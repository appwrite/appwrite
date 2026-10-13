//! The engine conversions the library leans on (`(int)`, `(float)`,
//! `(string)`, `is_numeric`), on query values.

use php_std::Str;
use php_std::format::{dval_to_lval, str_to_float, str_to_int};
use utopia_query::Value;

/// `is_numeric($v)`.
pub(crate) fn is_numeric(value: &Value) -> bool {
    match value {
        Value::Int(_) | Value::Float(_) => true,
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes()).is_some(),
        _ => false,
    }
}

/// `is_numeric($v) ? (int) $v : 0`.
pub(crate) fn to_int(value: &Value) -> i64 {
    match value {
        Value::Int(i) => *i,
        Value::Float(f) => dval_to_lval(*f),
        Value::Str(s) if is_numeric(value) => str_to_int(s.as_bytes()),
        _ => 0,
    }
}

/// `is_numeric($v) ? (float) $v : 0.0`.
pub(crate) fn to_float(value: &Value) -> f64 {
    match value {
        Value::Int(i) => *i as f64,
        Value::Float(f) => *f,
        Value::Str(s) if is_numeric(value) => str_to_float(s.as_bytes()),
        _ => 0.0,
    }
}

/// `is_scalar($v) ? (string) $v : ''`.
pub(crate) fn to_str(value: &Value) -> Str {
    match value {
        Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => {
            value.to_php_string().unwrap_or(Str::EMPTY)
        }
        _ => Str::EMPTY,
    }
}

/// `(string) $v` of a scalar.
pub(crate) fn scalar_string(value: &Value) -> Option<Str> {
    match value {
        Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_) => value.to_php_string().ok(),
        _ => None,
    }
}

/// `new DateTime($text)` in UTC (Appwrite's default timezone).
pub(crate) fn datetime(text: &[u8]) -> Result<php_std::datetime::DateTime, php_std::datetime::Error> {
    php_std::datetime::DateTime::new(text, None, &php_std::datetime::TzInfo::utc())
}

/// `new DateTime($text, new DateTimeZone('UTC'))`.
pub(crate) fn datetime_utc(text: &[u8]) -> Result<php_std::datetime::DateTime, php_std::datetime::Error> {
    php_std::datetime::DateTime::new(text, Some(&php_std::datetime::TimeZone::utc()), &php_std::datetime::TzInfo::utc())
}

/// `$dt->format($format)` as a string.
pub(crate) fn format(dt: &php_std::datetime::DateTime, format: &str) -> String {
    String::from_utf8_lossy(&dt.format(format.as_bytes())).into_owned()
}

/// Lowercase hex of `n` random bytes (`bin2hex(random_bytes(n))`).
pub(crate) fn random_hex(n: usize) -> String {
    let mut bytes = vec![0u8; n];
    getrandom::fill(&mut bytes).unwrap_or_else(|e| panic!("the system random source failed: {e}"));
    hex::encode(bytes)
}
