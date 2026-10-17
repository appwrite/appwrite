//! Queries as MongoDB filters, and the key mappings between documents and
//! stored records.
//!
//! Filters are built as PHP arrays ([`Array`]) in the order and shape PHP's
//! adapter builds them, then encoded to BSON.
//!
//! PHP: `Mongo::buildFilters`, `buildFilter`, `handleObjectFilters`,
//! `flattenWithDotNotation`, `getQueryOperator`, `getQueryValue`,
//! `createSafeRegex`, `replaceCharacters`, `replaceInternalIdsKeys`,
//! `escapeQueryAttributes`, `getEscapedAttributes`, `getAttributeProjection`,
//! `ensureRelationshipDefaults`, `removeNullKeys`, `stringifyIdentifier`.

use utopia_query::{Method, Object, Value as QValue};

use super::{Mongo, OPERATORS, key, text};
use crate::adapter::Adapter;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::query::Query;
use crate::relationship::{RelationshipSide, RelationshipType};
use crate::storage;
use crate::value::{Array, ArrayKey, BsonValue, Ext, KeyRef, Str, Value};

/// An array of `(key, value)` pairs, in order.
pub(crate) fn assoc<const N: usize>(pairs: [(&str, Value); N]) -> Value {
    Value::Array(map(pairs))
}

/// [`assoc`] as an [`Array`].
pub(crate) fn map<const N: usize>(pairs: [(&str, Value); N]) -> Array {
    pairs.into_iter().map(|(k, v)| (key(k), v)).collect()
}

/// `$array[$key][] = $value`.
fn push(array: &mut Array, name: &str, value: Value) {
    let k = key(name);
    if !matches!(array.get(&k), Some(Value::Array(_))) {
        array.set(k.clone(), Value::Array(Array::new()));
    }
    if let Some(Value::Array(a)) = array.get_mut(&k) {
        let _ = a.push(value);
    }
}

/// A key's text (integer keys as their digits).
pub(crate) fn key_text(k: KeyRef<'_>) -> String {
    match k {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// `\is_string($v) ? $v : (\is_scalar($v) ? (string) $v : '')`.
pub(crate) fn scalar_string(value: &Value) -> Str {
    match value {
        Value::Str(s) => s.clone(),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => value.to_php_string().unwrap_or(Str::EMPTY),
        _ => Str::EMPTY,
    }
}

/// `stringifyIdentifier($value)`: a string as it is, a scalar as PHP prints
/// it, a BSON object by its `__toString()` (an ObjectId's hex), anything
/// else `''`.
pub(crate) fn stringify(value: &Value) -> Str {
    match value {
        Value::Str(s) => s.clone(),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => value.to_php_string().unwrap_or(Str::EMPTY),
        Value::Ext(Ext::Bson(b)) => Str::from(match &**b {
            BsonValue::ObjectId(bytes) => hex::encode(bytes),
            BsonValue::UtcDateTime(ms) => ms.to_string(),
            BsonValue::Int64(n) => n.to_string(),
            BsonValue::Regex { pattern, flags } => format!("/{pattern}/{flags}"),
        }),
        _ => Str::EMPTY,
    }
}

/// PHP's `empty($value)`.
pub(crate) fn empty(value: Option<&Value>) -> bool {
    match value {
        None | Some(Value::Null) => true,
        Some(v) => !v.truthy(),
    }
}

/// `preg_quote($value, '/')`.
fn quote(value: &[u8]) -> Vec<u8> {
    php_std::pcre::preg_quote(value, Some(b"/"))
}

/// `createSafeRegex($value, $pattern, $flags)`: `value` quoted into
/// `pattern` (its `%s`).
fn safe_regex(value: &[u8], pattern: &str, flags: &str) -> Value {
    let escaped = String::from_utf8_lossy(&quote(value)).into_owned();
    Value::from(BsonValue::Regex { pattern: pattern.replacen("%s", &escaped, 1), flags: flags.to_owned() })
}

/// `replaceCharacters($from, $to, $array)`: maps keys between documents
/// (`$id`, `$permissions`, `$createdAt`, ...) and stored records (`_uid`,
/// `_permissions`, `_createdAt`, ...), into nested arrays too, dots escaped
/// as `__dot__` on the way in and back on the way out. Renamed keys move to
/// the end, as PHP re-adds them.
pub fn replace_characters(from: &str, to: &str, mut array: Array) -> Array {
    const SWAPPED: [&str; 4] = ["permissions", "createdAt", "updatedAt", "collection"];
    const KEEP: [&str; 6] =
        [document::ID, document::SEQUENCE, document::TENANT, storage::UID, storage::SEQUENCE, storage::TENANT];
    let mut renames: Vec<(ArrayKey, Str)> = Vec::new();
    let keys: Vec<ArrayKey> = array.keys().map(KeyRef::to_owned).collect();
    for k in keys {
        if let Some(Value::Array(v)) = array.get_mut(&k) {
            let inner = std::mem::take(v);
            *v = replace_characters(from, to, inner);
        }
        let ArrayKey::Str(s) = &k else { continue };
        let name = s.as_bytes();
        let clean = replace_bytes(name, from.as_bytes(), b"");
        let mut new_key = name.to_vec();
        if SWAPPED.iter().any(|w| w.as_bytes() == clean.as_slice()) {
            new_key = replace_bytes(name, from.as_bytes(), to.as_bytes());
        } else if name.starts_with(from.as_bytes()) && !KEEP.iter().any(|w| w.as_bytes() == name) {
            new_key = [to.as_bytes(), &name[from.len()..]].concat();
        }
        if from == "$" && new_key.contains(&b'.') {
            new_key = replace_bytes(&new_key, b".", b"__dot__");
        } else if from == "_" && contains(name, b"__dot__") {
            new_key = replace_bytes(&new_key, b"__dot__", b".");
        }
        if new_key != name {
            renames.push((k.clone(), Str::from(new_key)));
        }
    }
    for (old, new) in renames {
        let value = array.get(&old).cloned().unwrap_or(Value::Null);
        array.set(ArrayKey::normalize(new), value);
        array.remove(&old);
    }
    let take = |array: &mut Array, old: &str| -> Option<Value> {
        let k = key(old);
        let value = array.get(&k).cloned()?;
        array.remove(&k);
        Some(value)
    };
    if from == "_" {
        if array.get_str(storage::SEQUENCE).is_some_and(|v| !matches!(v, Value::Null)) {
            let value = take(&mut array, storage::SEQUENCE).unwrap_or(Value::Null);
            array.set(key(document::SEQUENCE), Value::Str(stringify(&value)));
        }
        if array.get_str(storage::UID).is_some_and(|v| !matches!(v, Value::Null)) {
            let value = take(&mut array, storage::UID).unwrap_or(Value::Null);
            array.set(key(document::ID), Value::Str(stringify(&value)));
        }
        if let Some(tenant) = take(&mut array, storage::TENANT) {
            let tenant = match tenant {
                Value::Int(_) | Value::Null => tenant,
                other => Value::Str(stringify(&other)),
            };
            array.set(key(document::TENANT), tenant);
        }
    } else if from == "$" {
        if array.get_str(document::ID).is_some_and(|v| !matches!(v, Value::Null)) {
            let value = take(&mut array, document::ID).unwrap_or(Value::Null);
            array.set(key(storage::UID), value);
        }
        if array.get_str(document::SEQUENCE).is_some_and(|v| !matches!(v, Value::Null)) {
            let value = take(&mut array, document::SEQUENCE).unwrap_or(Value::Null);
            array.set(key(storage::SEQUENCE), value);
        }
        if let Some(tenant) = take(&mut array, document::TENANT) {
            array.set(key(storage::TENANT), tenant);
        }
    }
    array
}

fn contains(haystack: &[u8], needle: &[u8]) -> bool {
    haystack.windows(needle.len()).any(|w| w == needle)
}

/// `str_replace($from, $to, $subject)` on bytes.
fn replace_bytes(subject: &[u8], from: &[u8], to: &[u8]) -> Vec<u8> {
    if from.is_empty() {
        return subject.to_vec();
    }
    let mut out = Vec::with_capacity(subject.len());
    let mut i = 0;
    while i < subject.len() {
        if subject[i..].starts_with(from) {
            out.extend_from_slice(to);
            i += from.len();
        } else {
            out.push(subject[i]);
            i += 1;
        }
    }
    out
}

/// `replaceInternalIdsKeys($array, '$', '_', $operators)`: `$` replaced by
/// `_` in every key but the operators, recursively.
pub(crate) fn replace_internal_ids_keys(array: &Array) -> Array {
    array
        .iter()
        .map(|(k, v)| {
            let k = match k {
                KeyRef::Str(s) if !OPERATORS.iter().any(|o| o.as_bytes() == s) => {
                    ArrayKey::normalize(Str::from(replace_bytes(s, b"$", b"_")))
                }
                other => other.to_owned(),
            };
            let v = match v {
                Value::Array(a) => Value::Array(replace_internal_ids_keys(a)),
                other => other.clone(),
            };
            (k, v)
        })
        .collect()
}

/// `removeNullKeys($target)`: the top-level entries that are not null.
pub(crate) fn remove_null_keys(array: Array) -> Array {
    array.into_iter().filter(|(_, v)| !matches!(v, Value::Null)).collect()
}

/// `flattenWithDotNotation($key, $value)`: the leaves of nested maps as
/// dotted paths (lists are leaves), in the order PHP's stack reaches them.
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
            leaf => match result.iter_mut().find(|(p, _)| *p == path) {
                Some(entry) => entry.1 = leaf,
                None => result.push((path, leaf)),
            },
        }
    }
    result
}

/// A crate query of a nested query value.
fn nested(query: &Query) -> Vec<Query> {
    query.nested().cloned().map(Query::from).collect()
}

impl Mongo {
    /// `getQueryOperator($method)`.
    ///
    /// Fails ([`crate::Kind::Database`]) for a method that is not a filter.
    pub(crate) fn query_operator(method: Method) -> Result<&'static str> {
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
            other => return Err(Error::database(format!("Unknown operator: {}", other.as_str()))),
        })
    }

    /// `getQueryValue($method, $value)`: starts-with and ends-with as
    /// anchored patterns.
    fn query_value(method: Method, value: Value) -> Value {
        match method {
            Method::StartsWith => {
                let mut v = b"^".to_vec();
                v.extend_from_slice(&quote(scalar_string(&value).as_bytes()));
                Value::Str(Str::from(v))
            }
            Method::EndsWith => {
                let mut v = quote(scalar_string(&value).as_bytes());
                v.push(b'$');
                Value::Str(Str::from(v))
            }
            _ => value,
        }
    }

    /// `buildFilters($queries, $separator)`: the filter queries joined under
    /// `separator` (`$and`), nested ones recursively.
    pub(crate) fn build_filters(&self, queries: &[Query], separator: &str) -> Result<Array> {
        let mut filters = Array::new();
        let grouped = Query::group_by_type(queries)?;
        for query in grouped.filters {
            if query.is_nested() {
                let inner = nested(&query);
                if query.method() == Method::ElemMatch {
                    let built = self.build_filters(&inner, separator)?;
                    push(
                        &mut filters,
                        separator,
                        assoc([(query.attribute(), assoc([("$elemMatch", Value::Array(built))]))]),
                    );
                    continue;
                }
                let operator = Self::query_operator(query.method())?;
                push(&mut filters, separator, Value::Array(self.build_filters(&inner, operator)?));
            } else {
                push(&mut filters, separator, Value::Array(self.build_filter(query)?));
            }
        }
        Ok(filters)
    }

    /// `buildFilter($query)`: one query as a filter.
    pub(crate) fn build_filter(&self, mut query: Query) -> Result<Array> {
        let mut values = crate::value::from_query_array(query.values());
        if !self.supports(Capability::DefinedAttributes)
            || matches!(query.attribute(), document::CREATED_AT | document::UPDATED_AT)
        {
            values = values.map_values(|_, value| match value {
                Value::Str(s) if casting::is_extended_iso_datetime(s.as_bytes()) => {
                    casting::utc_datetime(s.as_bytes()).unwrap_or_else(|_| value.clone())
                }
                other => other.clone(),
            });
        }
        match query.attribute() {
            document::ID => {
                query.set_attribute(storage::UID);
            }
            document::SEQUENCE => {
                query.set_attribute(storage::SEQUENCE);
            }
            document::CREATED_AT => {
                query.set_attribute(storage::CREATED_AT);
            }
            document::UPDATED_AT => {
                query.set_attribute(storage::UPDATED_AT);
            }
            other if other.starts_with('$') => {
                let escaped = Self::escape_field_name(other);
                query.set_attribute(escaped);
            }
            _ => {}
        }
        let attribute = query.attribute().to_owned();
        let method = query.method();
        let operator = Self::query_operator(method)?;
        let value = match method {
            Method::IsNull | Method::IsNotNull => Value::Null,
            Method::Exists => Value::Bool(true),
            Method::NotExists => Value::Bool(false),
            _ => {
                let raw = if values.len() > 1 {
                    Value::Array(values.clone())
                } else {
                    values.get(&ArrayKey::Int(0)).cloned().unwrap_or(Value::Null)
                };
                Self::query_value(method, raw)
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
            Self::handle_object_filters(&query, &values, &mut filter);
            return Ok(filter);
        }
        let list = Value::Array(values.clone());
        if operator == "$eq" && matches!(value, Value::Array(_)) {
            filter.set(key(&attribute), assoc([("$in", value)]));
        } else if operator == "$ne" && matches!(value, Value::Array(_)) {
            filter.set(key(&attribute), assoc([("$nin", value)]));
        } else if operator == "$all" {
            filter.set(key(&attribute), assoc([("$all", list)]));
        } else if operator == "$in" {
            if matches!(method, Method::Contains | Method::ContainsAny) && !query.on_array() {
                if let Value::Array(items) = &value {
                    // array_map() keeps the keys.
                    let ors: Array = items
                        .iter()
                        .map(|(k, item)| {
                            let regex = safe_regex(scalar_string(item).as_bytes(), ".*%s.*", "i");
                            (k.to_owned(), assoc([(attribute.as_str(), assoc([("$regex", regex)]))]))
                        })
                        .collect();
                    filter.set(key("$or"), Value::Array(ors));
                } else {
                    filter.set(
                        key(&attribute),
                        assoc([("$regex", safe_regex(scalar_string(&value).as_bytes(), ".*%s.*", "i"))]),
                    );
                }
            } else {
                filter.set(key(&attribute), assoc([("$in", list)]));
            }
        } else if operator == "notContains" {
            if !query.on_array() {
                filter.set(
                    key(&attribute),
                    assoc([("$not", safe_regex(scalar_string(&value).as_bytes(), ".*%s.*", "i"))]),
                );
            } else {
                filter.set(key(&attribute), assoc([("$nin", list), ("$ne", Value::Null)]));
            }
        } else if operator == "$search" {
            if method == Method::NotSearch {
                if !empty(Some(&value)) {
                    filter.set(
                        key(&attribute),
                        assoc([("$not", safe_regex(scalar_string(&value).as_bytes(), ".*%s.*", "i"))]),
                    );
                }
            } else {
                filter.set(key("$text"), assoc([(operator, value)]));
            }
        } else if method == Method::Between {
            let (low, high) = bounds(&value);
            filter.set(key(&attribute), assoc([("$lte", high), ("$gte", low)]));
        } else if method == Method::NotBetween {
            let (low, high) = bounds(&value);
            let ors = vec![
                assoc([(attribute.as_str(), assoc([("$lt", low)]))]),
                assoc([(attribute.as_str(), assoc([("$gt", high)]))]),
            ];
            filter.set(key("$or"), Value::Array(Array::from_list(ors)));
        } else if operator == "$regex" && method == Method::NotStartsWith {
            filter.set(key(&attribute), assoc([("$not", safe_regex(scalar_string(&value).as_bytes(), "^%s", "i"))]));
        } else if operator == "$regex" && method == Method::NotEndsWith {
            filter.set(key(&attribute), assoc([("$not", safe_regex(scalar_string(&value).as_bytes(), "%s$", "i"))]));
        } else if operator == "$exists" {
            let ors: Vec<Value> = values
                .values()
                .map(|name| {
                    let mut condition = Array::new();
                    condition.set(ArrayKey::normalize(scalar_string(name)), assoc([(operator, value.clone())]));
                    Value::Array(condition)
                })
                .collect();
            filter.set(key("$or"), Value::Array(Array::from_list(ors)));
        } else {
            filter.set(key(&attribute), assoc([(operator, value)]));
        }
        Ok(filter)
    }

    /// `handleObjectFilters($query, $filter)`: equality and containment on
    /// an object attribute, as conditions on its flattened paths.
    fn handle_object_filters(query: &Query, values: &Array, filter: &mut Array) {
        let not = matches!(query.method(), Method::NotContains | Method::NotEqual);
        let mut conditions: Vec<Value> = Vec::new();
        for (k, value) in values.iter() {
            let name = match k {
                KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                KeyRef::Int(_) => String::new(),
            };
            let Some((path, leaf)) = flatten(&name, value.clone()).into_iter().next() else { continue };
            let field =
                if path.is_empty() { query.attribute().to_owned() } else { format!("{}.{path}", query.attribute()) };
            match query.method() {
                Method::Contains | Method::ContainsAny | Method::ContainsAll | Method::NotContains => {
                    let list = match leaf {
                        Value::Array(a) => Value::Array(a),
                        other => Value::Array(Array::from_list(vec![other])),
                    };
                    conditions.push(assoc([(field.as_str(), assoc([(if not { "$nin" } else { "$in" }, list)]))]));
                }
                Method::Equal | Method::NotEqual => {
                    let operator = match (&leaf, not) {
                        (Value::Array(_), true) => "$nin",
                        (Value::Array(_), false) => "$in",
                        (_, true) => "$ne",
                        (_, false) => "$eq",
                    };
                    conditions.push(assoc([(field.as_str(), assoc([(operator, leaf)]))]));
                }
                _ => {}
            }
        }
        let logical = if not { "$and" } else { "$or" };
        filter.set(key(logical), Value::Array(Array::from_list(conditions)));
    }

    /// `getEscapedAttributes($collection)`: the stored field name of each
    /// attribute whose key holds a dot or starts with `$`.
    pub(crate) fn escaped_attributes(collection: &Collection) -> Result<Vec<(String, String)>> {
        let mut escaped: Vec<(String, String)> = Vec::new();
        for attribute in collection.attributes()? {
            let k = &attribute.key;
            if k.contains('.') || k.starts_with('$') {
                let name = Self::escape_field_name(k);
                match escaped.iter_mut().find(|(a, _)| a == k) {
                    Some(entry) => entry.1 = name,
                    None => escaped.push((k.clone(), name)),
                }
            }
        }
        Ok(escaped)
    }

    /// `escapeQueryAttributes($collection, $queries)`: queries on attributes
    /// whose names hold dots or start with `$` address the escaped stored
    /// name (a nested object path keeps its dots), inside groups and
    /// `exists` too.
    pub(crate) fn escape_query_attributes(collection: &Collection, queries: &mut [Query]) -> Result<()> {
        let escaped = Self::escaped_attributes(collection)?;
        if escaped.is_empty() {
            return Ok(());
        }
        for query in queries.iter_mut() {
            escape_query(query, &escaped);
        }
        Ok(())
    }

    /// `getAttributeProjection($selections)`: the selected attributes (but
    /// the internal ones, always selected) and every internal field.
    ///
    /// Fails ([`crate::Kind::TypeError`]) for a selection PHP cannot use as
    /// an array key (`Cannot access offset of type array on array`).
    pub(crate) fn attribute_projection(selections: &[QValue]) -> Result<Array> {
        let internal: Vec<Value> = crate::database::internal_attributes(true).iter().map(|a| text(&a.key)).collect();
        let mut projection = Array::new();
        for selection in selections {
            let selection = crate::value::from_query(selection);
            // `in_array()`: loose comparison.
            if internal.iter().any(|i| i.loose_eq(&selection)) {
                continue;
            }
            let k = match &selection {
                Value::Int(i) => ArrayKey::Int(*i),
                Value::Bool(b) => ArrayKey::Int(i64::from(*b)),
                Value::Float(f) => ArrayKey::Int(php_std::format::dval_to_lval(*f)),
                Value::Null => ArrayKey::Str(Str::EMPTY),
                Value::Str(s) => ArrayKey::normalize(s.clone()),
                other => {
                    return Err(crate::error::Error::new(
                        crate::error::Kind::TypeError,
                        format!("Cannot access offset of type {} on array", other.type_name()),
                    ));
                }
            };
            projection.set(k, Value::Int(1));
        }
        for field in [storage::UID, storage::SEQUENCE, storage::CREATED_AT, storage::UPDATED_AT, storage::PERMISSIONS] {
            projection.set(key(field), Value::Int(1));
        }
        Ok(projection)
    }

    /// `ensureRelationshipDefaults($collection, $document)`: `null` for the
    /// relationship attributes a document lacks where SQL would hold a
    /// column for them (MongoDB stores no null fields).
    pub(crate) fn ensure_relationship_defaults(collection: &Collection, document: &mut Document) -> Result<()> {
        for attribute in collection.attributes()? {
            let Some(relationship) = &attribute.relationship else { continue };
            if document.has(&attribute.key) {
                continue;
            }
            let parent = attribute.side == Some(RelationshipSide::Parent);
            let stores = match relationship.kind {
                RelationshipType::OneToOne => parent || relationship.two_way,
                RelationshipType::OneToMany => !parent,
                RelationshipType::ManyToOne => parent,
                RelationshipType::ManyToMany => false,
            };
            if stores {
                document.put(attribute.key.as_str(), Value::Null);
            }
        }
        Ok(())
    }
}

/// `escapeQueryFields()` of one query.
fn escape_query(query: &mut Query, escaped: &[(String, String)]) {
    let lookup = |name: &str| escaped.iter().find(|(k, _)| k == name).map(|(_, v)| v.clone());
    let method = query.method();
    if method == Method::And || method == Method::Or {
        let values = query.values().map_values(|_, value| match value {
            QValue::Ext(Object::Query(inner)) => {
                let mut child = Query::from(inner.as_ref().clone());
                escape_query(&mut child, escaped);
                QValue::Ext(Object::Query(Box::new(child.into_inner())))
            }
            other => other.clone(),
        });
        query.set_values(values);
        return;
    }
    if method == Method::Exists || method == Method::NotExists {
        let values = query.values().map_values(|_, value| match value {
            QValue::Str(s) => match s.as_str().and_then(lookup) {
                Some(name) => QValue::Str(Str::from(name)),
                None => value.clone(),
            },
            other => other.clone(),
        });
        query.set_values(values);
        return;
    }
    if let Some(name) = lookup(query.attribute()) {
        query.set_attribute(name);
    }
}

/// `$value[0] ?? null` and `$value[1] ?? null` of a between query's value
/// (`[]` when it is not an array).
fn bounds(value: &Value) -> (Value, Value) {
    match value {
        Value::Array(a) => (
            a.get(&ArrayKey::Int(0)).cloned().unwrap_or(Value::Null),
            a.get(&ArrayKey::Int(1)).cloned().unwrap_or(Value::Null),
        ),
        _ => (Value::Null, Value::Null),
    }
}

use super::casting;

#[cfg(test)]
mod tests {
    use super::*;

    /// PHP 8.3.6 Adapter/Mongo.php:3803 (`$projection[$selection] = 1`): a
    /// selection that is an array is no array key, a TypeError.
    #[test]
    fn a_selection_that_is_an_array_is_a_type_error() {
        let mut selection = utopia_query::Array::new();
        selection.set(php_std::ArrayKey::from("s"), QValue::Str("".into()));
        let error = Mongo::attribute_projection(&[QValue::Array(selection)]).unwrap_err();
        assert_eq!(
            (error.kind(), error.message()),
            (crate::error::Kind::TypeError, "Cannot access offset of type array on array")
        );
        assert!(Mongo::attribute_projection(&[QValue::Str("name".into())]).unwrap().get_str("name").is_some());
    }
}
