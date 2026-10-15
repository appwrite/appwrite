//! Finds, counts and sums of the [`Redis`] adapter: the visible documents
//! of a collection are loaded, then filtered, ordered, paged and projected
//! here with PHP's comparisons.

use std::cmp::Ordering;

use super::documents::relationship_keys;
use super::{Redis, raw_error, tx_error};
use crate::adapter::{Adapter, FindOptions};
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::query::{Method, Query};
use crate::value::{Array, Ext, KeyRef, Value, to_text};

/// `(int) $value`.
pub(crate) fn int_of(value: &Value) -> i64 {
    match value {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) => php_std::format::str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

/// `is_numeric($value) ? $value + 0 : null`.
pub(crate) fn numeric(value: &Value) -> Option<Value> {
    match value {
        Value::Int(_) | Value::Float(_) => Some(value.clone()),
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).map(|(n, _)| match n {
            php_std::value::Number::Int(i) => Value::Int(i),
            php_std::value::Number::Float(f) => Value::Float(f),
        }),
        _ => None,
    }
}

/// `$a < $b`.
fn lt(a: &Value, b: &Value) -> bool {
    a.compare(b) < 0
}

/// `$a <= $b`.
fn le(a: &Value, b: &Value) -> bool {
    a.compare(b) <= 0
}

/// `$a === $b`, objects included (the same document compares identical).
fn identical(a: &Value, b: &Value) -> bool {
    match (a, b) {
        (Value::Ext(Ext::Document(x)), Value::Ext(Ext::Document(y))) => std::ptr::eq(&**x, &**y),
        _ => a.strict_eq(b),
    }
}

/// The value a query compares with (`$values[$i]`, `null` when missing).
fn nth(values: &[Value], i: usize) -> Value {
    values.get(i).cloned().unwrap_or(Value::Null)
}

/// `$value` as an array (`getArrayCopy()` of a document).
fn plain(value: Value) -> Value {
    match value {
        Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy(&[], &[])),
        other => other,
    }
}

/// `json_decode($value, true)` of a string starting with `{` or `[`, when
/// it decodes to an array.
fn decoded_json(value: &Value) -> Option<Array> {
    let Value::Str(s) = value else {
        return None;
    };
    let bytes = s.as_bytes();
    if bytes.is_empty() || !(bytes[0] == b'{' || bytes[0] == b'[') {
        return None;
    }
    match php_std::json::decode(bytes, Some(true), 512, php_std::json::Flags::NONE).map(crate::value::from_plain) {
        Ok(Value::Array(a)) => Some(a),
        _ => None,
    }
}

/// The text of a key.
fn key_text(key: KeyRef<'_>) -> String {
    match key {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// `array_is_list()`.
fn is_list(a: &Array) -> bool {
    a.iter().enumerate().all(|(i, (k, _))| matches!(k, KeyRef::Int(n) if n == i as i64))
}

impl Redis {
    /// The documents of a collection the authorization may read (or act on
    /// with `for_permission`) and the tenant may see, missing relationship
    /// attributes set to `null`, in the order of the id set
    /// (`loadCollectionDocuments()`).
    async fn load(&self, collection: &str, for_permission: &str) -> Result<Vec<Document>> {
        let idx = self.idx_key(collection, None);
        let mut ids: Vec<String> = self.run(redis::cmd("SMEMBERS").arg(&idx)).await.map_err(tx_error)?;
        if ids.is_empty() {
            return Ok(Vec::new());
        }
        if self.authorization().status() {
            ids = self.permitted(collection, ids, for_permission).await?;
            if ids.is_empty() {
                return Ok(Vec::new());
            }
        }
        let keys: Vec<String> = ids.iter().map(|id| self.doc_key(collection, id, None)).collect();
        let payloads: Vec<Option<Vec<u8>>> = self.run(redis::cmd("MGET").arg(&keys)).await.map_err(tx_error)?;
        let shared = self.shared_tables();
        let tenant = if shared { self.tenant() } else { Value::Null };
        let allow_null = shared && collection == Database::METADATA;
        let keys = if collection != Database::METADATA {
            let meta = self.meta_key(&self.filter(collection)?);
            relationship_keys(&self.read_attributes(&meta).await?)
        } else {
            Vec::new()
        };
        let mut documents = Vec::new();
        for payload in payloads.into_iter().flatten().filter(|p| !p.is_empty()) {
            let document = Self::decode(&payload)?;
            if shared {
                let row = document.raw("$tenant").cloned().unwrap_or(Value::Null);
                if !(row.strict_eq(&tenant) || (allow_null && matches!(row, Value::Null))) {
                    continue;
                }
            }
            documents.push(if keys.is_empty() { document } else { Self::surface(&keys, document) });
        }
        Ok(documents)
    }

    /// Fails like PHP when the collection has no meta hash.
    async fn require_collection(&self, collection: &str) -> Result<()> {
        if !self.exists_key(&self.meta_key(collection)).await? {
            return Err(Error::not_found("Collection not found"));
        }
        Ok(())
    }

    /// `find()`.
    pub(super) async fn find_documents(
        &self,
        collection: &Document,
        queries: &[Query],
        options: FindOptions<'_>,
    ) -> Result<Vec<Document>> {
        let collection = self.filter(collection.id())?;
        self.require_collection(&collection).await?;
        let documents = self.load(&collection, options.for_permission).await?;
        let documents = self.filter_by_queries(documents, queries)?;
        let documents = self.order(documents, options.order_attributes, options.order_types, options.cursor_direction);
        let mut documents = self.after_cursor(
            documents,
            options.order_attributes,
            options.order_types,
            options.cursor,
            options.cursor_direction,
        );
        if let Some(offset) = options.offset {
            documents = slice(documents, offset, None);
        }
        if let Some(limit) = options.limit {
            documents = slice(documents, 0, Some(limit));
        }
        let selections: Vec<String> = queries
            .iter()
            .filter(|q| *q.method() == Method::Select)
            .flat_map(|q| q.values().iter().filter(|v| matches!(v, Value::Str(_))).map(to_text).collect::<Vec<_>>())
            .collect();
        if !selections.is_empty() {
            documents = documents.into_iter().map(|d| project(d, &selections)).collect::<Result<Vec<_>>>()?;
        }
        if options.cursor_direction == Database::CURSOR_BEFORE {
            documents.reverse();
        }
        Ok(documents)
    }

    /// `sum()`: of the attribute's numeric values among the first `max`
    /// matching documents; a float when any value is one.
    pub(super) async fn sum_documents(
        &self,
        collection: &Document,
        attribute: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> Result<Value> {
        let collection = self.filter(collection.id())?;
        self.require_collection(&collection).await?;
        let documents = self.load(&collection, Database::PERMISSION_READ).await?;
        let mut documents = self.filter_by_queries(documents, queries)?;
        if let Some(max) = max {
            documents = slice(documents, 0, Some(max));
        }
        let mut sum = Value::Int(0);
        let mut float = false;
        for document in &documents {
            let value = self.resolve(document, attribute);
            if matches!(value, Value::Null) {
                continue;
            }
            if matches!(value, Value::Float(_)) {
                float = true;
            }
            if let Some(n) = numeric(&value) {
                sum = crate::validator::operator::arith(crate::validator::operator::Arith::Add, &sum, &n)?;
            }
        }
        Ok(if float {
            Value::Float(sum.to_double())
        } else {
            match sum {
                Value::Float(f) => Value::Int(php_std::format::dval_to_lval(f)),
                other => other,
            }
        })
    }

    /// `count()`: the id set's size when nothing filters (no queries, no
    /// authorization, no shared tables), else the matching documents.
    pub(super) async fn count_documents(
        &self,
        collection: &Document,
        queries: &[Query],
        max: Option<i64>,
    ) -> Result<i64> {
        let collection = self.filter(collection.id())?;
        self.require_collection(&collection).await?;
        if queries.is_empty() && !self.authorization().status() && !self.shared_tables() {
            let idx = self.idx_key(&collection, None);
            let cardinality: i64 = self.run(redis::cmd("SCARD").arg(&idx)).await.map_err(raw_error)?;
            return Ok(match max {
                None => cardinality,
                Some(max) => max.min(cardinality),
            });
        }
        let documents = self.load(&collection, Database::PERMISSION_READ).await?;
        let mut documents = self.filter_by_queries(documents, queries)?;
        if let Some(max) = max {
            documents = slice(documents, 0, Some(max));
        }
        Ok(documents.len() as i64)
    }

    /// The documents every filtering query matches (`filterDocumentsByQueries()`).
    fn filter_by_queries(&self, documents: Vec<Document>, queries: &[Query]) -> Result<Vec<Document>> {
        if documents.is_empty() {
            return Ok(Vec::new());
        }
        let effective: Vec<&Query> = queries
            .iter()
            .filter(|q| {
                !matches!(
                    q.method(),
                    Method::Select
                        | Method::OrderAsc
                        | Method::OrderDesc
                        | Method::OrderRandom
                        | Method::Limit
                        | Method::Offset
                        | Method::CursorAfter
                        | Method::CursorBefore
                )
            })
            .collect();
        if effective.is_empty() {
            return Ok(documents);
        }
        let mut out = Vec::new();
        'documents: for document in documents {
            for query in &effective {
                if !self.matches(&document, query)? {
                    continue 'documents;
                }
            }
            out.push(document);
        }
        Ok(out)
    }

    /// Whether a document matches a query (`matchesDocument()`).
    fn matches(&self, document: &Document, query: &Query) -> Result<bool> {
        let method = query.method();
        if *method == Method::And {
            for sub in query.values() {
                match sub {
                    Value::Ext(Ext::Query(q)) => {
                        if !self.matches(document, q)? {
                            return Ok(false);
                        }
                    }
                    _ => return Ok(false),
                }
            }
            return Ok(true);
        }
        if *method == Method::Or {
            for sub in query.values() {
                if let Value::Ext(Ext::Query(q)) = sub
                    && self.matches(document, q)?
                {
                    return Ok(true);
                }
            }
            return Ok(false);
        }
        let attribute = query.attribute();
        let value = self.resolve(document, attribute);
        let values = query.values();
        if query.is_object_attribute() && !attribute.contains('.') {
            return matches_object(&value, query);
        }
        let null = matches!(value, Value::Null);
        Ok(match method {
            Method::Equal => values.iter().any(|c| values_equal(&value, c)),
            Method::NotEqual => !null && !values.iter().any(|c| values_equal(&value, c)),
            Method::LessThan => !null && lt(&value, &nth(values, 0)),
            Method::LessThanEqual => !null && le(&value, &nth(values, 0)),
            Method::GreaterThan => !null && lt(&nth(values, 0), &value),
            Method::GreaterThanEqual => !null && le(&nth(values, 0), &value),
            Method::IsNull => null,
            Method::IsNotNull => !null,
            Method::Between => !null && le(&nth(values, 0), &value) && le(&value, &nth(values, 1)),
            Method::NotBetween => !null && (lt(&value, &nth(values, 0)) || lt(&nth(values, 1), &value)),
            Method::StartsWith => string_test(&value, values, |v, n| v.starts_with(n)),
            Method::NotStartsWith => !null && !string_test(&value, values, |v, n| v.starts_with(n)),
            Method::EndsWith => string_test(&value, values, |v, n| v.ends_with(n)),
            Method::NotEndsWith => !null && !string_test(&value, values, |v, n| v.ends_with(n)),
            Method::Contains | Method::ContainsAny => contains(&value, values),
            Method::NotContains => !null && !contains(&value, values),
            Method::ContainsAll => match coerce_array(&value) {
                Some(haystack) => values.iter().all(|needle| haystack.values().any(|item| values_equal(item, needle))),
                None => false,
            },
            Method::Search => {
                let Value::Str(haystack) = &value else {
                    return Ok(false);
                };
                let needle = to_text(&nth(values, 0));
                !needle.is_empty() && fulltext(haystack.as_bytes(), needle.as_bytes())
            }
            Method::NotSearch => {
                if null {
                    return Ok(false);
                }
                let Value::Str(haystack) = &value else {
                    return Ok(true);
                };
                let needle = to_text(&nth(values, 0));
                needle.is_empty() || !fulltext(haystack.as_bytes(), needle.as_bytes())
            }
            Method::Regex => {
                let Value::Str(subject) = &value else {
                    return Ok(false);
                };
                let pattern = to_text(&nth(values, 0));
                let delimited = format!("#{}#u", pattern.replace('#', "\\#"));
                matches!(
                    php_std::pcre::preg_match_bare(delimited.as_bytes(), subject.as_bytes()).map(|r| r.value),
                    Ok(php_std::pcre::Value::Int(1))
                )
            }
            other => {
                return Err(Error::query(format!("Query method not supported by Redis adapter: {}", other.as_str())));
            }
        })
    }

    /// An attribute of a document: by its key, its filtered key, or a dotted
    /// path into an array (or JSON) value; `null` when missing
    /// (`resolveDocumentAttribute()`).
    pub(super) fn resolve(&self, document: &Document, attribute: &str) -> Value {
        if document.has(attribute) {
            return document.get(attribute).cloned().unwrap_or(Value::Null);
        }
        if let Ok(filtered) = self.filter(attribute)
            && filtered != attribute
            && document.has(&filtered)
        {
            return document.get(&filtered).cloned().unwrap_or(Value::Null);
        }
        let Some((head, rest)) = attribute.split_once('.') else {
            return Value::Null;
        };
        let mut value = document.get(head).cloned().unwrap_or(Value::Null);
        if let Some(decoded) = decoded_json(&value) {
            value = Value::Array(decoded);
        }
        traverse(plain(value), rest)
    }

    /// Orders documents: at random, by `$sequence` without order attributes,
    /// or by each attribute in turn (`orderDocuments()`).
    fn order(
        &self,
        mut documents: Vec<Document>,
        attributes: &[String],
        types: &[String],
        cursor_direction: &str,
    ) -> Vec<Document> {
        if types.iter().any(|t| t == Database::ORDER_RANDOM) {
            shuffle(&mut documents);
            return documents;
        }
        let reverse = cursor_direction == Database::CURSOR_BEFORE;
        if attributes.is_empty() {
            let sequence =
                |d: &Document| numeric(d.get("$sequence").unwrap_or(&Value::Int(0))).unwrap_or(Value::Int(0));
            documents.sort_by(|a, b| {
                let (av, bv) = (sequence(a), sequence(b));
                if av.strict_eq(&bv) {
                    return Ordering::Equal;
                }
                let cmp = if lt(&av, &bv) { Ordering::Less } else { Ordering::Greater };
                if reverse { cmp.reverse() } else { cmp }
            });
            return documents;
        }
        let directions: Vec<i32> = attributes
            .iter()
            .enumerate()
            .map(|(i, _)| {
                let mut asc = types.get(i).map(String::as_str).unwrap_or(Database::ORDER_ASC) == Database::ORDER_ASC;
                if reverse {
                    asc = !asc;
                }
                if asc { 1 } else { -1 }
            })
            .collect();
        documents.sort_by(|a, b| {
            for (i, attribute) in attributes.iter().enumerate() {
                let (av, bv) = (self.resolve(a, attribute), self.resolve(b, attribute));
                if identical(&av, &bv) {
                    continue;
                }
                let cmp = if matches!(av, Value::Null) {
                    -1
                } else if matches!(bv, Value::Null) {
                    1
                } else if lt(&av, &bv) {
                    -1
                } else {
                    1
                };
                return (cmp * directions[i]).cmp(&0);
            }
            Ordering::Equal
        });
        documents
    }

    /// Drops the documents up to the cursor on the active order
    /// (`cursorDocuments()`).
    fn after_cursor(
        &self,
        documents: Vec<Document>,
        attributes: &[String],
        types: &[String],
        cursor: &Array,
        cursor_direction: &str,
    ) -> Vec<Document> {
        if cursor.is_empty() {
            return documents;
        }
        let (attributes, types): (Vec<String>, Vec<String>) = if attributes.is_empty() {
            (vec!["$sequence".to_owned()], vec![Database::ORDER_ASC.to_owned()])
        } else {
            (attributes.to_vec(), types.to_vec())
        };
        let reverse = cursor_direction == Database::CURSOR_BEFORE;
        let resolved: Vec<(String, bool, Value)> = attributes
            .iter()
            .enumerate()
            .map(|(i, attribute)| {
                let mut asc = types.get(i).map(String::as_str).unwrap_or(Database::ORDER_ASC) == Database::ORDER_ASC;
                if reverse {
                    asc = !asc;
                }
                (attribute.clone(), asc, cursor.get_str(attribute).cloned().unwrap_or(Value::Null))
            })
            .collect();
        let mut out = Vec::new();
        for document in documents {
            for (attribute, asc, reference) in &resolved {
                let current = self.resolve(&document, attribute);
                if identical(&current, reference) {
                    continue;
                }
                let keep = if matches!(current, Value::Null) {
                    !asc
                } else if matches!(reference, Value::Null) {
                    *asc
                } else if *asc {
                    lt(reference, &current)
                } else {
                    lt(&current, reference)
                };
                if keep {
                    out.push(document);
                }
                break;
            }
        }
        out
    }
}

/// `array_slice($documents, $offset, $length)` on a list.
fn slice(documents: Vec<Document>, offset: i64, length: Option<i64>) -> Vec<Document> {
    let len = documents.len() as i64;
    let start = if offset < 0 { (len + offset).max(0) } else { offset.min(len) };
    let end = match length {
        None => len,
        Some(l) if l < 0 => (len + l).max(start),
        Some(l) => (start + l).min(len),
    };
    documents.into_iter().skip(start as usize).take((end - start).max(0) as usize).collect()
}

/// `shuffle()`: a random order.
fn shuffle(documents: &mut [Document]) {
    for i in (1..documents.len()).rev() {
        let mut bytes = [0u8; 8];
        let _ = getrandom::fill(&mut bytes);
        let j = (u64::from_le_bytes(bytes) % (i as u64 + 1)) as usize;
        documents.swap(i, j);
    }
}

/// A document reduced to the selected attributes (internal ones kept);
/// `*` keeps everything (`projectDocument()`).
fn project(document: Document, selections: &[String]) -> Result<Document> {
    if selections.iter().any(|s| s == "*") {
        return Ok(document);
    }
    let mut projected = Array::new();
    for (field, value) in document.array_copy(&[], &[]).iter() {
        let keep = match field {
            KeyRef::Str(s) => {
                s.starts_with(b"$") || s.starts_with(b"_") || selections.iter().any(|x| x.as_bytes() == s)
            }
            KeyRef::Int(_) => false,
        };
        if keep {
            projected.set(field.to_owned(), value.clone());
        }
    }
    Document::new(projected)
}

/// Walks a dotted path through arrays (and documents); `null` on a miss
/// (`traverseNestedPath()`).
fn traverse(value: Value, path: &str) -> Value {
    let mut value = value;
    for part in path.split('.') {
        value = plain(value);
        match &value {
            Value::Array(a) => match a.get_str(part) {
                Some(v) => value = v.clone(),
                None => return Value::Null,
            },
            _ => return Value::Null,
        }
    }
    value
}

/// `valuesEqual()`: identical, or numeric and identical as numbers.
fn values_equal(a: &Value, b: &Value) -> bool {
    if identical(a, b) {
        return true;
    }
    match (numeric(a), numeric(b)) {
        (Some(x), Some(y)) => x.strict_eq(&y),
        _ => false,
    }
}

/// `coerceArrayValue()`: an array, or a JSON array (or object) string.
fn coerce_array(value: &Value) -> Option<Array> {
    match value {
        Value::Array(a) => Some(a.clone()),
        Value::Str(_) => decoded_json(value),
        _ => None,
    }
}

/// `contains` / `containsAny`: an element of the array (or JSON array)
/// equal to a needle, or for another string a needle it contains,
/// case-insensitively.
fn contains(value: &Value, needles: &[Value]) -> bool {
    match coerce_array(value) {
        Some(haystack) => needles.iter().any(|needle| haystack.values().any(|item| values_equal(item, needle))),
        None => match value {
            Value::Str(s) => needles.iter().any(|needle| match needle {
                Value::Str(n) => {
                    matches!(php_std::string::stripos(s.as_bytes(), n.as_bytes(), 0), Ok(Some(_)))
                }
                _ => false,
            }),
            _ => false,
        },
    }
}

/// A string test of `startsWith`-like queries: both the value and the
/// first query value are strings and `test` holds.
fn string_test(value: &Value, values: &[Value], test: impl Fn(&[u8], &[u8]) -> bool) -> bool {
    match (value, values.first()) {
        (Value::Str(v), Some(Value::Str(n))) => test(v.as_bytes(), n.as_bytes()),
        _ => false,
    }
}

/// Object-attribute queries: JSON containment (`matchesDocumentObject()`).
fn matches_object(value: &Value, query: &Query) -> Result<bool> {
    let haystack = decode_objectish(value);
    let values = query.values();
    let missing = matches!(haystack, Value::Null);
    Ok(match query.method() {
        Method::Equal => !missing && values.iter().any(|c| json_containment(&haystack, c)),
        Method::NotEqual => !missing && !values.iter().any(|c| json_containment(&haystack, c)),
        Method::Contains | Method::ContainsAny => {
            !missing && values.iter().any(|c| json_containment(&haystack, &wrap_scalar_candidate(c)))
        }
        Method::ContainsAll => {
            !missing && values.iter().all(|c| json_containment(&haystack, &wrap_scalar_candidate(c)))
        }
        Method::NotContains => {
            !missing && !values.iter().any(|c| json_containment(&haystack, &wrap_scalar_candidate(c)))
        }
        Method::IsNull => matches!(value, Value::Null),
        Method::IsNotNull => !matches!(value, Value::Null),
        other => {
            return Err(Error::query(format!("Query method {} not supported for object attributes", other.as_str())));
        }
    })
}

/// An object attribute's value for containment (`decodeObjectishValue()`).
fn decode_objectish(value: &Value) -> Value {
    match value {
        Value::Null => Value::Null,
        Value::Array(_) => value.clone(),
        Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy(&[], &[])),
        Value::Str(_) => decoded_json(value).map(Value::Array).unwrap_or_else(|| value.clone()),
        other => other.clone(),
    }
}

/// PostgreSQL's `@>`: recursive subset, list elements matched against any
/// element (`jsonContainment()`).
fn json_containment(haystack: &Value, candidate: &Value) -> bool {
    if let Value::Array(h) = haystack
        && is_list(h)
    {
        if let Value::Array(c) = candidate
            && is_list(c)
        {
            return c.values().all(|needle| h.values().any(|item| json_containment(item, needle)));
        }
        return h.values().any(|item| json_containment(item, candidate));
    }
    if let (Value::Array(h), Value::Array(c)) = (haystack, candidate) {
        return c.iter().all(|(key, value)| match h.get_ref(key) {
            Some(inner) => json_containment(inner, value),
            None => false,
        });
    }
    if identical(haystack, candidate) {
        return true;
    }
    match (numeric(haystack), numeric(candidate)) {
        (Some(x), Some(y)) => x.strict_eq(&y),
        _ => false,
    }
}

/// `['skills' => 'ts']` as `['skills' => ['ts']]`, so a contains probe hits
/// a list inside the haystack (`wrapScalarObjectCandidate()`).
fn wrap_scalar_candidate(candidate: &Value) -> Value {
    let Value::Array(a) = candidate else {
        return candidate.clone();
    };
    if a.len() != 1 {
        return candidate.clone();
    }
    let Some((key, value)) = a.iter().next() else {
        return candidate.clone();
    };
    if matches!(value, Value::Array(_)) {
        return candidate.clone();
    }
    let mut wrapped = Array::new();
    wrapped.set(key.to_owned(), Value::Array([value.clone()].into_iter().collect()));
    let _ = key_text;
    Value::Array(wrapped)
}

/// Natural-language search: a quoted phrase is a case-insensitive
/// substring, otherwise any needle token (or `prefix*`) among the
/// haystack's tokens (`matchesFulltextRedis()`).
fn fulltext(haystack: &[u8], needle: &[u8]) -> bool {
    let trimmed = php_std::string::trim(needle, b" \t\n\r\0\x0B");
    let quoted = php_std::pcre::preg_match(b"/^\"(.*)\"$/u", trimmed, 0, 0).ok().map(|m| m.value);
    if let Some(m) = quoted.filter(|m| matches!(m.result, php_std::pcre::Value::Int(1))) {
        let group = match m.matches.as_ref().and_then(|a| a.get(&php_std::pcre::Key::Int(1))) {
            Some(php_std::pcre::Value::Str(s)) => s.clone(),
            _ => Vec::new(),
        };
        let phrase = php_std::mb::mb_strtolower(&group);
        if phrase.is_empty() {
            return false;
        }
        let lower = php_std::mb::mb_strtolower(haystack);
        return lower.windows(phrase.len()).any(|w| w == phrase.as_slice());
    }
    let haystack_tokens = tokenize(haystack);
    let needle_tokens = tokenize(needle);
    if needle_tokens.is_empty() || haystack_tokens.is_empty() {
        return false;
    }
    for token in &needle_tokens {
        if let Some(prefix) = token.strip_suffix(b"*") {
            if prefix.is_empty() {
                continue;
            }
            if haystack_tokens.iter().any(|t| t.starts_with(prefix)) {
                return true;
            }
            continue;
        }
        if haystack_tokens.contains(token) {
            return true;
        }
    }
    false
}

/// The lower-cased letter, number and `*` runs of a text (`tokenizeForSearch()`).
fn tokenize(text: &[u8]) -> Vec<Vec<u8>> {
    let lower = php_std::mb::mb_strtolower(text);
    match php_std::pcre::preg_split(b"/[^\\p{L}\\p{N}*]+/u", &lower, -1, 0).value {
        php_std::pcre::Value::Array(parts) => parts
            .entries()
            .iter()
            .filter_map(|(_, v)| match v {
                php_std::pcre::Value::Str(s) if !s.is_empty() => Some(s.clone()),
                _ => None,
            })
            .collect(),
        _ => Vec::new(),
    }
}
