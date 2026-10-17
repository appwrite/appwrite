//! Reading and writing the decoded JSON documents of the JetStream API the
//! way PHP reads them from `json_decode(..., true)` arrays.

use indexmap::IndexMap;
use serde_json::{Map, Value};

/// `$data[$key] ?? null`.
pub(crate) fn get<'a>(data: &'a Value, key: &str) -> Option<&'a Value> {
    data.get(key).filter(|v| !v.is_null())
}

/// `$data[$key] ?? $default` for a string field.
pub(crate) fn string(data: &Value, key: &str, default: &str) -> String {
    get(data, key).and_then(Value::as_str).unwrap_or(default).to_owned()
}

/// `$data[$key] ?? null` for a string field.
pub(crate) fn opt_string(data: &Value, key: &str) -> Option<String> {
    get(data, key).and_then(Value::as_str).map(str::to_owned)
}

/// `$data[$key] ?? $default` for an integer field.
pub(crate) fn int(data: &Value, key: &str, default: i64) -> i64 {
    get(data, key).and_then(Value::as_i64).unwrap_or(default)
}

/// `$data[$key] ?? null` for an integer field.
pub(crate) fn opt_int(data: &Value, key: &str) -> Option<i64> {
    get(data, key).and_then(Value::as_i64)
}

/// `$data[$key] ?? false`.
pub(crate) fn flag(data: &Value, key: &str) -> bool {
    get(data, key).and_then(Value::as_bool).unwrap_or(false)
}

/// `$data[$key] ?? null` for a list of strings.
pub(crate) fn opt_strings(data: &Value, key: &str) -> Option<Vec<String>> {
    get(data, key).map(strings)
}

pub(crate) fn strings(value: &Value) -> Vec<String> {
    match value {
        Value::Array(items) => items.iter().filter_map(Value::as_str).map(str::to_owned).collect(),
        Value::Object(items) => items.values().filter_map(Value::as_str).map(str::to_owned).collect(),
        _ => Vec::new(),
    }
}

/// `$data[$key] ?? null` for a string map (metadata).
pub(crate) fn opt_map(data: &Value, key: &str) -> Option<IndexMap<String, String>> {
    get(data, key).map(|value| match value {
        Value::Object(items) => {
            items.iter().filter_map(|(k, v)| v.as_str().map(|v| (k.clone(), v.to_owned()))).collect()
        }
        _ => IndexMap::new(),
    })
}

/// A string map as PHP encodes an array: `[]` when empty.
pub(crate) fn map_value(map: &IndexMap<String, String>) -> Value {
    if map.is_empty() {
        return Value::Array(Vec::new());
    }
    Value::Object(map.iter().map(|(k, v)| (k.clone(), Value::String(v.clone()))).collect())
}

/// A list of strings as a JSON array.
pub(crate) fn strings_value(items: &[String]) -> Value {
    Value::Array(items.iter().cloned().map(Value::String).collect())
}

/// Builds a JSON object in insertion order.
pub(crate) struct Object(pub Map<String, Value>);

impl Object {
    pub fn new() -> Self {
        Object(Map::new())
    }

    pub fn set(&mut self, key: &str, value: impl Into<Value>) -> &mut Self {
        self.0.insert(key.to_owned(), value.into());
        self
    }

    pub fn opt(&mut self, key: &str, value: Option<impl Into<Value>>) -> &mut Self {
        if let Some(value) = value {
            self.0.insert(key.to_owned(), value.into());
        }
        self
    }

    pub fn build(&mut self) -> Value {
        Value::Object(std::mem::take(&mut self.0))
    }
}

/// `(string) $value` for a decoded JSON scalar.
pub(crate) fn php_string(value: &Value) -> String {
    match value {
        Value::Null | Value::Bool(false) => String::new(),
        Value::Bool(true) => "1".into(),
        Value::Number(n) => match n.as_i64() {
            Some(i) => i.to_string(),
            None => php_std::number::to_string(n.as_f64().unwrap_or(0.0)),
        },
        Value::String(s) => s.clone(),
        Value::Array(_) | Value::Object(_) => "Array".into(),
    }
}

/// `(int) $value` for a decoded JSON scalar.
pub(crate) fn php_int(value: &Value) -> i64 {
    match value {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Number(n) => match n.as_i64() {
            Some(i) => i,
            None => php_std::format::dval_to_lval(n.as_f64().unwrap_or(0.0)),
        },
        Value::String(s) => php_std::format::str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(o) => i64::from(!o.is_empty()),
    }
}

/// `(bool) $value` for a decoded JSON value.
pub(crate) fn php_bool(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(b) => *b,
        Value::Number(n) => n.as_f64().is_some_and(|f| f != 0.0),
        Value::String(s) => !(s.is_empty() || s == "0"),
        Value::Array(a) => !a.is_empty(),
        Value::Object(o) => !o.is_empty(),
    }
}

/// Seconds to whole nanoseconds, truncated as PHP's `(int)` cast does.
pub(crate) fn seconds_to_nanos(seconds: f64) -> i64 {
    php_std::format::dval_to_lval(seconds * 1_000_000_000.0)
}

/// Nanoseconds to seconds, as PHP's `$nanos / 1_000_000_000` (exact when
/// the division is).
pub(crate) fn nanos_to_seconds(nanos: i64) -> f64 {
    if nanos % 1_000_000_000 == 0 { (nanos / 1_000_000_000) as f64 } else { nanos as f64 / 1_000_000_000.0 }
}
