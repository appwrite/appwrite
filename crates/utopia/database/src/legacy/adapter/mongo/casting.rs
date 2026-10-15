//! Values to and from what MongoDB stores: dates as `UTCDateTime`,
//! objects as embedded documents, integers as integers.
//!
//! PHP: `Mongo::castingBefore`, `castingAfter`, `setUTCDatetime`,
//! `convertUTCDateToString`, `convertStdClassToArray`.

use php_std::datetime::{DateTime, TzInfo};

use super::{Mongo, bson};
use crate::adapter::Adapter;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::operator::Operator;
use crate::value::{Array, ArrayKey, BsonValue, Ext, Str, Value};

/// `new UTCDateTime(new \DateTime($value))`: the date's milliseconds.
pub(super) fn utc_datetime(value: &[u8]) -> Result<Value> {
    let date = DateTime::new(value, None, &TzInfo::utc())
        .map_err(|e| Error::new(Kind::from_php_class(e.php_class()).unwrap_or(Kind::Exception), e.message()))?;
    Ok(Value::from(BsonValue::UtcDateTime(date.timestamp() * 1000 + date.microsecond() / 1000)))
}

/// `UTCDateTime::toDateTime()` formatted by `DateTime::format()`
/// (`Y-m-d H:i:s.v`, UTC).
pub(super) fn format_millis(ms: i64) -> String {
    let sec = ms.div_euclid(1000);
    let usec = ms.rem_euclid(1000) * 1000;
    match DateTime::new(format!("@{sec}.{usec:06}").as_bytes(), None, &TzInfo::utc()) {
        Ok(date) => crate::datetime::format(&date),
        Err(_) => String::new(),
    }
}

/// `convertUTCDateToString($node)`: a `UTCDateTime` (or its extended JSON
/// array form) as a database date string; anything else as it is.
pub(super) fn date_to_string(node: Value) -> Value {
    match node {
        Value::Ext(Ext::Bson(b)) => match *b {
            BsonValue::UtcDateTime(ms) => Value::Str(Str::from(format_millis(ms))),
            other => Value::from(other),
        },
        Value::Array(ref a) => {
            let Some(Value::Array(date)) = a.get_str("$date") else { return node };
            let Some(long) = date.get_str("$numberLong") else { return node };
            let ms = php_std::format::Arg::Str(long.to_php_string().unwrap_or(Str::EMPTY).as_bytes()).to_int();
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

/// `(int) $value`.
fn int(value: &Value) -> i64 {
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

/// An attribute's `$id`, `type` and `array` (attributes are documents or
/// arrays).
fn attribute_fields(attribute: &Value) -> (String, String, bool) {
    let get = |key: &str| -> Option<Value> {
        match attribute {
            Value::Ext(Ext::Document(d)) => d.get(key).cloned(),
            Value::Array(a) => a.get_str(key).cloned(),
            _ => None,
        }
    };
    let text = |v: Option<Value>| v.map(|v| crate::value::to_text(&v)).unwrap_or_default();
    (text(get("$id")), text(get("type")), get("array").is_some_and(|v| v.truthy()))
}

/// The collection's attributes followed by the internal ones.
fn attributes(collection: &Document) -> Vec<Value> {
    let mut out: Vec<Value> = match collection.get("attributes") {
        Some(Value::Array(a)) => a.values().cloned().collect(),
        _ => Vec::new(),
    };
    out.extend(Database::internal_attributes_const().into_iter().map(Value::from));
    out
}

/// `json_decode($value, true)` of an array attribute stored as a string,
/// or PHP's error for one that is not JSON.
fn decode_array(key: &str, value: Value) -> Result<Value> {
    let Value::Str(s) = &value else { return Ok(value) };
    php_std::json::decode_ext(s.as_bytes(), Some(true), 512, php_std::json::Flags::NONE)
        .map_err(|e| Error::database(format!("Failed to decode JSON for attribute {key}: {}", e.message())))
}

impl Mongo {
    /// `castingAfter($collection, $document)`: integers as integers, dates
    /// as strings, objects as arrays; in schemaless mode every top-level
    /// object and date too.
    pub(super) fn casting_after_impl(&self, collection: &Document, mut document: Document) -> Result<Document> {
        if document.is_empty() {
            return Ok(document);
        }
        for attribute in attributes(collection) {
            let (key, kind, array) = attribute_fields(&attribute);
            let Some(value) = document.get(&key).cloned() else { continue };
            if super::null(&value) || Operator::is_operator(&value) {
                continue;
            }
            let value = if array { decode_array(&key, value)? } else { Value::Array(Array::from_list(vec![value])) };
            let Value::Array(nodes) = value else {
                document.set(key.as_str(), value);
                continue;
            };
            let cast: Array = nodes
                .into_iter()
                .map(|(k, node)| {
                    let node = match kind.as_str() {
                        Database::VAR_INTEGER | Database::VAR_BIGINT => Value::Int(int(&node)),
                        Database::VAR_DATETIME => date_to_string(node),
                        Database::VAR_OBJECT => match node {
                            Value::Object(_) => bson::std_class_to_array(node),
                            other => other,
                        },
                        _ => node,
                    };
                    (k, node)
                })
                .collect();
            let value = if array { Value::Array(cast) } else { cast.values().next().cloned().unwrap_or(Value::Null) };
            document.set(key.as_str(), value);
        }
        if !self.support_for_attributes() {
            let copy = document.as_array().clone();
            for (key, value) in copy.iter() {
                let key = key.to_owned();
                match value {
                    Value::Object(_) => {
                        document.as_array_mut().set(key, bson::std_class_to_array(value.clone()));
                    }
                    Value::Ext(Ext::Bson(b)) if matches!(**b, BsonValue::UtcDateTime(_)) => {
                        document.as_array_mut().set(key, date_to_string(value.clone()));
                    }
                    _ => {}
                }
            }
        }
        Ok(document)
    }

    /// `castingBefore($collection, $document)`: dates as `UTCDateTime`,
    /// objects decoded from their JSON; in schemaless mode every ISO 8601
    /// date string too.
    pub(super) fn casting_before_impl(&self, collection: &Document, mut document: Document) -> Result<Document> {
        if document.is_empty() {
            return Ok(document);
        }
        for attribute in attributes(collection) {
            let (key, kind, array) = attribute_fields(&attribute);
            let Some(value) = document.get(&key).cloned() else { continue };
            if super::null(&value) || Operator::is_operator(&value) {
                continue;
            }
            let value = if array { decode_array(&key, value)? } else { Value::Array(Array::from_list(vec![value])) };
            let Value::Array(nodes) = value else {
                document.set(key.as_str(), value);
                continue;
            };
            let mut cast = Array::new();
            for (k, node) in nodes {
                let node = match kind.as_str() {
                    Database::VAR_DATETIME => match node {
                        Value::Ext(Ext::Bson(ref b)) if matches!(**b, BsonValue::UtcDateTime(_)) => node,
                        other => {
                            let text = other.to_php_string().map_err(Error::from)?;
                            utc_datetime(text.as_bytes()).map_err(|e| {
                                Error::new(
                                    Kind::Structure,
                                    format!("Invalid datetime value for attribute \"{key}\": {}", e.message()),
                                )
                            })?
                        }
                    },
                    Database::VAR_OBJECT => match &node {
                        Value::Str(s) => {
                            php_std::json::decode_ext(s.as_bytes(), Some(false), 512, php_std::json::Flags::NONE)
                                .unwrap_or(Value::Null)
                        }
                        other => {
                            return Err(Error::new(
                                Kind::TypeError,
                                format!(
                                    "json_decode(): Argument #1 ($json) must be of type string, {} given",
                                    other.type_name()
                                ),
                            ));
                        }
                    },
                    _ => node,
                };
                cast.set(k, node);
            }
            let value = if array { Value::Array(cast) } else { cast.values().next().cloned().unwrap_or(Value::Null) };
            document.set(key.as_str(), value);
        }
        // PHP filters the collection's indexes for TTL ones (the result is
        // not used further): a collection without indexes fails there.
        match collection.get("indexes") {
            Some(Value::Array(_)) => {}
            other => {
                let given = other.map_or("null", |v| v.type_name());
                return Err(Error::new(
                    Kind::TypeError,
                    format!("array_filter(): Argument #1 ($array) must be of type array, {given} given"),
                ));
            }
        }
        if !self.support_for_attributes() {
            let copy = document.as_array().clone();
            for (key, value) in copy.iter() {
                let name = match key {
                    php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    php_std::types::KeyRef::Int(i) => i.to_string(),
                };
                if Database::INTERNAL_ATTRIBUTE_KEYS.contains(&Self::internal_key(&name)) {
                    continue;
                }
                if let Value::Str(s) = value
                    && Self::is_extended_iso_datetime(s.as_bytes())
                    && let Ok(date) = utc_datetime(s.as_bytes())
                {
                    document.as_array_mut().set(ArrayKey::normalize(Str::from(name.as_str())), date);
                }
            }
        }
        Ok(document)
    }
}
