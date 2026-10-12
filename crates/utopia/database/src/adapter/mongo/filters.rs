//! Queries as MongoDB filters, and the key mappings between documents and
//! stored records.
//!
//! PHP: `Mongo::buildFilters`, `buildFilter`, `handleObjectFilters`,
//! `flattenWithDotNotation`, `getQueryOperator`, `getQueryValue`,
//! `createSafeRegex`, `replaceChars`, `replaceInternalIdsKeys`,
//! `escapeQueryAttributes`, `getAttributeProjection`,
//! `ensureRelationshipDefaults`, `removeNullKeys`.

use super::Mongo;
use crate::adapter::Adapter;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::query::{Method, Query};
use crate::value::{Array, ArrayKey, BsonValue, Ext, KeyRef, Str, Value};

/// The operators `replaceInternalIdsKeys` leaves alone (PHP's
/// `$operators`; `$all` is not among them).
const OPERATORS: [&str; 18] = [
    "$eq",
    "$ne",
    "$lt",
    "$lte",
    "$gt",
    "$gte",
    "$in",
    "$nin",
    "$text",
    "$search",
    "$or",
    "$and",
    "$match",
    "$regex",
    "$not",
    "$nor",
    "$exists",
    "$elemMatch",
];

/// `$array[$key]` as an array (a fresh one when missing or not an array).
pub(super) fn entry_of<'a>(array: &'a mut Array, key: &str) -> &'a mut Array {
    let k = ArrayKey::normalize(Str::from(key));
    if !matches!(array.get(&k), Some(Value::Array(_))) {
        array.set(k.clone(), Value::Array(Array::new()));
    }
    match array.get_mut(&k) {
        Some(Value::Array(a)) => a,
        _ => unreachable!("set just above"),
    }
}

/// `$array[$key] = $value`.
pub(super) fn set(array: &mut Array, key: &str, value: Value) {
    array.set(ArrayKey::normalize(Str::from(key)), value);
}

/// `$array[$key][] = $value`.
fn push(array: &mut Array, key: &str, value: Value) {
    let _ = entry_of(array, key).push(value);
}

/// A one-entry array.
pub(super) fn pair(key: &str, value: Value) -> Value {
    let mut a = Array::new();
    set(&mut a, key, value);
    Value::Array(a)
}

/// `$array[$value]`'s key: PHP's cast of a value used as an array offset
/// (a bool or float is an integer, `null` the empty string); an array or
/// object is a `TypeError`.
fn offset(value: &Value) -> Result<ArrayKey> {
    Ok(match value {
        Value::Array(_) | Value::Object(_) | Value::Ext(_) => {
            return Err(Error::new(
                Kind::TypeError,
                format!("Cannot access offset of type {} on array", value.debug_type()),
            ));
        }
        Value::Bool(b) => ArrayKey::Int(i64::from(*b)),
        Value::Float(f) => ArrayKey::Int(php_std::format::dval_to_lval(*f)),
        Value::Null => ArrayKey::Str(Str::EMPTY),
        other => ArrayKey::normalize(Str::from(crate::value::to_text(other))),
    })
}

/// A key's text (integer keys as their digits).
fn key_text(k: KeyRef<'_>) -> String {
    match k {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// `preg_quote($value, '/')`.
fn quote(value: &[u8]) -> Vec<u8> {
    php_std::pcre::preg_quote(value, Some(b"/"))
}

/// `replaceChars($from, $to, $array)`: maps keys between documents
/// (`$id`, `$permissions`, `$createdAt`, ...) and stored records (`_uid`,
/// `_permissions`, `_createdAt`, ...), recursively, dots escaped as
/// `__dot__` on the way in and back on the way out. Renamed keys move to
/// the end, as PHP re-adds them.
pub(super) fn replace_chars(from: &str, to: &str, mut array: Array) -> Array {
    const FILTER: [&str; 4] = ["permissions", "createdAt", "updatedAt", "collection"];
    const KEEP: [&str; 6] = ["$id", "$sequence", "$tenant", "_uid", "_id", "_tenant"];
    let mut renames: Vec<(ArrayKey, String)> = Vec::new();
    let keys: Vec<ArrayKey> = array.keys().map(KeyRef::to_owned).collect();
    for k in keys {
        if let Some(Value::Array(v)) = array.get(&k) {
            let v = v.clone();
            array.set(k.clone(), Value::Array(replace_chars(from, to, v)));
        }
        let ArrayKey::Str(s) = &k else { continue };
        let key = String::from_utf8_lossy(s.as_bytes()).into_owned();
        let clean = key.replace(from, "");
        let mut new_key = key.clone();
        if FILTER.contains(&clean.as_str()) {
            new_key = key.replace(from, to);
        } else if key.starts_with(from) && !KEEP.contains(&key.as_str()) {
            new_key = format!("{to}{}", &key[from.len()..]);
        }
        if from == "$" && new_key.contains('.') {
            new_key = new_key.replace('.', "__dot__");
        } else if from == "_" && key.contains("__dot__") {
            new_key = new_key.replace("__dot__", ".");
        }
        if new_key != key {
            renames.push((k.clone(), new_key));
        }
    }
    for (old, new) in renames {
        let value = array.get(&old).cloned().unwrap_or(Value::Null);
        array.set(ArrayKey::normalize(Str::from(new.as_str())), value);
        array.remove(&old);
    }
    let take = |array: &mut Array, old: &str, new: &str, string: bool| {
        let old = ArrayKey::Str(Str::from(old));
        if let Some(value) = array.get(&old).filter(|v| !super::null(v)).cloned() {
            let value = if string { Value::Str(super::bson::php_string(&value)) } else { value };
            array.set(ArrayKey::Str(Str::from(new)), value);
            array.remove(&old);
        }
    };
    if from == "_" {
        take(&mut array, "_id", "$sequence", true);
        take(&mut array, "_uid", "$id", false);
        take(&mut array, "_tenant", "$tenant", false);
    } else if from == "$" {
        take(&mut array, "$id", "_uid", false);
        take(&mut array, "$sequence", "_id", false);
        take(&mut array, "$tenant", "_tenant", false);
    }
    array
}

/// `replaceInternalIdsKeys($array, $from, $to, $exclude)`: `from` replaced
/// by `to` in every key but the operators, recursively.
pub(super) fn replace_internal_ids_keys(array: &Array, from: &str, to: &str) -> Array {
    array
        .iter()
        .map(|(k, v)| {
            let key = match k {
                KeyRef::Str(s) => {
                    let text = String::from_utf8_lossy(s);
                    if OPERATORS.contains(&text.as_ref()) {
                        k.to_owned()
                    } else {
                        ArrayKey::normalize(Str::from(text.replace(from, to)))
                    }
                }
                KeyRef::Int(_) => k.to_owned(),
            };
            let value = match v {
                Value::Array(a) => Value::Array(replace_internal_ids_keys(a, from, to)),
                other => other.clone(),
            };
            (key, value)
        })
        .collect()
}

/// `removeNullKeys($target)`: the top-level entries that are not null.
pub(super) fn remove_null_keys(array: Array) -> Array {
    array.into_iter().filter(|(_, v)| !super::null(v)).collect()
}

impl Mongo {
    /// `createSafeRegex($value, $pattern, $flags)`: `value` quoted into
    /// `pattern` (`%s`), refusing a `$` followed by letters.
    fn safe_regex(&self, value: &Value, pattern: &str, flags: &str, line: u32) -> Result<Value> {
        // `string $value` of a userland method in coercive mode: scalars
        // convert, anything else (null included) is a TypeError naming the
        // calling line.
        if matches!(
            value,
            Value::Null
                | Value::Array(_)
                | Value::Object(_)
                | Value::Ext(Ext::Document(_) | Ext::Query(_) | Ext::Operator(_))
        ) {
            return Err(Error::new(
                Kind::TypeError,
                format!(
                    "Utopia\\Database\\Adapter\\Mongo::createSafeRegex(): Argument #1 ($value) must be of type string, {} given, called in /usr/src/code/vendor/utopia-php/database/src/Database/Adapter/Mongo.php on line {line}",
                    crate::document::value_name(value)
                ),
            ));
        }
        let text = super::bson::php_string(value);
        let escaped = quote(text.as_bytes());
        let injected = php_std::pcre::preg_match(br"/\$[a-z]+/i", &escaped, 0, 0)
            .ok()
            .is_some_and(|m| m.value.result == php_std::pcre::Value::Int(1));
        if injected {
            return Err(Error::database("Invalid regex pattern: potential injection detected"));
        }
        let escaped = String::from_utf8_lossy(&escaped).into_owned();
        Ok(Value::from(BsonValue::Regex { pattern: pattern.replacen("%s", &escaped, 1), flags: flags.to_owned() }))
    }

    /// `getQueryOperator($method)`.
    pub(super) fn query_operator(method: &Method) -> Result<&'static str> {
        Ok(match method {
            Method::Equal | Method::IsNull => "$eq",
            Method::NotEqual | Method::IsNotNull => "$ne",
            Method::LessThan => "$lt",
            Method::LessThanEqual => "$lte",
            Method::GreaterThan => "$gt",
            Method::GreaterThanEqual => "$gte",
            Method::Contains | Method::ContainsAny => "$in",
            Method::ContainsAll => "$all",
            Method::NotContains => "notContains",
            Method::Search | Method::NotSearch => "$search",
            Method::Between => "between",
            Method::NotBetween => "notBetween",
            Method::StartsWith | Method::NotStartsWith | Method::EndsWith | Method::NotEndsWith | Method::Regex => {
                "$regex"
            }
            Method::Or => "$or",
            Method::And => "$and",
            Method::Exists | Method::NotExists => "$exists",
            Method::ElemMatch => "$elemMatch",
            other => {
                return Err(Error::database(format!(
                    "Unknown operator:{}. Must be one of equal, notEqual, lessThan, lessThanEqual, greaterThan, greaterThanEqual, isNull, isNotNull, between, notBetween, startsWith, notStartsWith, endsWith, notEndsWith, contains, notContains, search, notSearch, select",
                    other.as_str()
                )));
            }
        })
    }

    /// `getQueryValue($method, $value)`: starts-with and ends-with as
    /// regular expressions.
    fn query_value(method: &Method, value: Value) -> Result<Value> {
        let text = |v: &Value| crate::document::string_arg(v, "preg_quote", "#1 ($str)");
        Ok(match method {
            Method::StartsWith => {
                let mut v = quote(text(&value)?.as_bytes());
                v.extend_from_slice(b".*");
                Value::Str(Str::from(v))
            }
            Method::EndsWith => {
                let mut v = b".*".to_vec();
                v.extend_from_slice(&quote(text(&value)?.as_bytes()));
                Value::Str(Str::from(v))
            }
            _ => value,
        })
    }

    /// `buildFilters($queries, $separator)`: the filter queries joined
    /// under `separator` (`$and`), nested ones recursively.
    pub(super) fn build_filters(&self, queries: &[Query], separator: &str) -> Result<Array> {
        let mut filters = Array::new();
        let grouped = Query::group_by_type(queries);
        for query in &grouped.filters {
            if query.is_nested() {
                let nested: Vec<Query> = query.queries().cloned().collect();
                if *query.method() == Method::ElemMatch {
                    let inner = self.build_filters(&nested, separator)?;
                    push(&mut filters, separator, pair(query.attribute(), pair("$elemMatch", Value::Array(inner))));
                    continue;
                }
                let operator = Self::query_operator(query.method())?;
                let inner = self.build_filters(&nested, operator)?;
                push(&mut filters, separator, Value::Array(inner));
            } else {
                let filter = self.build_filter(query.clone())?;
                push(&mut filters, separator, Value::Array(filter));
            }
        }
        Ok(filters)
    }

    /// `buildFilter($query)`: one query as a filter.
    pub(super) fn build_filter(&self, mut query: Query) -> Result<Array> {
        if !self.support_for_attributes() || matches!(query.attribute(), "$createdAt" | "$updatedAt") {
            let values: Vec<Value> = query
                .values()
                .iter()
                .map(|value| match value {
                    Value::Str(s) if Self::is_extended_iso_datetime(s.as_bytes()) => {
                        super::casting::utc_datetime(s.as_bytes()).unwrap_or_else(|_| value.clone())
                    }
                    other => other.clone(),
                })
                .collect();
            query.set_values(values);
        }
        match query.attribute() {
            "$id" => {
                query.set_attribute("_uid");
            }
            "$sequence" => {
                query.set_attribute("_id");
            }
            "$createdAt" => {
                query.set_attribute("_createdAt");
            }
            "$updatedAt" => {
                query.set_attribute("_updatedAt");
            }
            other if other.starts_with('$') => {
                let escaped = Self::escape_field_name(other);
                query.set_attribute(escaped);
            }
            _ => {}
        }
        let attribute = query.attribute().to_owned();
        let operator = Self::query_operator(query.method())?;
        let method = query.method().clone();
        let values = query.values().to_vec();
        let value = match method {
            Method::IsNull | Method::IsNotNull => Value::Null,
            Method::Exists => Value::Bool(true),
            Method::NotExists => Value::Bool(false),
            _ => {
                let raw = if values.len() > 1 {
                    Value::Array(values.iter().cloned().collect())
                } else {
                    values.first().cloned().unwrap_or(Value::Null)
                };
                Self::query_value(&method, raw)?
            }
        };
        let mut filter = Array::new();
        let object_methods = [
            Method::Equal,
            Method::Contains,
            Method::ContainsAny,
            Method::ContainsAll,
            Method::NotContains,
            Method::NotEqual,
        ];
        if query.is_object_attribute() && !attribute.contains('.') && object_methods.contains(&method) {
            self.handle_object_filters(&query, &mut filter);
            return Ok(filter);
        }
        let list = Value::Array(values.iter().cloned().collect());
        if operator == "$eq" && matches!(value, Value::Array(_)) {
            set(entry_of(&mut filter, &attribute), "$in", value);
        } else if operator == "$ne" && matches!(value, Value::Array(_)) {
            set(entry_of(&mut filter, &attribute), "$nin", value);
        } else if operator == "$all" {
            set(entry_of(&mut filter, &attribute), "$all", list);
        } else if operator == "$in" {
            if matches!(method, Method::Contains | Method::ContainsAny) && !query.on_array() {
                if let Value::Array(items) = &value {
                    // array_map() keeps the keys: a map is a document.
                    let mut ors = Array::new();
                    for (key, item) in items.iter() {
                        ors.set(
                            key.to_owned(),
                            pair(&attribute, pair("$regex", self.safe_regex(item, ".*%s.*", "i", 3203)?)),
                        );
                    }
                    set(&mut filter, "$or", Value::Array(ors));
                } else {
                    set(entry_of(&mut filter, &attribute), "$regex", self.safe_regex(&value, ".*%s.*", "i", 3208)?);
                }
            } else {
                set(entry_of(&mut filter, &attribute), "$in", list);
            }
        } else if operator == "notContains" {
            if !query.on_array() {
                set(&mut filter, &attribute, pair("$not", self.safe_regex(&value, ".*%s.*", "i", 3215)?));
            } else {
                set(entry_of(&mut filter, &attribute), "$nin", list);
            }
        } else if operator == "$search" {
            if method == Method::NotSearch {
                if value.truthy() {
                    set(&mut filter, &attribute, pair("$not", self.safe_regex(&value, ".*%s.*", "i", 3226)?));
                }
            } else {
                set(entry_of(&mut filter, "$text"), operator, value);
            }
        } else if operator == "between" {
            let (low, high) = bounds(&value);
            let range = entry_of(&mut filter, &attribute);
            set(range, "$lte", high);
            set(range, "$gte", low);
        } else if operator == "notBetween" {
            let (low, high) = bounds(&value);
            let ors = Array::from_list(vec![pair(&attribute, pair("$lt", low)), pair(&attribute, pair("$gt", high))]);
            set(&mut filter, "$or", Value::Array(ors));
        } else if operator == "$regex" && method == Method::NotStartsWith {
            set(&mut filter, &attribute, pair("$not", self.safe_regex(&value, "^%s", "i", 3240)?));
        } else if operator == "$regex" && method == Method::NotEndsWith {
            set(&mut filter, &attribute, pair("$not", self.safe_regex(&value, "%s$", "i", 3242)?));
        } else if operator == "$exists" {
            for name in values.iter() {
                let mut condition = Array::new();
                condition.set(offset(name)?, pair(operator, value.clone()));
                push(&mut filter, "$or", Value::Array(condition));
            }
        } else {
            set(entry_of(&mut filter, &attribute), operator, value);
        }
        Ok(filter)
    }

    /// `handleObjectFilters($query, $filter)`: equality and containment on
    /// an object attribute, as conditions on its flattened paths.
    fn handle_object_filters(&self, query: &Query, filter: &mut Array) {
        let not = matches!(query.method(), Method::NotContains | Method::NotEqual);
        let mut conditions: Vec<Value> = Vec::new();
        for (k, value) in query.values_array().iter() {
            let key = match k {
                KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                KeyRef::Int(_) => String::new(),
            };
            let flattened = flatten(&key, value.clone());
            let Some((path, leaf)) = flattened.into_iter().next() else { continue };
            let field =
                if path.is_empty() { query.attribute().to_owned() } else { format!("{}.{path}", query.attribute()) };
            match query.method() {
                Method::Contains | Method::ContainsAny | Method::ContainsAll | Method::NotContains => {
                    let list = match leaf {
                        Value::Array(a) => Value::Array(a),
                        other => Value::Array(Array::from_list(vec![other])),
                    };
                    conditions.push(pair(&field, pair(if not { "$nin" } else { "$in" }, list)));
                }
                Method::Equal | Method::NotEqual => {
                    let operator = match (&leaf, not) {
                        (Value::Array(_), true) => "$nin",
                        (Value::Array(_), false) => "$in",
                        (_, true) => "$ne",
                        (_, false) => "$eq",
                    };
                    conditions.push(pair(&field, pair(operator, leaf)));
                }
                _ => {}
            }
        }
        let logical = if not { "$and" } else { "$or" };
        let existing = match filter.get_str(logical) {
            Some(Value::Array(a)) => Some(a.clone()),
            _ => None,
        };
        match existing {
            Some(mut a) if !conditions.is_empty() => {
                for c in conditions {
                    let _ = a.push(c);
                }
                set(filter, logical, Value::Array(a));
            }
            _ => set(filter, logical, Value::Array(Array::from_list(conditions))),
        }
    }

    /// `escapeQueryAttributes($collection, $queries)`: queries on
    /// attributes whose names have dots or start with `$` use the escaped
    /// stored name (a nested path keeps its dots).
    pub(super) fn escape_query_attributes(collection: &Document, queries: &mut [Query]) {
        let mut escaped: Vec<(String, String)> = Vec::new();
        if let Some(Value::Array(attributes)) = collection.get("attributes") {
            for attribute in attributes.values() {
                let key = match attribute {
                    Value::Ext(Ext::Document(d)) => d.get("$id").map(crate::value::to_text),
                    Value::Array(a) => a.get_str("$id").map(crate::value::to_text),
                    _ => None,
                }
                .unwrap_or_default();
                if key.contains('.') || key.starts_with('$') {
                    escaped.push((key.clone(), Self::escape_field_name(&key)));
                }
            }
        }
        if escaped.is_empty() {
            return;
        }
        for query in queries.iter_mut() {
            if let Some((_, name)) = escaped.iter().rev().find(|(k, _)| k == query.attribute()) {
                let name = name.clone();
                query.set_attribute(name);
            }
        }
    }

    /// `getAttributeProjection($selections)`: the selected attributes (but
    /// internal ones) and every internal field.
    pub(super) fn attribute_projection(selections: &[Value]) -> Result<Array> {
        let internal: Vec<Value> =
            Database::internal_attributes_const().iter().map(|a| Value::Str(Str::from(a.id()))).collect();
        let mut projection = Array::new();
        for selection in selections {
            // `in_array()`: loose comparison.
            if internal.iter().any(|i| i.loose_eq(selection)) {
                continue;
            }
            projection.set(offset(selection)?, Value::Int(1));
        }
        for field in ["_uid", "_id", "_createdAt", "_updatedAt", "_permissions"] {
            set(&mut projection, field, Value::Int(1));
        }
        Ok(projection)
    }

    /// `getAttributeSelections($queries)`: the values of the `select` queries.
    pub(super) fn attribute_selections(queries: &[Query]) -> Vec<Value> {
        queries.iter().filter(|q| *q.method() == Method::Select).flat_map(|q| q.values().to_vec()).collect()
    }

    /// `ensureRelationshipDefaults($collection, $document)`: `null` for the
    /// relationship attributes a document lacks where SQL would have a
    /// column for them.
    pub(super) fn ensure_relationship_defaults(collection: &Document, document: &mut Document) {
        let Some(Value::Array(attributes)) = collection.get("attributes") else { return };
        for attribute in attributes.values() {
            let get = |key: &str| -> Option<Value> {
                match attribute {
                    Value::Ext(Ext::Document(d)) => d.get(key).cloned(),
                    Value::Array(a) => a.get_str(key).cloned(),
                    _ => None,
                }
            };
            let key = get("$id").map(|v| crate::value::to_text(&v)).unwrap_or_default();
            let kind = get("type").map(|v| crate::value::to_text(&v)).unwrap_or_default();
            if kind != Database::VAR_RELATIONSHIP
                || document.as_array().contains_key(&ArrayKey::normalize(Str::from(key.as_str())))
            {
                continue;
            }
            let options = match get("options") {
                Some(Value::Array(a)) => a,
                Some(Value::Ext(Ext::Document(d))) => d.as_array().clone(),
                _ => Array::new(),
            };
            let option = |k: &str| options.get_str(k).cloned();
            let two_way = option("twoWay").is_some_and(|v| v.truthy());
            let side = option("side").map(|v| crate::value::to_text(&v)).unwrap_or_default();
            let relation = option("relationType").map(|v| crate::value::to_text(&v)).unwrap_or_default();
            let stores = match relation.as_str() {
                Database::RELATION_ONE_TO_ONE => side == Database::RELATION_SIDE_PARENT || two_way,
                Database::RELATION_ONE_TO_MANY => side == Database::RELATION_SIDE_CHILD,
                Database::RELATION_MANY_TO_ONE => side == Database::RELATION_SIDE_PARENT,
                _ => false,
            };
            if stores {
                document.set(key.as_str(), Value::Null);
            }
        }
    }
}

/// `$value[0]` and `$value[1]` of a between query's values.
fn bounds(value: &Value) -> (Value, Value) {
    match value {
        Value::Array(a) => (
            a.get(&ArrayKey::Int(0)).cloned().unwrap_or(Value::Null),
            a.get(&ArrayKey::Int(1)).cloned().unwrap_or(Value::Null),
        ),
        Value::Str(s) => {
            // A string offset: its first and second bytes.
            let byte = |i: usize| s.as_bytes().get(i).map_or(Value::Null, |b| Value::Str(Str::from(vec![*b])));
            (byte(0), byte(1))
        }
        _ => (Value::Null, Value::Null),
    }
}

/// `flattenWithDotNotation($key, $value)`: the leaves of nested maps as
/// dotted paths (lists are leaves), last pushed first, as PHP's stack pops.
fn flatten(key: &str, value: Value) -> Vec<(String, Value)> {
    let mut result: Vec<(String, Value)> = Vec::new();
    let mut stack: Vec<(String, Value)> = vec![(key.to_owned(), value)];
    while let Some((path, current)) = stack.pop() {
        match current {
            Value::Array(a) if !a.is_list() => {
                for (k, v) in a.iter() {
                    let next = key_text(k);
                    let next_path = if path.is_empty() { next } else { format!("{path}.{next}") };
                    stack.push((next_path, v.clone()));
                }
            }
            leaf => {
                if let Some(entry) = result.iter_mut().find(|(p, _)| *p == path) {
                    entry.1 = leaf;
                } else {
                    result.push((path, leaf));
                }
            }
        }
    }
    result
}
