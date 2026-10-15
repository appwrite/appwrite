//! Finds, counts and sums of the [`Redis`] adapter: the visible documents
//! of a collection are loaded, then filtered, ordered, paged and projected
//! here with PHP's comparisons.

use redis::aio::ConnectionLike;
use utopia_query::{CursorDirection, Method, OrderDirection};

use super::operators::{ge, gt, le, lt, numeric, number_value};
use super::Redis;
use crate::adapter::{Adapter, Find, filter};
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value, from_query};

/// The methods that do not filter (`filterDocumentsByQueries()` skips them).
const NOT_FILTERS: [Method; 8] = [
    Method::Select,
    Method::OrderAsc,
    Method::OrderDesc,
    Method::OrderRandom,
    Method::Limit,
    Method::Offset,
    Method::CursorAfter,
    Method::CursorBefore,
];

/// `extractSelections()`: the string values of the `select` queries.
pub(super) fn selections(queries: &[Query]) -> Vec<String> {
    queries
        .iter()
        .filter(|q| q.method() == Method::Select)
        .flat_map(|q| {
            q.values()
                .values()
                .filter_map(|v| match v {
                    utopia_query::Value::Str(s) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
                    _ => None,
                })
                .collect::<Vec<_>>()
        })
        .collect()
}

/// `projectDocument()`: the internal (`$…`, `_…`) and selected attributes;
/// everything for `*`.
pub(super) fn project(document: Document, selections: &[String]) -> Result<Document> {
    if selections.iter().any(|s| s == "*") {
        return Ok(document);
    }
    let mut projected = Array::new();
    for (field, value) in document.array_copy() {
        let keep = match field.as_ref() {
            KeyRef::Str(s) => s.starts_with(b"$") || s.starts_with(b"_") || selections.iter().any(|x| x.as_bytes() == s),
            KeyRef::Int(i) => selections.iter().any(|x| *x == i.to_string()),
        };
        if keep {
            projected.set(field, value);
        }
    }
    Document::new(projected)
}

/// `$value` as an array when it is a document (`getArrayCopy()`).
fn plain(value: Value) -> Value {
    match value {
        Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy()),
        other => other,
    }
}

/// `json_decode($value, true)` of a string starting with `{` or `[`, when it
/// decodes to an array.
fn decoded_json(value: &Value) -> Option<Array> {
    let Value::Str(s) = value else {
        return None;
    };
    let bytes = s.as_bytes();
    if bytes.is_empty() || !(bytes[0] == b'{' || bytes[0] == b'[') {
        return None;
    }
    match super::json_decode_lenient(bytes) {
        Some(Value::Array(a)) => Some(a),
        _ => None,
    }
}

/// `traverseNestedPath()`: a dotted path through arrays (and documents);
/// `null` on a miss.
fn traverse(value: Value, path: &str) -> Value {
    let mut value = value;
    for part in path.split('.') {
        value = plain(value);
        match &value {
            Value::Array(a) => match a.get(&ArrayKey::normalize(Str::from(part))) {
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
    if a.strict_eq(b) {
        return true;
    }
    match (numeric(a), numeric(b)) {
        (Some(x), Some(y)) => number_value(x).strict_eq(&number_value(y)),
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

/// `contains` / `containsAny`: an element equal to a needle, or for another
/// string a string needle it contains, case-insensitively.
fn contains(value: &Value, needles: &[Value]) -> bool {
    match coerce_array(value) {
        Some(haystack) => needles.iter().any(|needle| haystack.values().any(|item| values_equal(item, needle))),
        None => match value {
            Value::Str(s) => needles.iter().any(|needle| match needle {
                Value::Str(n) => matches!(php_std::string::stripos(s.as_bytes(), n.as_bytes(), 0), Ok(Some(_))),
                _ => false,
            }),
            _ => false,
        },
    }
}

/// A `startsWith`-like test: the value and the first query value are
/// strings and `test` holds.
fn string_test(value: &Value, values: &[Value], test: impl Fn(&[u8], &[u8]) -> bool) -> bool {
    match (value, values.first()) {
        (Value::Str(v), Some(Value::Str(n))) => test(v.as_bytes(), n.as_bytes()),
        _ => false,
    }
}

/// `decodeObjectishValue()`.
fn decode_objectish(value: &Value) -> Value {
    match value {
        Value::Null => Value::Null,
        Value::Array(_) => value.clone(),
        Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy()),
        Value::Str(_) => decoded_json(value).map(Value::Array).unwrap_or_else(|| value.clone()),
        other => other.clone(),
    }
}

/// `jsonContainment()`: PostgreSQL's `@>`, a recursive subset where list
/// elements match any element.
fn json_containment(haystack: &Value, candidate: &Value) -> bool {
    if let Value::Array(h) = haystack
        && h.is_list()
    {
        if let Value::Array(c) = candidate
            && c.is_list()
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
    values_equal(haystack, candidate)
}

/// `wrapScalarObjectCandidate()`: `['skills' => 'ts']` as
/// `['skills' => ['ts']]`.
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
    wrapped.set(key.to_owned(), crate::value::list([value.clone()]));
    Value::Array(wrapped)
}

/// `matchesDocumentObject()`: object-attribute queries as JSON containment.
fn matches_object(value: &Value, method: Method, values: &[Value]) -> Result<bool> {
    let haystack = decode_objectish(value);
    let missing = matches!(haystack, Value::Null);
    Ok(match method {
        Method::Equal => !missing && values.iter().any(|c| json_containment(&haystack, c)),
        Method::NotEqual => !missing && !values.iter().any(|c| json_containment(&haystack, c)),
        Method::Contains | Method::ContainsAny => {
            !missing && values.iter().any(|c| json_containment(&haystack, &wrap_scalar_candidate(c)))
        }
        Method::ContainsAll => !missing && values.iter().all(|c| json_containment(&haystack, &wrap_scalar_candidate(c))),
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

/// `matchesFulltextRedis()`: a quoted phrase is a case-insensitive
/// substring; otherwise any needle token (or `prefix*`) among the
/// haystack's tokens.
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
        return php_std::string::str_contains(&lower, &phrase);
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

/// `tokenizeForSearch()`: the lower-cased runs of letters, numbers and `*`.
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

/// `array_slice($documents, $offset, $length)` of a list.
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

/// `shuffle()`.
fn shuffle(documents: &mut [Document]) {
    for i in (1..documents.len()).rev() {
        let mut bytes = [0u8; 8];
        let _ = getrandom::fill(&mut bytes);
        let j = (u64::from_le_bytes(bytes) % (i as u64 + 1)) as usize;
        documents.swap(i, j);
    }
}

/// `usort()`: the engine's sort with the original order breaking ties, as
/// PHP 8's stable sort does.
fn usort(documents: Vec<Document>, mut cmp: impl FnMut(&Document, &Document) -> i32) -> Vec<Document> {
    let mut indexed: Vec<(usize, Document)> = documents.into_iter().enumerate().collect();
    php_std::sort::zend_sort(&mut indexed, &mut |(ia, a), (ib, b)| match cmp(a, b) {
        0 => (ia > ib) as i32 - (ia < ib) as i32,
        r => r,
    });
    indexed.into_iter().map(|(_, d)| d).collect()
}

/// The query values as document values.
fn query_values(query: &utopia_query::Query) -> Vec<Value> {
    query.values().values().map(from_query).collect()
}

/// `$values[$i]`, `null` when missing.
fn nth(values: &[Value], i: usize) -> Value {
    values.get(i).cloned().unwrap_or(Value::Null)
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static> Redis<C> {
    /// Fails ([`crate::Kind::NotFound`]) when the collection has no meta hash.
    async fn require_collection(&self, collection: &str) -> Result<()> {
        if !self.exists_key(&self.meta_key(collection)).await? {
            return Err(Error::not_found("Collection not found"));
        }
        Ok(())
    }

    /// `find()`.
    pub(super) async fn find_documents(&self, collection: &Collection, queries: &[Query], options: Find) -> Result<Vec<Document>> {
        let collection = filter(collection.id());
        self.require_collection(&collection).await?;
        self.tx(async {
            let documents = self.load(&collection, options.for_permission).await?;
            let documents = self.filter_by_queries(documents, queries)?;
            let documents =
                self.order(documents, &options.order_attributes, &options.order_types, options.cursor_direction);
            let mut documents = self.after_cursor(
                documents,
                &options.order_attributes,
                &options.order_types,
                &options.cursor,
                options.cursor_direction,
            );
            if let Some(offset) = options.offset {
                documents = slice(documents, offset, None);
            }
            if let Some(limit) = options.limit {
                documents = slice(documents, 0, Some(limit));
            }
            let selections = selections(queries);
            if !selections.is_empty() {
                documents = documents.into_iter().map(|d| project(d, &selections)).collect::<Result<Vec<_>>>()?;
            }
            if options.cursor_direction == CursorDirection::Before {
                documents.reverse();
            }
            Ok(documents)
        })
        .await
    }

    /// `sum()`: of the attribute's numeric values among the first `max`
    /// matching documents; a float when any value is one.
    pub(super) async fn sum_documents(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> Result<Value> {
        let collection = filter(collection.id());
        self.require_collection(&collection).await?;
        self.tx(async {
            let documents = self.load(&collection, PermissionType::Read).await?;
            let mut documents = self.filter_by_queries(documents, queries)?;
            if let Some(max) = max {
                documents = slice(documents, 0, Some(max));
            }
            let mut sum = php_std::value::Number::Int(0);
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
                    sum = php_std::format::add(sum, n);
                }
            }
            Ok(if float {
                Value::Float(sum.as_f64())
            } else {
                match sum {
                    php_std::value::Number::Float(f) => Value::Int(php_std::format::dval_to_lval(f)),
                    php_std::value::Number::Int(i) => Value::Int(i),
                }
            })
        })
        .await
    }

    /// `count()`: the id set's size when nothing filters (no queries, no
    /// authorization, no shared tables), else the matching documents.
    pub(super) async fn count_documents(&self, collection: &Collection, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let collection = filter(collection.id());
        self.require_collection(&collection).await?;
        if queries.is_empty() && !self.authorization().status() && !self.shared_tables() {
            let cardinality: i64 = self.run(redis::cmd("SCARD").arg(self.idx_key(&collection, None))).await?;
            return Ok(max.map_or(cardinality, |max| max.min(cardinality)));
        }
        self.tx(async {
            let documents = self.load(&collection, PermissionType::Read).await?;
            let mut documents = self.filter_by_queries(documents, queries)?;
            if let Some(max) = max {
                documents = slice(documents, 0, Some(max));
            }
            Ok(documents.len() as i64)
        })
        .await
    }

    /// `filterDocumentsByQueries()`: the documents every filter matches.
    fn filter_by_queries(&self, documents: Vec<Document>, queries: &[Query]) -> Result<Vec<Document>> {
        if documents.is_empty() {
            return Ok(Vec::new());
        }
        let effective: Vec<&Query> = queries.iter().filter(|q| !NOT_FILTERS.contains(&q.method())).collect();
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
    fn matches(&self, document: &Document, query: &utopia_query::Query) -> Result<bool> {
        let method = query.method();
        if method == Method::And {
            for sub in query.values().values() {
                match sub {
                    utopia_query::Value::Ext(utopia_query::Object::Query(q)) if self.matches(document, q)? => {}
                    _ => return Ok(false),
                }
            }
            return Ok(true);
        }
        if method == Method::Or {
            for sub in query.values().values() {
                if let utopia_query::Value::Ext(utopia_query::Object::Query(q)) = sub
                    && self.matches(document, q)?
                {
                    return Ok(true);
                }
            }
            return Ok(false);
        }
        let attribute = query.attribute();
        let value = self.resolve(document, attribute);
        let values = query_values(query);
        if query.attribute_type() == "object" && !attribute.contains('.') {
            return matches_object(&value, method, &values);
        }
        self.matches_value(&value, method, &values)
    }

    /// The scalar and array methods of `matchesDocument()`.
    fn matches_value(&self, value: &Value, method: Method, values: &[Value]) -> Result<bool> {
        let null = matches!(value, Value::Null);
        let first = nth(values, 0);
        Ok(match method {
            Method::Equal => {
                !null && values.iter().filter(|c| !matches!(c, Value::Null)).any(|c| values_equal(value, c))
            }
            Method::NotEqual => {
                if null {
                    return Ok(false);
                }
                for candidate in values {
                    if matches!(candidate, Value::Null) || values_equal(value, candidate) {
                        return Ok(false);
                    }
                }
                true
            }
            Method::LessThan => !null && lt(value, &first),
            Method::LessThanEqual => !null && le(value, &first),
            Method::GreaterThan => !null && gt(value, &first),
            Method::GreaterThanEqual => !null && ge(value, &first),
            Method::IsNull => null,
            Method::IsNotNull => !null,
            Method::Between => !null && ge(value, &first) && le(value, &nth(values, 1)),
            Method::NotBetween => !null && (lt(value, &first) || gt(value, &nth(values, 1))),
            Method::StartsWith => string_test(value, values, |v, n| v.starts_with(n)),
            Method::NotStartsWith => !null && !string_test(value, values, |v, n| v.starts_with(n)),
            Method::EndsWith => string_test(value, values, |v, n| v.ends_with(n)),
            Method::NotEndsWith => !null && !string_test(value, values, |v, n| v.ends_with(n)),
            Method::Contains | Method::ContainsAny => contains(value, values),
            Method::NotContains => !null && !contains(value, values),
            Method::ContainsAll => match coerce_array(value) {
                Some(haystack) => values.iter().all(|needle| haystack.values().any(|item| values_equal(item, needle))),
                None => false,
            },
            Method::Search => {
                let Value::Str(haystack) = value else {
                    return Ok(false);
                };
                let needle = super::string_or_empty(&first);
                !needle.is_empty() && fulltext(haystack.as_bytes(), &needle)
            }
            Method::NotSearch => {
                if null {
                    return Ok(false);
                }
                let Value::Str(haystack) = value else {
                    return Ok(true);
                };
                let needle = super::string_or_empty(&first);
                needle.is_empty() || !fulltext(haystack.as_bytes(), &needle)
            }
            Method::Regex => {
                let Value::Str(subject) = value else {
                    return Ok(false);
                };
                let pattern = super::string_or_empty(&first);
                let mut delimited = b"#".to_vec();
                delimited.extend(php_std::string::str_replace(b"#", b"\\#", &pattern).0.iter());
                delimited.extend_from_slice(b"#u");
                matches!(
                    php_std::pcre::preg_match_bare(&delimited, subject.as_bytes()).map(|r| r.value),
                    Ok(php_std::pcre::Value::Int(1))
                )
            }
            other => {
                return Err(Error::query(format!("Query method not supported by Redis adapter: {}", other.as_str())));
            }
        })
    }

    /// `resolveDocumentAttribute()`: an attribute by its key, its filtered
    /// key, or a dotted path into an array (or JSON) value; `null` when
    /// missing.
    pub(super) fn resolve(&self, document: &Document, attribute: &str) -> Value {
        if document.has(attribute) {
            return document.get(attribute).cloned().unwrap_or(Value::Null);
        }
        let filtered = filter(attribute);
        if filtered != attribute && document.has(&filtered) {
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

    /// `orderDocuments()`: at random, by `$sequence` without order
    /// attributes, or by each attribute in turn (`null` first ascending).
    fn order(
        &self,
        mut documents: Vec<Document>,
        attributes: &[String],
        types: &[OrderDirection],
        cursor_direction: CursorDirection,
    ) -> Vec<Document> {
        if types.contains(&OrderDirection::Random) {
            shuffle(&mut documents);
            return documents;
        }
        let reverse = cursor_direction == CursorDirection::Before;
        if attributes.is_empty() {
            let sequence = |d: &Document| {
                let value = d.get(document::SEQUENCE).cloned().unwrap_or(Value::Int(0));
                numeric(&value).map_or(Value::Int(0), number_value)
            };
            return usort(documents, |a, b| {
                let (av, bv) = (sequence(a), sequence(b));
                if av.strict_eq(&bv) {
                    return 0;
                }
                let cmp = if lt(&av, &bv) { -1 } else { 1 };
                if reverse { -cmp } else { cmp }
            });
        }
        let directions: Vec<i32> = attributes
            .iter()
            .enumerate()
            .map(|(i, _)| {
                let mut direction = types.get(i).copied().unwrap_or(OrderDirection::Asc);
                if reverse {
                    direction =
                        if direction == OrderDirection::Asc { OrderDirection::Desc } else { OrderDirection::Asc };
                }
                if direction == OrderDirection::Asc { 1 } else { -1 }
            })
            .collect();
        usort(documents, |a, b| {
            for (i, attribute) in attributes.iter().enumerate() {
                let (av, bv) = (self.resolve(a, attribute), self.resolve(b, attribute));
                if av.strict_eq(&bv) {
                    continue;
                }
                let cmp = if matches!(av, Value::Null) {
                    -1
                } else if matches!(bv, Value::Null) || !lt(&av, &bv) {
                    1
                } else {
                    -1
                };
                return cmp * directions[i];
            }
            0
        })
    }

    /// `cursorDocuments()`: the documents past the cursor on the active
    /// order (`$sequence` ascending without one).
    fn after_cursor(
        &self,
        documents: Vec<Document>,
        attributes: &[String],
        types: &[OrderDirection],
        cursor: &Array,
        cursor_direction: CursorDirection,
    ) -> Vec<Document> {
        if cursor.is_empty() {
            return documents;
        }
        let (attributes, types): (Vec<String>, Vec<OrderDirection>) = if attributes.is_empty() {
            (vec![document::SEQUENCE.to_owned()], vec![OrderDirection::Asc])
        } else {
            (attributes.to_vec(), types.to_vec())
        };
        let reverse = cursor_direction == CursorDirection::Before;
        let boundaries: Vec<(String, bool, Value)> = attributes
            .iter()
            .enumerate()
            .map(|(i, attribute)| {
                let mut ascending = types.get(i).copied().unwrap_or(OrderDirection::Asc) == OrderDirection::Asc;
                if reverse {
                    ascending = !ascending;
                }
                let reference = cursor
                    .get(&ArrayKey::normalize(Str::from(attribute.as_str())))
                    .cloned()
                    .unwrap_or(Value::Null);
                (attribute.clone(), ascending, reference)
            })
            .collect();
        let mut out = Vec::new();
        for document in documents {
            for (attribute, ascending, reference) in &boundaries {
                let current = self.resolve(&document, attribute);
                if current.strict_eq(reference) {
                    continue;
                }
                let keep = if matches!(current, Value::Null) {
                    !ascending
                } else if matches!(reference, Value::Null) {
                    *ascending
                } else if *ascending {
                    gt(&current, reference)
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

