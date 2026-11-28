//! The PHP array reads the adapters make on decoded JSON (`isset`,
//! `is_array`, `is_string`, `is_int`), and the request payloads they build.

use php_std::json::{self, Flags};
use php_std::types::{ArrayKey, KeyRef};
use php_std::{Array, Str, Value};

/// `isset($array[$key])`: the value at a string key of an array, `None`
/// when `value` is not an array, the key is missing or the value is null.
pub(crate) fn get<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    match value {
        Value::Array(a) => a.get_ref(KeyRef::normalize(key.as_bytes())).filter(|v| !matches!(v, Value::Null)),
        _ => None,
    }
}

/// `isset($array[$index])` for an integer index.
pub(crate) fn at(value: &Value, index: i64) -> Option<&Value> {
    match value {
        Value::Array(a) => a.get_ref(KeyRef::Int(index)).filter(|v| !matches!(v, Value::Null)),
        _ => None,
    }
}

/// `isset($v[$key]) && is_array($v[$key]) ? $v[$key] : []`.
pub(crate) fn array<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    get(value, key).filter(|v| matches!(v, Value::Array(_)))
}

/// `isset($v[$key]) && is_string($v[$key])`.
pub(crate) fn string<'a>(value: &'a Value, key: &str) -> Option<&'a [u8]> {
    match get(value, key) {
        Some(Value::Str(s)) => Some(s.as_bytes()),
        _ => None,
    }
}

/// `isset($v[$key]) && is_int($v[$key])`.
pub(crate) fn int(value: &Value, key: &str) -> Option<i64> {
    match get(value, key) {
        Some(Value::Int(i)) => Some(*i),
        _ => None,
    }
}

/// `(string) $value` of a scalar (`is_scalar`); `None` for anything else.
pub(crate) fn scalar_string(value: &Value) -> Option<Vec<u8>> {
    Some(match value {
        Value::Bool(true) => b"1".to_vec(),
        Value::Bool(false) => Vec::new(),
        Value::Int(i) => i.to_string().into_bytes(),
        Value::Float(f) => php_std::number::to_string(*f).into_bytes(),
        Value::Str(s) => s.as_bytes().to_vec(),
        _ => return None,
    })
}

/// `json_decode($json, true)`, `None` where PHP gets `null` (invalid JSON
/// included).
pub(crate) fn decode(json: &[u8]) -> Value {
    json::decode(json, Some(true), json::DEFAULT_DEPTH, Flags::NONE).unwrap_or(Value::Null)
}

/// `Adapter::decodeJsonObject()`: a JSON object with only string keys,
/// decoded as an array.
pub(crate) fn decode_object(json: &[u8]) -> Option<Value> {
    // Only `{` starts a document that decodes to an array that is not a
    // list: skip the work for the SSE lines and fragments that cannot.
    let first = json.iter().find(|b| !matches!(b, b' ' | b'\t' | b'\n' | b'\r'))?;
    if *first != b'{' {
        return None;
    }
    let decoded = decode(json);
    match &decoded {
        Value::Array(a) if !a.is_list() && a.keys().all(|k| matches!(k, KeyRef::Str(_))) => Some(decoded),
        _ => None,
    }
}

/// The text of an array key: an integer key in decimal.
pub(crate) fn key_string(key: KeyRef<'_>) -> String {
    match key {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// `[$key => $value, ...]` with string keys (normalised as PHP does).
pub(crate) fn map<const N: usize>(entries: [(&str, Value); N]) -> Value {
    let mut a = Array::with_capacity(N);
    for (k, v) in entries {
        a.set(ArrayKey::from(k), v);
    }
    Value::Array(a)
}

/// Sets `$array[$key] = $value` on an array value.
pub(crate) fn set(target: &mut Value, key: &str, value: Value) {
    if let Value::Array(a) = target {
        a.set(ArrayKey::from(key), value);
    }
}

/// A PHP string of bytes.
pub(crate) fn bytes(b: &[u8]) -> Value {
    Value::Str(Str::from(b))
}

/// A list of values.
pub(crate) fn list(values: Vec<Value>) -> Value {
    Value::Array(Array::from_list(values))
}
