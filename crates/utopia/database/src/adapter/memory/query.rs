//! Finds, counts and sums: rows filtered by tenant, permission and query
//! (in PHP: comparisons, substring, full-text and PCRE matches, JSON
//! containment), ordered, paged by cursor, offset and limit.
//!
//! PHP: `Memory::find`, `count`, `sum`, `fusedFilter`, `matches`,
//! `matchesFulltext`, `tokenize`, `matchesRegex`, `looseEquals`,
//! `decodeArrayValue`, `matchesObject`, `decodeObjectValue`,
//! `jsonContains`, `wrapScalarObjectValue`, `buildPermissionAllowSet`,
//! `applyOrdering`, `applyCursor`, `extractSelections`.

use php_std::pcre;

use super::documents::decode_json_like;
use super::operators::{ge, gt, le, lt, numeric};
use super::store::{self, k, s};
use super::{Ctx, Store};
use crate::adapter::FindOptions;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::query::{Method, Query};
use crate::value::{Array, ArrayKey, Str, Value};

/// `looseEquals($a, $b)`: identical, or numbers equal after `+ 0` (an
/// integer never equals a float).
fn loose_equals(a: &Value, b: &Value) -> bool {
    if a.strict_eq(b) {
        return true;
    }
    match (numeric(a), numeric(b)) {
        (Some(x), Some(y)) => x.strict_eq(&y),
        _ => false,
    }
}

/// `decodeArrayValue($value)`.
fn decode_array_value(value: &Value) -> Option<Array> {
    match value {
        Value::Array(a) => Some(a.clone()),
        _ => decode_json_like(value),
    }
}

/// `stripos($haystack, $needle) !== false`.
fn contains_ci(haystack: &[u8], needle: &[u8]) -> bool {
    if needle.is_empty() {
        return true;
    }
    let haystack = haystack.to_ascii_lowercase();
    let needle = needle.to_ascii_lowercase();
    haystack.windows(needle.len()).any(|w| w == needle.as_slice())
}

/// `tokenize($text)`: lower-cased words (letters, digits, `*`).
fn tokenize(text: &[u8]) -> Vec<Vec<u8>> {
    let lower = php_std::mb::mb_strtolower(text);
    match pcre::preg_split(br"/[^\p{L}\p{N}*]+/u", &lower, -1, 0).value {
        pcre::Value::Array(parts) => parts
            .entries()
            .iter()
            .map(|(_, v)| v)
            .filter_map(|p| match p {
                pcre::Value::Str(s) if !s.is_empty() => Some(s.clone()),
                _ => None,
            })
            .collect(),
        _ => Vec::new(),
    }
}

/// `matchesFulltext($haystack, $needle)`: a quoted phrase as a substring,
/// otherwise any word (a `word*` as a prefix).
fn matches_fulltext(haystack: &[u8], needle: &[u8]) -> bool {
    let trimmed = php_std::string::trim(needle, b" \n\r\t\x0B\0");
    if let Ok(r) = pcre::preg_match(br#"/^"(.*)"$/u"#, trimmed, 0, 0)
        && matches!(r.value.result, pcre::Value::Int(1))
    {
        let phrase = match r.value.matches.as_ref().and_then(|m| m.get(&pcre::Key::Int(1))) {
            Some(pcre::Value::Str(s)) => php_std::mb::mb_strtolower(s),
            _ => Vec::new(),
        };
        if phrase.is_empty() {
            return false;
        }
        let haystack = php_std::mb::mb_strtolower(haystack);
        return haystack.windows(phrase.len()).any(|w| w == phrase.as_slice());
    }
    let words = tokenize(haystack);
    let terms = tokenize(needle);
    if terms.is_empty() || words.is_empty() {
        return false;
    }
    for term in terms {
        if let Some(prefix) = term.strip_suffix(b"*") {
            if prefix.is_empty() {
                continue;
            }
            if words.iter().any(|w| w.starts_with(prefix)) {
                return true;
            }
            continue;
        }
        if words.contains(&term) {
            return true;
        }
    }
    false
}

/// `matchesRegex($value, $pattern)`: `preg_match('#pattern#u', ...)`.
fn matches_regex(value: &[u8], pattern: &[u8]) -> bool {
    let mut delimited = b"#".to_vec();
    delimited.extend_from_slice(&php_std::string::str_replace(b"#", b"\\#", pattern).0);
    delimited.extend_from_slice(b"#u");
    matches!(pcre::preg_match_bare(&delimited, value), Ok(r) if matches!(r.value, pcre::Value::Int(1)))
}

/// `jsonContains($haystack, $candidate)`.
fn json_contains(haystack: &Value, candidate: &Value) -> bool {
    if let Value::Array(h) = haystack
        && h.is_list()
    {
        if let Value::Array(c) = candidate
            && c.is_list()
        {
            return c.values().all(|needle| h.values().any(|item| json_contains(item, needle)));
        }
        return h.values().any(|item| json_contains(item, candidate));
    }
    if let (Value::Array(h), Value::Array(c)) = (haystack, candidate) {
        for (key, value) in c.iter() {
            match h.get(&key.to_owned()) {
                Some(v) => {
                    if !json_contains(v, value) {
                        return false;
                    }
                }
                None => return false,
            }
        }
        return true;
    }
    loose_equals(haystack, candidate) && (haystack.strict_eq(candidate) || numeric(haystack).is_some())
}

/// `wrapScalarObjectValue($candidate)`.
fn wrap_scalar(candidate: &Value) -> Value {
    let Value::Array(c) = candidate else { return candidate.clone() };
    if c.len() != 1 {
        return candidate.clone();
    }
    let Some((key, value)) = c.iter().next() else { return candidate.clone() };
    if matches!(value, Value::Array(_)) {
        return candidate.clone();
    }
    let mut out = Array::new();
    out.set(key.to_owned(), Value::Array([value.clone()].into_iter().collect()));
    Value::Array(out)
}

/// `decodeObjectValue($value)`.
fn decode_object_value(value: &Value) -> Option<Value> {
    match value {
        Value::Null => None,
        Value::Array(_) => Some(value.clone()),
        _ => Some(decode_json_like(value).map(Value::Array).unwrap_or_else(|| value.clone())),
    }
}

/// `matchesObject($value, $query)`.
fn matches_object(value: &Value, query: &Query) -> Result<bool> {
    let haystack = decode_object_value(value);
    let values = query.values();
    Ok(match query.method() {
        Method::Equal => haystack.is_some_and(|h| values.iter().any(|c| json_contains(&h, c))),
        Method::NotEqual => haystack.is_some_and(|h| !values.iter().any(|c| json_contains(&h, c))),
        Method::Contains | Method::ContainsAny => {
            haystack.is_some_and(|h| values.iter().any(|c| json_contains(&h, &wrap_scalar(c))))
        }
        Method::ContainsAll => haystack.is_some_and(|h| values.iter().all(|c| json_contains(&h, &wrap_scalar(c)))),
        Method::NotContains => haystack.is_some_and(|h| !values.iter().any(|c| json_contains(&h, &wrap_scalar(c)))),
        Method::IsNull => matches!(value, Value::Null),
        Method::IsNotNull => !matches!(value, Value::Null),
        other => {
            return Err(Error::database(format!(
                "Query method {} not supported for object attributes",
                other.as_str()
            )));
        }
    })
}

/// `$queryValues[$i]` (`null` when missing).
fn at(values: &[Value], i: usize) -> Value {
    values.get(i).cloned().unwrap_or(Value::Null)
}

impl Store {
    /// `matches($row, $query)`.
    fn matches(&self, ctx: &Ctx, row: &Array, query: &Query) -> Result<bool> {
        let method = query.method();
        if *method == Method::And {
            for sub in query.values() {
                match crate::value::as_query(sub) {
                    Some(q) if self.matches(ctx, row, q)? => {}
                    _ => return Ok(false),
                }
            }
            return Ok(true);
        }
        if *method == Method::Or {
            for sub in query.values() {
                if let Some(q) = crate::value::as_query(sub)
                    && self.matches(ctx, row, q)?
                {
                    return Ok(true);
                }
            }
            return Ok(false);
        }
        let attribute = query.attribute();
        let object = query.is_object_attribute() && !attribute.contains('.');
        let value = self.resolve_attribute_value(ctx, row, attribute);
        let values = query.values();
        if object {
            return matches_object(&value, query);
        }
        let null = matches!(value, Value::Null);
        let string_pair = |a: &Value, b: &Value| match (a, b) {
            (Value::Str(a), Value::Str(b)) => Some((a.as_bytes().to_vec(), b.as_bytes().to_vec())),
            _ => None,
        };
        Ok(match method {
            Method::Equal => values.iter().any(|c| loose_equals(&value, c)),
            Method::NotEqual => !null && !values.iter().any(|c| loose_equals(&value, c)),
            Method::LessThan => !null && lt(&value, &at(values, 0)),
            Method::LessThanEqual => !null && le(&value, &at(values, 0)),
            Method::GreaterThan => !null && gt(&value, &at(values, 0)),
            Method::GreaterThanEqual => !null && ge(&value, &at(values, 0)),
            Method::IsNull => null,
            Method::IsNotNull => !null,
            Method::Between => !null && ge(&value, &at(values, 0)) && le(&value, &at(values, 1)),
            Method::NotBetween => !null && (lt(&value, &at(values, 0)) || gt(&value, &at(values, 1))),
            Method::StartsWith => string_pair(&value, &at(values, 0)).is_some_and(|(v, n)| v.starts_with(&n)),
            Method::NotStartsWith => {
                !null && !string_pair(&value, &at(values, 0)).is_some_and(|(v, n)| v.starts_with(&n))
            }
            Method::EndsWith => string_pair(&value, &at(values, 0)).is_some_and(|(v, n)| v.ends_with(&n)),
            Method::NotEndsWith => !null && !string_pair(&value, &at(values, 0)).is_some_and(|(v, n)| v.ends_with(&n)),
            Method::Contains | Method::ContainsAny => {
                let haystack = decode_array_value(&value);
                match (haystack, &value) {
                    (None, Value::Str(v)) => values.iter().any(|n| match n {
                        Value::Str(n) => contains_ci(v.as_bytes(), n.as_bytes()),
                        _ => false,
                    }),
                    (Some(h), _) => values.iter().any(|n| h.values().any(|item| loose_equals(item, n))),
                    (None, _) => false,
                }
            }
            Method::NotContains => {
                if null {
                    return Ok(false);
                }
                let contains = Query::new(Method::Contains, attribute, values.to_vec());
                !self.matches(ctx, row, &contains)?
            }
            Method::ContainsAll => match decode_array_value(&value) {
                Some(h) => values.iter().all(|n| h.values().any(|item| loose_equals(item, n))),
                None => false,
            },
            Method::Search => {
                let Value::Str(v) = &value else { return Ok(false) };
                let needle = at(values, 0).to_php_string().map_err(Error::from)?;
                !needle.is_empty() && matches_fulltext(v.as_bytes(), needle.as_bytes())
            }
            Method::NotSearch => {
                if null {
                    return Ok(false);
                }
                let Value::Str(v) = &value else { return Ok(true) };
                let needle = at(values, 0).to_php_string().map_err(Error::from)?;
                needle.is_empty() || !matches_fulltext(v.as_bytes(), needle.as_bytes())
            }
            Method::Regex => {
                let Value::Str(v) = &value else { return Ok(false) };
                let pattern = at(values, 0).to_php_string().map_err(Error::from)?;
                matches_regex(v.as_bytes(), pattern.as_bytes())
            }
            other => {
                return Err(Error::database(format!(
                    "Query method not implemented in the Memory adapter: {}",
                    other.as_str()
                )));
            }
        })
    }

    /// `buildPermissionAllowSet($key, $forPermission)`: the documents the
    /// roles may access (`None` with authorization off).
    fn permission_allow_set(&self, ctx: &Ctx, key: &ArrayKey, for_permission: &str) -> Option<Array> {
        if !ctx.authorization.status() {
            return None;
        }
        let roles = ctx.authorization.roles();
        let mut allowed = Array::new();
        let Some(Value::Array(by_type)) = store::get(&self.by_permission, &[key.clone(), k(for_permission)]) else {
            return Some(allowed);
        };
        let bucket =
            if matches!(ctx.tenant, Value::Null) { "__null__".to_owned() } else { crate::value::to_text(&ctx.tenant) };
        let buckets: Vec<&Value> =
            if ctx.shared { by_type.get(&k(&bucket)).into_iter().collect() } else { by_type.values().collect() };
        for bucket in buckets {
            let Value::Array(bucket) = bucket else { continue };
            for role in &roles {
                if let Some(Value::Array(documents)) = bucket.get(&super::store::key_of(role)) {
                    for document in documents.keys() {
                        allowed.set(document.to_owned(), Value::Bool(true));
                    }
                }
            }
        }
        Some(allowed)
    }

    /// `fusedFilter($key, $collectionId, $queries, $forPermission)`.
    fn fused_filter(
        &self,
        ctx: &Ctx,
        key: &ArrayKey,
        collection: &str,
        queries: &[Query],
        for_permission: &str,
    ) -> Result<Vec<Array>> {
        let Some(documents) = store::get_array(&self.data, &[key.clone(), k("documents")]) else {
            return Ok(Vec::new());
        };
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
        let allow_null_tenant = ctx.shared && collection == Database::METADATA;
        let allowed = self.permission_allow_set(ctx, key, for_permission);
        let mut out = Vec::new();
        for row in documents.values() {
            let Value::Array(row) = row else { continue };
            if ctx.shared {
                let tenant = row.get_str("_tenant").cloned().unwrap_or(Value::Null);
                if !(tenant.strict_eq(&ctx.tenant) || (allow_null_tenant && matches!(tenant, Value::Null))) {
                    continue;
                }
            }
            if let Some(allowed) = &allowed {
                let uid = row.get_str("_uid").cloned().unwrap_or(s(""));
                if !store::isset(allowed, &[super::store::key_of(&uid)]) {
                    continue;
                }
            }
            let mut matched = true;
            for query in &effective {
                if !self.matches(ctx, row, query)? {
                    matched = false;
                    break;
                }
            }
            if matched {
                out.push(row.clone());
            }
        }
        Ok(out)
    }

    /// `find($collection, $queries, ...)`.
    pub(super) fn find(
        &self,
        ctx: &Ctx,
        collection: &Document,
        queries: &[Query],
        options: &FindOptions<'_>,
    ) -> Result<Vec<Document>> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let rows = self.fused_filter(ctx, &key, collection.id(), queries, options.for_permission)?;
        let rows = apply_ordering(rows, options.order_attributes, options.order_types, options.cursor_direction);
        let mut rows =
            apply_cursor(rows, options.order_attributes, options.order_types, options.cursor, options.cursor_direction);
        if let Some(offset) = options.offset {
            rows = slice(rows, offset, None);
        }
        if let Some(limit) = options.limit {
            rows = slice(rows, 0, Some(limit));
        }
        let selections: Vec<Value> = queries
            .iter()
            .filter(|q| *q.method() == Method::Select)
            .flat_map(|q| q.values().iter().filter(|v| matches!(v, Value::Str(_))).cloned())
            .collect();
        let mut results = Vec::with_capacity(rows.len());
        for row in &rows {
            results.push(Document::new(self.row_to_document(row, Some(&selections), Some(&key)))?);
        }
        if options.cursor_direction == Database::CURSOR_BEFORE {
            results.reverse();
        }
        Ok(results)
    }

    /// `count($collection, $queries, $max)`.
    pub(super) fn count(&self, ctx: &Ctx, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let mut rows = self.fused_filter(ctx, &key, collection.id(), queries, Database::PERMISSION_READ)?;
        if let Some(max) = max {
            rows = slice(rows, 0, Some(max));
        }
        Ok(rows.len() as i64)
    }

    /// `sum($collection, $attribute, $queries, $max)`.
    pub(super) fn sum(
        &self,
        ctx: &Ctx,
        collection: &Document,
        attribute: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> Result<Value> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let mut rows = self.fused_filter(ctx, &key, collection.id(), queries, Database::PERMISSION_READ)?;
        if let Some(max) = max {
            rows = slice(rows, 0, Some(max));
        }
        let mut sum = Value::Int(0);
        let mut is_float = false;
        let column = k(&super::filter(attribute));
        for row in &rows {
            let Some(v) = row.get(&column).filter(|v| !matches!(v, Value::Null)) else { continue };
            if matches!(v, Value::Float(_)) {
                is_float = true;
            }
            sum = super::operators::add(&sum, v)?;
        }
        Ok(if is_float { Value::Float(sum.to_double()) } else { Value::Int(super::operators::to_long(&sum)) })
    }
}

/// `array_slice($rows, $offset, $length)` of a list.
fn slice(rows: Vec<Array>, offset: i64, length: Option<i64>) -> Vec<Array> {
    let len = rows.len() as i64;
    let start = if offset < 0 { (len + offset).max(0) } else { offset.min(len) };
    let end = match length {
        None => len,
        Some(l) if l < 0 => (len + l).max(start),
        Some(l) => (start + l).min(len),
    };
    rows.into_iter().skip(start as usize).take((end - start).max(0) as usize).collect()
}

/// `usort($items, $cmp)`: the engine's sort, made stable by the items'
/// positions when `cmp` returns 0.
fn usort<T>(items: Vec<T>, cmp: impl Fn(&T, &T) -> i32) -> Vec<T> {
    let mut indexed: Vec<(usize, T)> = items.into_iter().enumerate().collect();
    php_std::sort::zend_sort(&mut indexed, &mut |a, b| {
        let r = cmp(&a.1, &b.1).signum();
        if r != 0 {
            r
        } else if a.0 < b.0 {
            -1
        } else {
            1
        }
    });
    indexed.into_iter().map(|(_, t)| t).collect()
}

/// `applyOrdering($rows, $orderAttributes, $orderTypes, $cursorDirection)`.
fn apply_ordering(rows: Vec<Array>, attributes: &[String], types: &[String], direction: &str) -> Vec<Array> {
    if types.iter().any(|t| t == Database::ORDER_RANDOM) {
        let mut rows = rows;
        let n = rows.len();
        for i in (1..n).rev() {
            let mut r = [0u8; 8];
            let _ = getrandom::fill(&mut r);
            let j = (u64::from_le_bytes(r) % (i as u64 + 1)) as usize;
            rows.swap(i, j);
        }
        return rows;
    }
    let reverse = direction == Database::CURSOR_BEFORE;
    if attributes.is_empty() {
        return usort(rows, |a, b| {
            let av = a.get_str("_id").cloned().unwrap_or(Value::Int(0));
            let bv = b.get_str("_id").cloned().unwrap_or(Value::Int(0));
            if av.strict_eq(&bv) {
                return 0;
            }
            let cmp = if lt(&av, &bv) { -1 } else { 1 };
            if reverse { -cmp } else { cmp }
        });
    }
    let columns: Vec<(ArrayKey, i32)> = attributes
        .iter()
        .enumerate()
        .map(|(i, attribute)| {
            let mut ty = types.get(i).map(String::as_str).unwrap_or(Database::ORDER_ASC);
            if reverse {
                ty = if ty == Database::ORDER_ASC { Database::ORDER_DESC } else { Database::ORDER_ASC };
            }
            (k(&Store::map_attribute(attribute)), if ty == Database::ORDER_ASC { 1 } else { -1 })
        })
        .collect();
    usort(rows, |a, b| {
        for (column, direction) in &columns {
            let av = a.get(column).cloned().unwrap_or(Value::Null);
            let bv = b.get(column).cloned().unwrap_or(Value::Null);
            if av.strict_eq(&bv) {
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
            return cmp * direction;
        }
        0
    })
}

/// `applyCursor($rows, $orderAttributes, $orderTypes, $cursor, $cursorDirection)`.
fn apply_cursor(
    rows: Vec<Array>,
    attributes: &[String],
    types: &[String],
    cursor: &Array,
    direction: &str,
) -> Vec<Array> {
    if cursor.is_empty() {
        return rows;
    }
    let (attributes, types): (Vec<String>, Vec<String>) = if attributes.is_empty() {
        (vec!["$sequence".to_owned()], vec![Database::ORDER_ASC.to_owned()])
    } else {
        (attributes.to_vec(), types.to_vec())
    };
    let reverse = direction == Database::CURSOR_BEFORE;
    let resolved: Vec<(ArrayKey, bool, Value)> = attributes
        .iter()
        .enumerate()
        .map(|(i, attribute)| {
            let mut ty = types.get(i).map(String::as_str).unwrap_or(Database::ORDER_ASC);
            if reverse {
                ty = if ty == Database::ORDER_ASC { Database::ORDER_DESC } else { Database::ORDER_ASC };
            }
            let reference =
                cursor.get(&ArrayKey::normalize(Str::from(attribute.as_str()))).cloned().unwrap_or(Value::Null);
            (k(&Store::map_attribute(attribute)), ty == Database::ORDER_ASC, reference)
        })
        .collect();
    let mut out = Vec::new();
    'rows: for row in rows {
        for (column, asc, reference) in &resolved {
            let current = row.get(column).cloned().unwrap_or(Value::Null);
            if current.strict_eq(reference) {
                continue;
            }
            if matches!(current, Value::Null) {
                if !asc {
                    out.push(row);
                }
                continue 'rows;
            }
            if matches!(reference, Value::Null) {
                if *asc {
                    out.push(row);
                }
                continue 'rows;
            }
            if if *asc { gt(&current, reference) } else { lt(&current, reference) } {
                out.push(row);
            }
            continue 'rows;
        }
    }
    out
}
