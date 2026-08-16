//! PHP-compatible comparison helpers.

use serde_json::Value;

/// PHP `is_numeric()` for JSON values (numbers and numeric strings).
pub(crate) fn php_is_numeric(value: &Value) -> bool {
    match value {
        Value::Number(_) => true,
        Value::String(s) => php_is_numeric_str(s),
        _ => false,
    }
}

/// PHP `is_numeric` for strings: optional leading whitespace, optional sign,
/// digits with optional decimal and exponent. Trailing whitespace is rejected.
pub(crate) fn php_is_numeric_str(s: &str) -> bool {
    let s = trim_leading_php_whitespace(s);
    if s.is_empty() {
        return false;
    }

    let mut rest = s;
    if rest.starts_with('+') || rest.starts_with('-') {
        rest = &rest[1..];
    }
    if rest.is_empty() {
        return false;
    }

    let mut chars = rest.chars().peekable();
    let mut has_digit = false;

    while matches!(chars.peek(), Some('0'..='9')) {
        has_digit = true;
        chars.next();
    }
    if matches!(chars.peek(), Some('.')) {
        chars.next();
        while matches!(chars.peek(), Some('0'..='9')) {
            has_digit = true;
            chars.next();
        }
    }
    if !has_digit {
        return false;
    }
    if matches!(chars.peek(), Some('e' | 'E')) {
        chars.next();
        if matches!(chars.peek(), Some('+' | '-')) {
            chars.next();
        }
        let mut exp_digit = false;
        while matches!(chars.peek(), Some('0'..='9')) {
            exp_digit = true;
            chars.next();
        }
        if !exp_digit {
            return false;
        }
    }
    chars.peek().is_none()
}

fn trim_leading_php_whitespace(s: &str) -> &str {
    s.trim_start_matches([' ', '\t', '\n', '\r', '\u{0b}', '\u{0c}'])
}

/// Convert a numeric JSON value the way PHP would for `<=>`.
fn php_to_f64(value: &Value) -> Option<f64> {
    match value {
        Value::Number(n) => n.as_f64(),
        Value::String(s) if php_is_numeric_str(s) => {
            trim_leading_php_whitespace(s).parse::<f64>().ok()
        }
        _ => None,
    }
}

/// PHP spaceship `<=>` used by relational / range operators.
///
/// Returns `None` when the pair is incomparable (mixed types, one side null).
pub(crate) fn php_compare(left: &Value, right: &Value) -> Option<i8> {
    if left.is_null() && right.is_null() {
        return Some(0);
    }
    if left.is_null() || right.is_null() {
        return None;
    }

    if php_is_numeric(left) && php_is_numeric(right) {
        let l = php_to_f64(left)?;
        let r = php_to_f64(right)?;
        return Some(cmp_f64(l, r));
    }

    match (left, right) {
        (Value::String(l), Value::String(r)) => Some(cmp_ord(l.as_str().cmp(r.as_str()))),
        (Value::Bool(l), Value::Bool(r)) => Some(cmp_ord(l.cmp(r))),
        _ => None,
    }
}

fn cmp_f64(left: f64, right: f64) -> i8 {
    if left < right {
        -1
    } else {
        i8::from(left > right)
    }
}

fn cmp_ord(ord: std::cmp::Ordering) -> i8 {
    match ord {
        std::cmp::Ordering::Less => -1,
        std::cmp::Ordering::Equal => 0,
        std::cmp::Ordering::Greater => 1,
    }
}

/// PHP `===` for JSON values (no type juggling).
pub(crate) fn php_strict_eq(left: &Value, right: &Value) -> bool {
    match (left, right) {
        (Value::Null, Value::Null) => true,
        (Value::Bool(a), Value::Bool(b)) => a == b,
        (Value::Number(a), Value::Number(b)) => a == b,
        (Value::String(a), Value::String(b)) => a == b,
        (Value::Array(a), Value::Array(b)) => {
            a.len() == b.len() && a.iter().zip(b).all(|(x, y)| php_strict_eq(x, y))
        }
        (Value::Object(a), Value::Object(b)) => {
            a.len() == b.len()
                && a.iter()
                    .all(|(k, v)| b.get(k).is_some_and(|w| php_strict_eq(v, w)))
        }
        _ => false,
    }
}

/// PHP `(string)` cast for scalars used by array `contains`.
pub(crate) fn php_stringify_scalar(value: &Value) -> Option<String> {
    match value {
        Value::String(s) => Some(s.clone()),
        Value::Number(n) => {
            if let Some(i) = n.as_i64() {
                Some(i.to_string())
            } else if let Some(u) = n.as_u64() {
                Some(u.to_string())
            } else {
                n.as_f64().map(php_float_to_string)
            }
        }
        Value::Bool(true) => Some("1".into()),
        Value::Bool(false) => Some(String::new()),
        _ => None,
    }
}

fn php_float_to_string(value: f64) -> String {
    if value.fract() == 0.0 && value.is_finite() {
        format!("{value:.0}")
    } else {
        value.to_string()
    }
}

/// PHP `is_scalar` for JSON (bool / int / float / string).
pub(crate) fn php_is_scalar(value: &Value) -> bool {
    matches!(value, Value::Bool(_) | Value::Number(_) | Value::String(_))
}
