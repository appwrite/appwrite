//! Reading values out of decoded API answers: lookups by key, and
//! conversions to strings, numbers and booleans.
//!
//! PHP: the way PHP reads arrays: `$a['k'] ?? ...`, `(string)`, `(float)`,
//! `(int)`, `empty()`.

use php_std::format::{Arg, floatval, str_to_int};
use php_std::value as pv;
use serde_json::Value;

/// `$value[$key]`, `None` when missing or `null` (what `??` and `isset` skip).
pub(crate) fn at<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    let found = match value {
        Value::Object(map) => map.get(key),
        Value::Array(list) => key.parse::<usize>().ok().and_then(|i| list.get(i)),
        _ => None,
    };
    found.filter(|v| !v.is_null())
}

/// `$value[$key]` through a path of keys.
pub(crate) fn path<'a>(value: &'a Value, keys: &[&str]) -> Option<&'a Value> {
    keys.iter().try_fold(value, |v, k| at(v, k))
}

fn arg(value: &Value) -> Arg<'_> {
    match value {
        Value::Null => Arg::Null,
        Value::Bool(b) => Arg::Bool(*b),
        Value::Number(n) => match pv::json_number(n) {
            pv::Number::Int(i) => Arg::Int(i),
            pv::Number::Float(f) => Arg::Float(f),
        },
        Value::String(s) => Arg::Str(s.as_bytes()),
        Value::Array(a) => Arg::Array(a.len()),
        Value::Object(o) => Arg::Array(o.len()),
    }
}

/// `(string) $value` (arrays are `"Array"`).
pub(crate) fn string(value: &Value) -> String {
    pv::to_string(value).unwrap_or_else(|| "Array".to_owned())
}

/// `(float) $value`.
pub(crate) fn float(value: &Value) -> f64 {
    floatval(arg(value))
}

/// `(int) $string`.
pub(crate) fn int(s: &str) -> i64 {
    str_to_int(s.as_bytes())
}

/// `empty($value)` (a missing value is empty).
pub(crate) fn empty(value: Option<&Value>) -> bool {
    value.is_none_or(pv::empty)
}

/// `(bool) $value`.
pub(crate) fn truthy(value: Option<&Value>) -> bool {
    value.is_some_and(pv::truthy)
}
