//! Values to and from what MongoDB stores: dates as `UTCDateTime`, objects
//! as embedded documents, integers as integers.
//!
//! PHP: `Mongo::castBefore`, `castAfter`, `castRead`, `getReadCasts`,
//! `castDatetime`, `isExtendedIsoDatetime`, `convertUtcDateToString`,
//! `convertStdClassToArray`, `toMongoDatetime`.

use php_std::datetime::{DateTime, TzInfo};
use utopia_query::schema::ColumnType;

use super::{Mongo, date};
use crate::adapter::{Adapter, Casting};
use crate::capability::Capability;
use crate::collection::Collection;
use crate::database;
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::operator::Operator;
use crate::validator::big_int::BigInt;
use crate::value::{Array, BsonValue, Ext, KeyRef, Str, Value};

/// `new UTCDateTime(new \DateTime($value))`: the date's milliseconds.
pub(crate) fn utc_datetime(value: &[u8]) -> Result<Value> {
    let date = date(value)?;
    Ok(Value::from(BsonValue::UtcDateTime(date.timestamp() * 1000 + date.microsecond() / 1000)))
}

/// `UTCDateTime::toDateTime()` formatted by `DateTime::format()`
/// (`Y-m-d H:i:s.v`, UTC).
pub(crate) fn format_millis(ms: i64) -> String {
    let sec = ms.div_euclid(1000);
    let usec = ms.rem_euclid(1000) * 1000;
    match DateTime::new(format!("@{sec}.{usec:06}").as_bytes(), None, &TzInfo::utc()) {
        Ok(date) => crate::datetime::format(&date),
        Err(_) => String::new(),
    }
}

/// `isExtendedIsoDatetime($value)`: whether a string is an ISO 8601 date
/// and time with a zone (`Z` or `+hh:mm`) and an optional fraction
/// (`YYYY-MM-DDTHH:mm:ss[.f+](Z|+HH:MM)`).
pub(crate) fn is_extended_iso_datetime(value: &[u8]) -> bool {
    let len = value.len();
    if len < 20 {
        return false;
    }
    if value[4] != b'-' || value[7] != b'-' || value[10] != b'T' || value[13] != b':' || value[16] != b':' {
        return false;
    }
    let has_z = value[len - 1] == b'Z';
    let has_offset = len >= 25 && matches!(value[len - 6], b'+' | b'-') && value[len - 3] == b':';
    if !has_z && !has_offset {
        return false;
    }
    if has_offset && len > 31 {
        return false;
    }
    if has_z && len > 26 {
        return false;
    }
    let mut digits: Vec<usize> = vec![0, 1, 2, 3, 5, 6, 8, 9, 11, 12, 14, 15, 17, 18];
    let time_end = if has_z { len - 1 } else { len - 6 };
    if time_end > 19 {
        if value[19] != b'.' || time_end < 21 {
            return false;
        }
        digits.extend(20..time_end);
    }
    if has_offset {
        digits.extend([len - 5, len - 4, len - 2, len - 1]);
    }
    digits.iter().all(|i| value[*i].is_ascii_digit())
}

/// `convertUtcDateToString($node)`: a `UTCDateTime` (or its extended JSON
/// array form) as a database date string; anything else as it is.
pub(crate) fn date_to_string(node: Value) -> Value {
    match node {
        Value::Ext(Ext::Bson(b)) => match *b {
            BsonValue::UtcDateTime(ms) => Value::Str(Str::from(format_millis(ms))),
            other => Value::from(other),
        },
        Value::Array(ref a) => {
            let Some(Value::Array(date)) = a.get_str("$date") else { return node };
            let Some(long) = date.get_str("$numberLong").filter(|v| !matches!(v, Value::Null)) else { return node };
            let ms = match long {
                Value::Int(i) => *i,
                other if is_numeric(other) => int(other),
                _ => 0,
            };
            let seconds = ms / 1000;
            // str_pad() pads on the right.
            let mut micro = ((ms % 1000) * 1000).to_string();
            while micro.len() < 6 {
                micro.push('0');
            }
            let text = format!("{seconds}.{micro}");
            match DateTime::from_format(
                b"U.u",
                text.as_bytes(),
                None,
                &TzInfo::utc(),
                php_std::datetime::Timestamp::now(),
            ) {
                Ok(Ok(date)) => Value::Str(Str::from(crate::datetime::format(&date))),
                _ => node,
            }
        }
        other => other,
    }
}

/// `is_numeric($value)`.
pub(crate) fn is_numeric(value: &Value) -> bool {
    match value {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

/// `(int) $value` of a scalar.
pub(crate) fn int(value: &Value) -> i64 {
    match value {
        Value::Null => 0,
        Value::Bool(b) => i64::from(*b),
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::Arg::Float(*f).to_int(),
        Value::Str(s) => php_std::format::Arg::Str(s.as_bytes()).to_int(),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

/// `convertStdClassToArray($value)`: every non-empty `stdClass` in `value`
/// as an array (empty ones kept), recursively through arrays.
pub(crate) fn std_class_to_array(value: Value) -> Value {
    match value {
        Value::Object(a) if a.is_empty() => Value::Object(a),
        Value::Object(a) | Value::Array(a) => {
            Value::Array(a.into_iter().map(|(k, v)| (k, std_class_to_array(v))).collect())
        }
        other => other,
    }
}

/// `json_decode($value, true)` of an array attribute stored as a string,
/// or the database error for one that is not JSON.
fn decode_array(key: &str, value: &Value) -> Result<Value> {
    let Value::Str(s) = value else { return Ok(value.clone()) };
    php_std::json::decode_ext(s.as_bytes(), Some(true), 512, php_std::json::Flags::NONE)
        .map_err(|e| Error::database(format!("Failed to decode JSON for attribute {key}: {}", e.message())))
}

/// The nodes of a value: an array attribute's items (a JSON string
/// decoded, a scalar wrapped), any other's value alone.
fn nodes(key: &str, value: &Value, array: bool) -> Result<Array> {
    if !array {
        return Ok(Array::from_list(vec![value.clone()]));
    }
    Ok(match decode_array(key, value)? {
        Value::Array(a) => a,
        other => Array::from_list(vec![other]),
    })
}

/// `$value[0]` of the nodes of a single value.
fn first(nodes: Array) -> Value {
    nodes.into_iter().next().map_or(Value::Null, |(_, v)| v)
}

impl Mongo {
    /// `getReadCasts($collection)`: the key, type and array flag of every
    /// collection attribute, then of every internal one.
    fn read_casts(collection: &Collection) -> Result<Vec<(String, ColumnType, bool)>> {
        Ok(Self::attributes_with_internal(collection)?.into_iter().map(|a| (a.key, a.kind, a.array)).collect())
    }

    /// `castRead($casts, $defined, $document)`: stored values read back by
    /// their attribute's type; without defined attributes, every top-level
    /// object and date too.
    pub(crate) fn cast_read(
        casts: &[(String, ColumnType, bool)],
        defined: bool,
        mut document: Document,
    ) -> Result<Document> {
        if document.is_empty() {
            return Ok(document);
        }
        for (key, kind, array) in casts {
            let Some(stored) = document.get(key).cloned() else { continue };
            if Operator::is_operator(&stored) {
                continue;
            }
            let cast: Array = nodes(key, &stored, *array)?
                .into_iter()
                .map(|(k, node)| {
                    let node = match kind {
                        ColumnType::BigInteger | ColumnType::Integer => match node {
                            Value::Int(_) => node,
                            ref other if is_numeric(other) => Value::Int(int(other)),
                            _ => Value::Int(0),
                        },
                        ColumnType::String | ColumnType::Id => match node {
                            Value::Int(_) | Value::Float(_) | Value::Bool(_) => {
                                Value::Str(node.to_php_string().unwrap_or(Str::EMPTY))
                            }
                            other => other,
                        },
                        ColumnType::Float | ColumnType::Double => match node {
                            Value::Float(_) => node,
                            ref other if is_numeric(other) => Value::Float(other.to_double()),
                            _ => Value::Float(0.0),
                        },
                        ColumnType::Boolean => match node {
                            Value::Int(_) | Value::Float(_) | Value::Bool(_) | Value::Str(_) => {
                                Value::Bool(node.truthy())
                            }
                            other => other,
                        },
                        ColumnType::Datetime => date_to_string(node),
                        ColumnType::Object => match node {
                            Value::Object(_) => std_class_to_array(node),
                            other => other,
                        },
                        _ => node,
                    };
                    (k, node)
                })
                .collect();
            let value = if *array { Value::Array(cast) } else { first(cast) };
            if key == document::PERMISSIONS {
                document.set(key.as_str(), value)?;
            } else {
                document.put(key.as_str(), value);
            }
        }
        if !defined {
            let copy = document.as_array().clone();
            for (k, value) in copy.iter() {
                let converted = match value {
                    Value::Object(_) => std_class_to_array(value.clone()),
                    Value::Ext(Ext::Bson(b)) if matches!(**b, BsonValue::UtcDateTime(_)) => {
                        date_to_string(value.clone())
                    }
                    _ => continue,
                };
                document.as_array_mut().set(k.to_owned(), converted);
            }
        }
        Ok(document)
    }

    /// `castBefore($collection, $document)`: dates as `UTCDateTime`,
    /// objects decoded from their JSON, integer operator operands as
    /// integers; without defined attributes, every ISO 8601 date string too.
    ///
    /// Fails ([`Kind::Type`]) for an integer operand outside the signed
    /// 64-bit range, ([`Kind::Database`]) for an array attribute stored as
    /// a string that is not JSON, and as PHP's `DateTime` for a date it
    /// cannot read.
    pub(crate) fn cast_before_impl(&self, collection: &Collection, mut document: Document) -> Result<Document> {
        if document.is_empty() {
            return Ok(document);
        }
        for attribute in Self::attributes_with_internal(collection)? {
            let key = attribute.key.as_str();
            let Some(value) = document.get(key).cloned() else { continue };
            if let Value::Ext(Ext::Operator(mut operator)) = value {
                if attribute.is_integer() {
                    let mut values: Vec<Value> = operator.values().values().cloned().collect();
                    for operand in values.iter_mut() {
                        let Value::Str(s) = operand else { continue };
                        if !BigInt::is_integer_string(s.as_bytes(), true) {
                            continue;
                        }
                        if !BigInt::fits_php_int(s.as_bytes(), true) {
                            return Err(Error::new(
                                Kind::Type,
                                "MongoDB cannot safely apply an integer operator outside the signed 64-bit range.",
                            ));
                        }
                        *operand = Value::Int(php_std::format::str_to_int(s.as_bytes()));
                    }
                    operator.set_values(values);
                    document.put(key, Value::Ext(Ext::Operator(operator)));
                }
                continue;
            }
            let mut cast = Array::new();
            for (k, node) in nodes(key, &value, attribute.array)? {
                let node = match attribute.kind {
                    ColumnType::Datetime => match node {
                        Value::Ext(Ext::Bson(ref b)) if matches!(**b, BsonValue::UtcDateTime(_)) => node,
                        other => {
                            let text = match &other {
                                Value::Str(s) => s.clone(),
                                Value::Int(_) | Value::Float(_) | Value::Bool(_) => {
                                    other.to_php_string().unwrap_or(Str::EMPTY)
                                }
                                _ => Str::EMPTY,
                            };
                            let numeric = Value::Str(text.clone());
                            if is_numeric(&numeric) {
                                Value::from(BsonValue::UtcDateTime(int(&numeric)))
                            } else {
                                utc_datetime(text.as_bytes())?
                            }
                        }
                    },
                    ColumnType::Object => {
                        let text = match &node {
                            Value::Str(s) => s.clone(),
                            Value::Int(_) | Value::Float(_) | Value::Bool(_) => {
                                node.to_php_string().unwrap_or(Str::EMPTY)
                            }
                            _ => Str::EMPTY,
                        };
                        php_std::json::decode_ext(text.as_bytes(), Some(false), 512, php_std::json::Flags::NONE)
                            .unwrap_or(Value::Null)
                    }
                    _ => node,
                };
                cast.set(k, node);
            }
            let value = if attribute.array { Value::Array(cast) } else { first(cast) };
            document.set(key, value)?;
        }
        if !self.supports(Capability::DefinedAttributes) {
            let copy = document.as_array().clone();
            for (k, value) in copy.iter() {
                let name = super::filters::key_text(k);
                if database::INTERNAL_ATTRIBUTE_KEYS.contains(&Self::internal_key(&name)) {
                    continue;
                }
                if let Value::Str(s) = value
                    && is_extended_iso_datetime(s.as_bytes())
                    && let Ok(date) = utc_datetime(s.as_bytes())
                {
                    let k = match k {
                        KeyRef::Int(i) => crate::value::ArrayKey::Int(i),
                        KeyRef::Str(s) => crate::value::ArrayKey::Str(Str::from(s.to_vec())),
                    };
                    document.as_array_mut().set(k, date);
                }
            }
        }
        Ok(document)
    }
}

impl Casting for Mongo {
    fn cast_before(&self, collection: &Collection, document: Document) -> Result<Document> {
        self.cast_before_impl(collection, document)
    }

    /// `castAfter($collection, $documents)`: each document's stored values
    /// read back by their attribute's type.
    fn cast_after(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        let casts = Self::read_casts(collection)?;
        let defined = self.supports(Capability::DefinedAttributes);
        documents.into_iter().map(|d| Self::cast_read(&casts, defined, d)).collect()
    }

    /// `castDatetime($value)`: a `UTCDateTime` of the date.
    fn cast_datetime(&self, value: &str) -> Result<Value> {
        utc_datetime(value.as_bytes())
    }
}

/// The read casts of a collection, for `getDocument()`.
pub(crate) fn read_casts(collection: &Collection) -> Result<Vec<(String, ColumnType, bool)>> {
    Mongo::read_casts(collection)
}
