//! Finds, counts and sums: rows filtered by tenant, permission and query
//! (in PHP: comparisons, substring, full-text and PCRE matches, JSON
//! containment), ordered, paged by cursor, offset and limit.
//!
//! PHP: `Memory::find`, `count`, `sum`, `fusedFilter`, `matches`,
//! `matchesFulltext`, `tokenize`, `matchesRegex`, `looseEquals`,
//! `decodeArrayValue`, `matchesObject`, `decodeObjectValue`,
//! `jsonContains`, `wrapScalarObjectValue`, `buildPermissionAllowSet`,
//! `applyOrdering`, `applyCursor`.

use php_std::pcre;
use utopia_query::{CursorDirection, Method, OrderDirection};

use super::documents::{decode_json_like, map_attribute, selections};
use super::operators::{ge, gt, is_numeric, le, lt, numeric};
use super::store::{self, k, key_of};
use super::{Ctx, Store, filter};
use crate::adapter::{CursorBoundary, Find};
use crate::collection::Collection;
use crate::database::METADATA;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::storage;
use crate::value::{Array, ArrayKey, Value, from_query};

/// `looseEquals($a, $b)`: identical, or numbers equal with `==`.
fn loose_equals(a: &Value, b: &Value) -> bool {
    if a.strict_eq(b) {
        return true;
    }
    is_numeric(a) && is_numeric(b) && a.loose_eq(b)
}

/// `decodeArrayValue($value)` / `decodeObjectValue($value)`: an array, or
/// JSON text of one.
fn decode_array_value(value: &Value) -> Option<Array> {
    match value {
        Value::Array(a) => Some(a.clone()),
        _ => decode_json_like(value),
    }
}

/// `stripos($haystack, $needle) !== false`.
fn contains_ci(haystack: &[u8], needle: &[u8]) -> bool {
    matches!(php_std::string::stripos(haystack, needle, 0), Ok(Some(_)))
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

/// `matchesRegex($value, $pattern)`: `@preg_match('#pattern#u', ...) === 1`,
/// refusing a pattern that does not compile (8.3.4).
fn matches_regex(value: &[u8], pattern: &[u8]) -> Result<bool> {
    let mut delimited = b"#".to_vec();
    delimited.extend_from_slice(&php_std::string::str_replace(b"#", b"\\#", pattern).0);
    delimited.extend_from_slice(b"#u");
    crate::adapter::regex_matched(pcre::preg_match_bare(&delimited, value))
}

/// `jsonContains($haystack, $candidate)`: PostgreSQL's `@>`.
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
                Some(v) if json_contains(v, value) => {}
                _ => return false,
            }
        }
        return true;
    }
    if haystack.strict_eq(candidate) {
        return true;
    }
    match (numeric(haystack), numeric(candidate)) {
        (Some(a), Some(b)) => a.strict_eq(&b),
        _ => false,
    }
}

/// `wrapScalarObjectValue($candidate)`: `['k' => 'v']` as `['k' => ['v']]`.
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
    out.set(key.to_owned(), Value::Array(Array::from_list(vec![value.clone()])));
    Value::Array(out)
}

/// `matchesObject($value, $query)`.
fn matches_object(value: &Value, method: Method, values: &[Value]) -> Result<bool> {
    let haystack = decode_array_value(value).map(Value::Array);
    Ok(match method {
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

/// The containment of `contains` and `containsAny`: an array (or JSON
/// text of one) holding a needle, or a string holding a string needle
/// case-insensitively.
fn contains(value: &Value, values: &[Value]) -> bool {
    let haystack = decode_array_value(value);
    match (haystack, value) {
        (None, Value::Str(v)) => values.iter().any(|n| match n {
            Value::Str(n) => contains_ci(v.as_bytes(), n.as_bytes()),
            _ => false,
        }),
        (Some(h), _) => values.iter().any(|n| h.values().any(|item| loose_equals(item, n))),
        (None, _) => false,
    }
}

/// Whether the row matches every one of the queries (`matchesAll()`).
pub(crate) fn matches_all(row: &Array, queries: &[crate::query::Query]) -> Result<bool> {
    for query in queries {
        if !matches(row, query)? {
            return Ok(false);
        }
    }
    Ok(true)
}

/// `matches($row, $query)`.
fn matches(row: &Array, query: &utopia_query::Query) -> Result<bool> {
    let method = query.method();
    if method == Method::And {
        for sub in query.values().values() {
            match sub {
                utopia_query::Value::Ext(utopia_query::Object::Query(q)) if matches(row, q)? => {}
                _ => return Ok(false),
            }
        }
        return Ok(true);
    }
    if method == Method::Or {
        for sub in query.values().values() {
            if let utopia_query::Value::Ext(utopia_query::Object::Query(q)) = sub
                && matches(row, q)?
            {
                return Ok(true);
            }
        }
        return Ok(false);
    }
    let attribute = query.attribute();
    let object = query.attribute_type() == "object" && !attribute.contains('.');
    let value = Store::resolve_attribute_value(row, attribute);
    let values: Vec<Value> = query.values().values().map(from_query).collect();
    if object {
        return matches_object(&value, method, &values);
    }
    let null = matches!(value, Value::Null);
    let string_pair = |a: &Value, b: &Value| match (a, b) {
        (Value::Str(a), Value::Str(b)) => Some((a.as_bytes().to_vec(), b.as_bytes().to_vec())),
        _ => None,
    };
    Ok(match method {
        Method::Equal => !null && values.iter().any(|c| !matches!(c, Value::Null) && loose_equals(&value, c)),
        Method::NotEqual => {
            if null {
                return Ok(false);
            }
            for candidate in &values {
                if matches!(candidate, Value::Null) || loose_equals(&value, candidate) {
                    return Ok(false);
                }
            }
            true
        }
        Method::LessThan => !null && lt(&value, &at(&values, 0)),
        Method::LessThanEqual => !null && le(&value, &at(&values, 0)),
        Method::GreaterThan => !null && gt(&value, &at(&values, 0)),
        Method::GreaterThanEqual => !null && ge(&value, &at(&values, 0)),
        Method::IsNull => null,
        Method::IsNotNull => !null,
        Method::Between => !null && ge(&value, &at(&values, 0)) && le(&value, &at(&values, 1)),
        Method::NotBetween => !null && (lt(&value, &at(&values, 0)) || gt(&value, &at(&values, 1))),
        Method::StartsWith => string_pair(&value, &at(&values, 0)).is_some_and(|(v, n)| v.starts_with(&n)),
        Method::NotStartsWith => !null && !string_pair(&value, &at(&values, 0)).is_some_and(|(v, n)| v.starts_with(&n)),
        Method::EndsWith => string_pair(&value, &at(&values, 0)).is_some_and(|(v, n)| v.ends_with(&n)),
        Method::NotEndsWith => !null && !string_pair(&value, &at(&values, 0)).is_some_and(|(v, n)| v.ends_with(&n)),
        Method::Contains | Method::ContainsAny => contains(&value, &values),
        Method::NotContains => !null && !contains(&value, &values),
        Method::ContainsAll => match decode_array_value(&value) {
            Some(h) => values.iter().all(|n| h.values().any(|item| loose_equals(item, n))),
            None => false,
        },
        Method::Search => {
            let Value::Str(v) = &value else { return Ok(false) };
            match at(&values, 0) {
                Value::Str(n) if !n.is_empty() => matches_fulltext(v.as_bytes(), n.as_bytes()),
                _ => false,
            }
        }
        Method::NotSearch => {
            if null {
                return Ok(false);
            }
            let Value::Str(v) = &value else { return Ok(true) };
            match at(&values, 0) {
                Value::Str(n) if !n.is_empty() => !matches_fulltext(v.as_bytes(), n.as_bytes()),
                _ => true,
            }
        }
        Method::Regex => {
            let Value::Str(v) = &value else { return Ok(false) };
            match at(&values, 0) {
                Value::Str(p) => matches_regex(v.as_bytes(), p.as_bytes())?,
                _ => false,
            }
        }
        other => {
            return Err(Error::database(format!(
                "Query method not implemented in the Memory adapter: {}",
                other.as_str()
            )));
        }
    })
}

impl Store {
    /// `buildPermissionAllowSet($key, $forPermission, $tenant)`: the
    /// documents the roles may access (`None` with authorization off).
    fn permission_allow_set(&self, ctx: &Ctx, key: &ArrayKey, for_permission: &str, tenant: &Value) -> Option<Array> {
        if !ctx.authorization.status() {
            return None;
        }
        let roles = ctx.authorization.role_names();
        let mut allowed = Array::new();
        let Some(Value::Array(by_type)) = store::get(&self.by_permission, &[key.clone(), k(for_permission)]) else {
            return Some(allowed);
        };
        let bucket = if matches!(tenant, Value::Null) {
            k("__null__")
        } else {
            key_of(&Value::Str(tenant.to_php_string().unwrap_or_default()))
        };
        let buckets: Vec<&Value> =
            if ctx.shared { by_type.get(&bucket).into_iter().collect() } else { by_type.values().collect() };
        for bucket in buckets {
            let Value::Array(bucket) = bucket else { continue };
            for role in &roles {
                if let Some(Value::Array(documents)) = bucket.get(&k(role)) {
                    for document in documents.keys() {
                        allowed.set(document.to_owned(), Value::Bool(true));
                    }
                }
            }
        }
        Some(allowed)
    }

    /// `fusedFilter($key, $collectionId, $queries, $forPermission)`: the rows
    /// of the tenant (and, for the metadata collection, the tenantless
    /// ones) the roles may access and every filter matches.
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
        let tenant = if ctx.shared { ctx.tenant.clone() } else { Value::Null };
        let allow_null_tenant = ctx.shared && collection == METADATA;
        let allowed = self.permission_allow_set(ctx, key, for_permission, &tenant);
        let tenantless =
            if allow_null_tenant { self.permission_allow_set(ctx, key, for_permission, &Value::Null) } else { None };
        let mut out = Vec::new();
        for row in documents.values() {
            let Value::Array(row) = row else { continue };
            let mut row_allowed = allowed.as_ref();
            if ctx.shared {
                let row_tenant = row.get_str(storage::TENANT).cloned().unwrap_or(Value::Null);
                if allow_null_tenant && matches!(row_tenant, Value::Null) {
                    row_allowed = tenantless.as_ref();
                } else if !row_tenant.strict_eq(&tenant) {
                    continue;
                }
            }
            if let Some(allowed) = row_allowed {
                match row.get_str(storage::UID) {
                    Some(uid @ Value::Str(_)) if store::isset(allowed, &[key_of(uid)]) => {}
                    _ => continue,
                }
            }
            let mut matched = true;
            for query in &effective {
                if !matches(row, query)? {
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
    pub(crate) fn find(
        &self,
        ctx: &Ctx,
        collection: &Collection,
        queries: &[Query],
        options: &Find,
    ) -> Result<Vec<Document>> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let rows = self.fused_filter(ctx, &key, collection.id(), queries, options.for_permission.as_str())?;
        let rows = apply_ordering(rows, &options.order_attributes, &options.order_types, options.cursor_direction);
        let mut rows = apply_cursor(
            rows,
            &options.order_attributes,
            &options.order_types,
            &options.cursor,
            options.cursor_direction,
        );
        if let Some(offset) = options.offset {
            rows = slice(rows, offset, None);
        }
        if let Some(limit) = options.limit {
            rows = slice(rows, 0, Some(limit));
        }
        let selections = selections(queries);
        let mut results = Vec::with_capacity(rows.len());
        for row in &rows {
            results.push(Document::from_storage(self.row_to_document(row, Some(&selections), Some(&key)))?);
        }
        if options.cursor_direction == CursorDirection::Before {
            results.reverse();
        }
        Ok(results)
    }

    /// `count($collection, $queries, $max)`.
    pub(crate) fn count(&self, ctx: &Ctx, collection: &Collection, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let mut rows = self.fused_filter(ctx, &key, collection.id(), queries, PermissionType::Read.as_str())?;
        if let Some(max) = max {
            rows = slice(rows, 0, Some(max));
        }
        Ok(rows.len() as i64)
    }

    /// `sum($collection, $attribute, $queries, $max)`: an integer, or a
    /// float when a summed value is one ([`crate::adapter::sum_values`]).
    pub(crate) fn sum(
        &self,
        ctx: &Ctx,
        collection: &Collection,
        attribute: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> Result<Value> {
        let key = Self::key(ctx, collection.id());
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let mut rows = self.fused_filter(ctx, &key, collection.id(), queries, PermissionType::Read.as_str())?;
        if let Some(max) = max {
            rows = slice(rows, 0, Some(max));
        }
        let column = k(&filter(attribute));
        crate::adapter::sum_values(rows.iter().filter_map(|row| row.get(&column)))
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

/// `usort($items, $cmp)`: the engine's sort, made stable (as PHP 8's is) by
/// the items' positions when `cmp` returns 0.
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

/// `shuffle($rows)`.
fn shuffle(mut rows: Vec<Array>) -> Vec<Array> {
    let n = rows.len();
    for i in (1..n).rev() {
        let mut r = [0u8; 8];
        let _ = getrandom::fill(&mut r);
        let j = (u64::from_le_bytes(r) % (i as u64 + 1)) as usize;
        rows.swap(i, j);
    }
    rows
}

/// The direction of an order, flipped for a read before the cursor.
fn direction(types: &[OrderDirection], i: usize, reverse: bool) -> OrderDirection {
    let ty = types.get(i).copied().unwrap_or(OrderDirection::Asc);
    if !reverse {
        return ty;
    }
    if ty == OrderDirection::Asc { OrderDirection::Desc } else { OrderDirection::Asc }
}

/// `applyOrdering($rows, $orderAttributes, $orderTypes, $cursorDirection)`:
/// by sequence without an order, `null` first ascending.
fn apply_ordering(
    rows: Vec<Array>,
    attributes: &[String],
    types: &[OrderDirection],
    cursor: CursorDirection,
) -> Vec<Array> {
    if types.contains(&OrderDirection::Random) {
        return shuffle(rows);
    }
    let reverse = cursor == CursorDirection::Before;
    if attributes.is_empty() {
        return usort(rows, |a, b| {
            let av = a.get_str(storage::SEQUENCE).cloned().unwrap_or(Value::Int(0));
            let bv = b.get_str(storage::SEQUENCE).cloned().unwrap_or(Value::Int(0));
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
            let sign = if direction(types, i, reverse) == OrderDirection::Asc { 1 } else { -1 };
            (k(&map_attribute(attribute)), sign)
        })
        .collect();
    usort(rows, |a, b| {
        for (column, sign) in &columns {
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
            return cmp * sign;
        }
        0
    })
}

/// `applyCursor($rows, $orderAttributes, $orderTypes, $cursor, $cursorDirection)`:
/// the rows past the cursor on the first order attribute that differs.
fn apply_cursor(
    rows: Vec<Array>,
    attributes: &[String],
    types: &[OrderDirection],
    cursor: &Array,
    direction_of: CursorDirection,
) -> Vec<Array> {
    if cursor.is_empty() {
        return rows;
    }
    let (attributes, types): (Vec<String>, Vec<OrderDirection>) = if attributes.is_empty() {
        (vec![document::SEQUENCE.to_owned()], vec![OrderDirection::Asc])
    } else {
        (attributes.to_vec(), types.to_vec())
    };
    let reverse = direction_of == CursorDirection::Before;
    let resolved: Vec<CursorBoundary> = attributes
        .iter()
        .enumerate()
        .map(|(i, attribute)| CursorBoundary {
            field: map_attribute(attribute),
            direction: direction(&types, i, reverse),
            reference: cursor.get(&k(attribute)).cloned().unwrap_or(Value::Null),
        })
        .collect();
    let mut out = Vec::new();
    'rows: for row in rows {
        for entry in &resolved {
            let current = row.get(&k(&entry.field)).cloned().unwrap_or(Value::Null);
            let reference = &entry.reference;
            let ascending = entry.direction == OrderDirection::Asc;
            if current.strict_eq(reference) {
                continue;
            }
            if matches!(current, Value::Null) {
                if !ascending {
                    out.push(row);
                }
                continue 'rows;
            }
            if matches!(reference, Value::Null) {
                if ascending {
                    out.push(row);
                }
                continue 'rows;
            }
            if if ascending { gt(&current, reference) } else { lt(&current, reference) } {
                out.push(row);
            }
            continue 'rows;
        }
    }
    out
}
