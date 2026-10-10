//! PHP value semantics (type juggling) on top of `serde_json::Value`.
//!
//! Request parameters reach PHP code as PHP values. These functions answer
//! the questions libraries ask about them (`is_array`, `is_numeric`,
//! truthiness, string casts, `==`) the way the engine does.

use std::cmp::Ordering;

use serde_json::{Map, Value};

use crate::number;

/// PHP `is_array`: JSON arrays and *non-empty* objects.
///
/// Appwrite decodes request bodies so that empty objects (`{}`) stay
/// `stdClass` (not an array) while other objects become associative arrays.
pub fn is_array(value: &Value) -> bool {
    match value {
        Value::Array(_) => true,
        Value::Object(map) => !map.is_empty(),
        _ => false,
    }
}

/// An empty JSON object: a `stdClass` instance in PHP.
pub fn is_object(value: &Value) -> bool {
    matches!(value, Value::Object(map) if map.is_empty())
}

/// Number of elements of a PHP array value.
pub fn array_len(value: &Value) -> usize {
    match value {
        Value::Array(a) => a.len(),
        Value::Object(o) => o.len(),
        _ => 0,
    }
}

/// Iterates the elements of a PHP array value (list or map) in order.
pub fn array_values(value: &Value) -> Box<dyn Iterator<Item = &Value> + '_> {
    match value {
        Value::Array(a) => Box::new(a.iter()),
        Value::Object(o) => Box::new(o.values()),
        _ => Box::new(std::iter::empty()),
    }
}

/// A PHP number after `$value + 0`.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Number {
    Int(i64),
    Float(f64),
}

impl Number {
    pub fn as_f64(self) -> f64 {
        match self {
            Number::Int(i) => i as f64,
            Number::Float(f) => f,
        }
    }
}

/// A JSON number as PHP sees it: integers beyond `i64` are floats.
pub fn json_number(n: &serde_json::Number) -> Number {
    match n.as_i64() {
        Some(i) => Number::Int(i),
        None => Number::Float(n.as_f64().unwrap_or(0.0)),
    }
}

/// PHP `is_numeric($v) ? $v + 0 : null`.
pub fn numeric(value: &Value) -> Option<Number> {
    match value {
        Value::Number(n) => Some(json_number(n)),
        Value::String(s) => numeric_str(s),
        _ => None,
    }
}

/// PHP numeric-string parsing (`is_numeric` + `+ 0`).
pub fn numeric_str(s: &str) -> Option<Number> {
    numeric_str_ex(s).map(|(n, _)| n)
}

/// Like [`numeric_str`], also reporting integer overflow as the engine's
/// `is_numeric_string_ex` does: `1` or `-1` when an integer-looking string
/// did not fit in `i64` and became a float.
///
/// Leading and trailing whitespace (space, `\t`, `\n`, `\r`, `\v`, `\f`) is
/// allowed; the body is an optional sign and a decimal integer or float with
/// an optional exponent. Hex, octal and binary literals are not numeric.
pub fn numeric_str_ex(s: &str) -> Option<(Number, i8)> {
    let is_ws = |c: char| matches!(c, ' ' | '\t' | '\n' | '\r' | '\x0B' | '\x0C');
    let t = s.trim_start_matches(is_ws).trim_end_matches(is_ws);
    if t.is_empty() {
        return None;
    }
    let bytes = t.as_bytes();
    let mut i = 0;
    if bytes[i] == b'+' || bytes[i] == b'-' {
        i += 1;
    }
    let int_start = i;
    while i < bytes.len() && bytes[i].is_ascii_digit() {
        i += 1;
    }
    let int_digits = i - int_start;
    let mut is_float = false;
    let mut frac_digits = 0;
    if i < bytes.len() && bytes[i] == b'.' {
        is_float = true;
        i += 1;
        let frac_start = i;
        while i < bytes.len() && bytes[i].is_ascii_digit() {
            i += 1;
        }
        frac_digits = i - frac_start;
    }
    if int_digits == 0 && frac_digits == 0 {
        return None;
    }
    if i < bytes.len() && (bytes[i] == b'e' || bytes[i] == b'E') {
        let mut j = i + 1;
        if j < bytes.len() && (bytes[j] == b'+' || bytes[j] == b'-') {
            j += 1;
        }
        let exp_start = j;
        while j < bytes.len() && bytes[j].is_ascii_digit() {
            j += 1;
        }
        if j == exp_start {
            return None;
        }
        is_float = true;
        i = j;
    }
    if i != bytes.len() {
        return None;
    }
    if !is_float {
        if let Ok(v) = t.parse::<i64>() {
            // The engine's overflow check `strcmp`s the 19 digits up to the
            // end of the string, trailing whitespace included: "-9223372036854775808 "
            // compares greater than "9223372036854775808" and becomes a float.
            let trailing_ws = t.len() != s.trim_start_matches(is_ws).len();
            if v == i64::MIN && trailing_ws {
                return Some((Number::Float(v as f64), -1));
            }
            return Some((Number::Int(v), 0));
        }
        let oflow = if bytes[0] == b'-' { -1 } else { 1 };
        return t.parse::<f64>().ok().map(|f| (Number::Float(f), oflow));
    }
    t.parse::<f64>().ok().map(|f| (Number::Float(f), 0))
}

/// PHP truthiness (`(bool) $value`).
pub fn truthy(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(b) => *b,
        Value::Number(n) => json_number(n).as_f64() != 0.0,
        Value::String(s) => !(s.is_empty() || s == "0"),
        Value::Array(a) => !a.is_empty(),
        // An empty object is a stdClass instance, which is truthy in PHP.
        Value::Object(_) => true,
    }
}

/// PHP `(string) $value` for scalars; `None` for arrays and objects.
pub fn to_string(value: &Value) -> Option<String> {
    match value {
        Value::Null | Value::Bool(false) => Some(String::new()),
        Value::Bool(true) => Some("1".to_owned()),
        Value::Number(n) => Some(match json_number(n) {
            Number::Int(i) => i.to_string(),
            Number::Float(f) => number::to_string(f),
        }),
        Value::String(s) => Some(s.clone()),
        _ => None,
    }
}

/// PHP `empty($value)`.
pub fn empty(value: &Value) -> bool {
    !truthy(value)
}

/// PHP 8 loose equality (`$a == $b`).
pub fn loose_eq(a: &Value, b: &Value) -> bool {
    use Value::*;
    match (a, b) {
        (Bool(_), _) | (_, Bool(_)) => truthy(a) == truthy(b),
        (Null, Null) => true,
        (Null, String(s)) | (String(s), Null) => s.is_empty(),
        (Null, other) | (other, Null) => !truthy(other),
        (Number(x), Number(y)) => num_eq(json_number(x), json_number(y)),
        (Number(n), String(s)) | (String(s), Number(n)) => number_eq_string(json_number(n), s),
        (String(x), String(y)) => smart_str_eq(x, y),
        (Object(x), Object(y)) if x.is_empty() || y.is_empty() => {
            // stdClass == stdClass compares properties; stdClass vs array is uncomparable.
            x.is_empty() && y.is_empty()
        }
        (Object(o), Array(_)) | (Array(_), Object(o)) if o.is_empty() => false,
        (Array(_) | Object(_), Array(_) | Object(_)) => arrays_eq(a, b),
        // A stdClass compared with a number is cast to 1 (with a notice).
        (Object(o), Number(n)) | (Number(n), Object(o)) if o.is_empty() => {
            num_eq(crate::value::Number::Int(1), json_number(n))
        }
        // Arrays are greater than any scalar; objects are uncomparable with strings.
        _ => false,
    }
}

fn num_eq(x: Number, y: Number) -> bool {
    match (x, y) {
        (Number::Int(p), Number::Int(q)) => p == q,
        _ => x.as_f64() == y.as_f64(),
    }
}

/// `compare_longs_to_string` / `compare_doubles_to_string`.
fn number_eq_string(n: Number, s: &str) -> bool {
    match numeric_str(s) {
        Some(m) => num_eq(n, m),
        None => {
            let as_string = match n {
                Number::Int(i) => i.to_string(),
                Number::Float(f) => number::to_string(f),
            };
            as_string == s
        }
    }
}

/// `zendi_smart_str_equals`.
fn smart_str_eq(a: &str, b: &str) -> bool {
    let (Some((x, ox)), Some((y, oy))) = (numeric_str_ex(a), numeric_str_ex(b)) else {
        return a == b;
    };
    if ox != 0 && ox == oy && x.as_f64() - y.as_f64() == 0.0 {
        // Both integers overflowed to the same side: a float comparison would be lossy.
        return a == b;
    }
    match (x, y) {
        (Number::Int(p), Number::Int(q)) => p == q,
        (Number::Int(_), Number::Float(_)) if oy != 0 => false,
        (Number::Float(_), Number::Int(_)) if ox != 0 => false,
        (Number::Float(p), Number::Float(q)) if p == q && !p.is_finite() => a == b,
        _ => x.as_f64() == y.as_f64(),
    }
}

/// The PHP array key a JSON object key or list index becomes.
fn entries(v: &Value) -> Vec<(String, &Value)> {
    match v {
        Value::Array(a) => a.iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect(),
        Value::Object(o) => o.iter().map(|(k, v)| (k.clone(), v)).collect(),
        _ => Vec::new(),
    }
}

/// `zend_compare_arrays`: same size, and every key of `a` is in `b` with a loosely equal value.
fn arrays_eq(a: &Value, b: &Value) -> bool {
    let (ea, eb) = (entries(a), entries(b));
    if ea.len() != eb.len() {
        return false;
    }
    let mb: Map<String, Value> = eb.iter().map(|(k, v)| (k.clone(), (*v).clone())).collect();
    ea.iter().all(|(k, v)| mb.get(k).is_some_and(|w| loose_eq(v, w)))
}

/// PHP 8 `<=>` for two numbers.
pub fn compare_numbers(x: Number, y: Number) -> Ordering {
    match (x, y) {
        (Number::Int(p), Number::Int(q)) => p.cmp(&q),
        _ => x.as_f64().partial_cmp(&y.as_f64()).unwrap_or(Ordering::Equal),
    }
}

/// PHP `$a == $b` for two strings (`zendi_smart_str_equals`): two numeric
/// strings compare as numbers, anything else byte for byte. Numeric strings
/// are ASCII, so a string that is not UTF-8 only equals itself.
pub fn loose_str_eq(a: &[u8], b: &[u8]) -> bool {
    match (std::str::from_utf8(a), std::str::from_utf8(b)) {
        (Ok(x), Ok(y)) => smart_str_eq(x, y),
        _ => a == b,
    }
}

/// PHP 8 `$a <= $b` for two numbers (`zend_is_smaller_or_equal`): integers
/// compare exactly; otherwise both are floats, `ZEND_THREEWAY_COMPARE` is
/// not below zero for `NAN`, so any comparison with `NAN` is false.
pub fn le_numbers(a: Number, b: Number) -> bool {
    match (a, b) {
        (Number::Int(p), Number::Int(q)) => p <= q,
        // `ZEND_THREEWAY_COMPARE(x, y) <= 0` is `x <= y`, false for NAN.
        _ => a.as_f64() <= b.as_f64(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn numeric_strings() {
        assert_eq!(numeric_str("8"), Some(Number::Int(8)));
        assert_eq!(numeric_str(" 8"), Some(Number::Int(8)));
        assert_eq!(numeric_str("8 "), Some(Number::Int(8)));
        assert_eq!(numeric_str("-1.5"), Some(Number::Float(-1.5)));
        assert_eq!(numeric_str(".5"), Some(Number::Float(0.5)));
        assert_eq!(numeric_str("1e3"), Some(Number::Float(1000.0)));
        assert_eq!(numeric_str("abc"), None);
        assert_eq!(numeric_str("0x1A"), None);
        assert_eq!(numeric_str(""), None);
        assert_eq!(numeric_str("."), None);
    }

    #[test]
    fn php_arrays() {
        assert!(is_array(&json!([])));
        assert!(is_array(&json!({"a": 1})));
        assert!(!is_array(&json!({})));
        assert!(!is_array(&json!("x")));
    }

    #[test]
    fn truthiness() {
        assert!(truthy(&json!("false")));
        assert!(!truthy(&json!("0")));
        assert!(!truthy(&json!("")));
        assert!(truthy(&json!({})));
        assert!(!truthy(&json!([])));
    }

    #[test]
    fn loose_equality_matches_php() {
        assert!(!loose_eq(&json!(1.5), &json!("1.5abc")));
        assert!(!loose_eq(&json!(0.30000000000000004), &json!("0.3")));
        assert!(!loose_eq(&json!("abc"), &json!(0)));
        assert!(!loose_eq(&json!(null), &json!("0")));
        assert!(loose_eq(&json!("1e3"), &json!("1000")));
        assert!(!loose_eq(&json!("9223372036854775808"), &json!("9223372036854775809")));
        assert!(loose_eq(&json!([]), &json!(false)));
        assert!(!loose_eq(&json!({}), &json!([])));
        assert!(loose_eq(&json!({}), &json!(1)));
        assert!(loose_eq(&json!({"0": 1}), &json!([1])));
    }

    #[test]
    fn strings_of_floats() {
        assert_eq!(to_string(&json!(1.0)).unwrap(), "1");
        assert_eq!(to_string(&json!(1e25)).unwrap(), "1.0E+25");
    }
}
