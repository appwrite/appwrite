//! How the adapters read provider payloads: PHP's array access, casts and
//! array functions over [`Value`]s decoded as `json_decode($json, true)`
//! decodes them.
//!
//! Provider responses and webhook payloads are untyped; the PHP adapters read
//! them with `$a['k'] ?? default`, `(string)`, `(int)`, `empty()` and a few
//! array functions, and whatever type a field holds flows through. These
//! helpers do exactly that, so a field of an unexpected type behaves as in
//! PHP (passed through, cast, or refused with PHP's `TypeError`).

use php_std::format::{dval_to_lval, str_to_int};
use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str, Value};

use crate::error::{Error, Result};

/// `json_decode($json, true)`: `None` where PHP returns `null` (invalid
/// JSON, or the JSON `null`).
pub(crate) fn json_decode(json: &[u8]) -> Option<Value> {
    match php_std::json::decode(json, Some(true), 512, php_std::json::Flags::NONE) {
        Ok(Value::Null) | Err(_) => None,
        Ok(v) => Some(v),
    }
}

/// `json_encode($value)`: `None` where PHP returns `false`.
pub(crate) fn json_encode(value: &Value) -> Option<String> {
    php_std::json::encode(value, php_std::json::Flags::NONE, 512).ok()
}

/// `$value[$key]` read under `isset` (the left side of `??`): the element,
/// or `Null` when it is missing (or `null`). Only arrays have string keys;
/// a string's offsets are integers.
pub(crate) fn at(value: &Value, key: &str) -> Value {
    match value {
        Value::Array(a) => match a.get_ref(KeyRef::normalize(key.as_bytes())) {
            Some(v) => v.clone(),
            None => Value::Null,
        },
        Value::Str(s) => match KeyRef::normalize(key.as_bytes()) {
            KeyRef::Int(i) => offset(s, i),
            KeyRef::Str(_) => Value::Null,
        },
        _ => Value::Null,
    }
}

/// `$value[$index]` read under `isset`.
pub(crate) fn at_index(value: &Value, index: i64) -> Value {
    match value {
        Value::Array(a) => a.get_ref(KeyRef::Int(index)).cloned().unwrap_or(Value::Null),
        Value::Str(s) => offset(s, index),
        _ => Value::Null,
    }
}

/// A string offset (negative ones count from the end), as a one-byte string.
fn offset(s: &Str, index: i64) -> Value {
    let len = s.len() as i64;
    let i = if index < 0 { index + len } else { index };
    if (0..len).contains(&i) { Value::Str(Str::copy_from(&s.as_bytes()[i as usize..=i as usize])) } else { Value::Null }
}

/// `$value ?? $default`.
pub(crate) fn or(value: Value, default: impl Into<Value>) -> Value {
    if matches!(value, Value::Null) { default.into() } else { value }
}

/// A path of string keys read under `isset`: `$v['a']['b'] ?? null`.
pub(crate) fn path(value: &Value, keys: &[&str]) -> Value {
    let mut current = value.clone();
    for key in keys {
        current = at(&current, key);
        if matches!(current, Value::Null) {
            break;
        }
    }
    current
}

/// `isset($value[$key])`.
pub(crate) fn isset(value: &Value, key: &str) -> bool {
    !matches!(at(value, key), Value::Null)
}

/// `empty($value)`.
pub(crate) fn empty(value: &Value) -> bool {
    !value.truthy()
}

/// `\is_array($value)`.
pub(crate) fn is_array(value: &Value) -> bool {
    matches!(value, Value::Array(_))
}

/// `(string) $value`, `\strval($value)` and string interpolation: arrays
/// are `"Array"` (PHP warns).
pub(crate) fn string(value: &Value) -> Str {
    value.to_php_string().unwrap_or(Str::from_static("Object"))
}

/// `(int) $value`.
pub(crate) fn int(value: &Value) -> i64 {
    match value {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Int(i) => *i,
        Value::Float(f) => dval_to_lval(*f),
        Value::Str(s) => str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

/// `$a === $b`.
pub(crate) fn identical(a: &Value, b: &Value) -> bool {
    a.strict_eq(b)
}

/// Whether `value` is the string `s` (`$value === 's'`).
pub(crate) fn is_str(value: &Value, s: &str) -> bool {
    matches!(value, Value::Str(v) if v.as_bytes() == s.as_bytes())
}

/// A string value.
pub(crate) fn s(text: impl AsRef<[u8]>) -> Value {
    Value::Str(Str::copy_from(text.as_ref()))
}

/// The name PHP gives a value's type in argument and return errors
/// (`zend_zval_value_name`).
pub(crate) fn value_name(value: &Value) -> &'static str {
    match value {
        Value::Null => "null",
        Value::Bool(true) => "true",
        Value::Bool(false) => "false",
        Value::Int(_) => "int",
        Value::Float(_) => "float",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) => "stdClass",
        Value::Ext(_) => "object",
    }
}

/// The `TypeError` an internal function throws for an argument that is not
/// an array: `array_keys(): Argument #1 ($array) must be of type array,
/// string given`.
pub(crate) fn not_array(function: &str, argument: &str, value: &Value) -> Error {
    Error::type_error(format!("{function}(): Argument {argument} must be of type array, {} given", value_name(value)))
}

/// The array an internal function takes as its argument, or its `TypeError`.
pub(crate) fn expect_array<'a>(value: &'a Value, function: &str, argument: &str) -> Result<&'a Array> {
    match value {
        Value::Array(a) => Ok(a),
        other => Err(not_array(function, argument, other)),
    }
}

/// `foreach ($value as $item)`: the elements of an array; anything else is
/// skipped (PHP warns).
pub(crate) fn each(value: &Value) -> Vec<Value> {
    match value {
        Value::Array(a) => a.values().cloned().collect(),
        _ => Vec::new(),
    }
}

/// A value returned from a function declared `: string`, in coercive mode:
/// scalars become strings, `null` and arrays are a `TypeError`.
pub(crate) fn return_string(value: Value, function: &str) -> Result<String> {
    match value {
        Value::Str(s) => Ok(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => Ok(String::from_utf8_lossy(&string(&value)).into_owned()),
        other => Err(Error::type_error(format!(
            "{function}(): Return value must be of type string, {} returned",
            value_name(&other)
        ))),
    }
}

/// A value returned from a function declared `: int`, in coercive mode:
/// integral numbers, numeric strings and booleans become integers.
pub(crate) fn return_int(value: Value, function: &str) -> Result<i64> {
    let refuse = |v: &Value| {
        Error::type_error(format!("{function}(): Return value must be of type int, {} returned", value_name(v)))
    };
    match &value {
        Value::Int(i) => Ok(*i),
        Value::Bool(b) => Ok(i64::from(*b)),
        Value::Float(f) if f.is_finite() && *f >= -9.223_372_036_854_776e18 && *f < 9.223_372_036_854_776e18 => {
            Ok(*f as i64)
        }
        Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some((php_std::value::Number::Int(i), 0)) => Ok(i),
            Some((php_std::value::Number::Float(f), 0))
                if f.is_finite() && (-9.223_372_036_854_776e18..9.223_372_036_854_776e18).contains(&f) =>
            {
                Ok(f as i64)
            }
            _ => Err(refuse(&value)),
        },
        _ => Err(refuse(&value)),
    }
}

/// The key `$array[$value] = ...` uses: `null` is `""`, booleans and floats
/// integers, numeric strings integers; arrays are refused.
pub(crate) fn write_key(value: &Value) -> Result<ArrayKey> {
    match value {
        Value::Null => Ok(ArrayKey::Str(Str::default())),
        Value::Bool(b) => Ok(ArrayKey::Int(i64::from(*b))),
        Value::Int(i) => Ok(ArrayKey::Int(*i)),
        Value::Float(f) => Ok(ArrayKey::Int(dval_to_lval(*f))),
        Value::Str(s) => Ok(ArrayKey::normalize(s.clone())),
        other => Err(Error::type_error(format!("Cannot access offset of type {} on array", type_name(other)))),
    }
}

/// The key `isset($array[$value])` looks up, or the `TypeError` for an
/// array key.
pub(crate) fn isset_key(value: &Value) -> Result<ArrayKey> {
    match value {
        Value::Array(_) | Value::Object(_) | Value::Ext(_) => {
            Err(Error::type_error(format!("Cannot access offset of type {} in isset or empty", type_name(value))))
        }
        other => write_key(other),
    }
}

/// `zend_zval_type_name`: the type in offset errors.
fn type_name(value: &Value) -> &'static str {
    match value {
        Value::Null => "null",
        Value::Bool(_) => "bool",
        Value::Int(_) => "int",
        Value::Float(_) => "float",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) => "stdClass",
        Value::Ext(_) => "object",
    }
}

/// `str_replace($search, $replace, $subject)` with string search and
/// replace: scalars are replaced as strings (`null` is `""`), arrays element
/// by element (a nested array is "Array", as PHP 8 converts it).
pub(crate) fn str_replace(search: &str, replace: &str, subject: &Value) -> Value {
    let one = |v: &Value| {
        let bytes = string(v);
        Value::Str(Str::from(
            php_std::string::str_replace(search.as_bytes(), replace.as_bytes(), &bytes).0.into_owned(),
        ))
    };
    match subject {
        Value::Array(a) => Value::Array(a.iter().map(|(k, v)| (k.to_owned(), one(v))).collect()),
        other => one(other),
    }
}

/// `array_keys($array)`.
pub(crate) fn array_keys(array: &Array) -> Vec<Value> {
    array
        .keys()
        .map(|k| match k {
            KeyRef::Int(i) => Value::Int(i),
            KeyRef::Str(s) => Value::Str(Str::copy_from(s)),
        })
        .collect()
}

/// `array_column($rows, $column)`: the column of every row that is an array
/// holding it (`null` included).
pub(crate) fn array_column(rows: &Value, column: &str) -> Result<Vec<Value>> {
    let rows = expect_array(rows, "array_column", "#1 ($array)")?;
    Ok(rows
        .values()
        .filter_map(|row| match row {
            Value::Array(a) => a.get_ref(KeyRef::normalize(column.as_bytes())).cloned(),
            _ => None,
        })
        .collect())
}

/// `array_merge($a, $b, ...)`: string keys overwrite in place, integer keys
/// are renumbered after the others. `function` names the arguments in a
/// `TypeError`.
pub(crate) fn array_merge(arrays: &[&Value]) -> Result<Array> {
    let mut out: Array = Array::new();
    for (i, value) in arrays.iter().enumerate() {
        let array = expect_array(value, "array_merge", &format!("#{}", i + 1))?;
        for (k, v) in array.iter() {
            match k {
                KeyRef::Int(_) => {
                    let _ = out.push(v.clone());
                }
                KeyRef::Str(s) => {
                    out.set(ArrayKey::Str(Str::copy_from(s)), v.clone());
                }
            }
        }
    }
    Ok(out)
}

/// `array_slice($list, $offset, $length)` for the non-negative offsets and
/// lengths the adapters use on lists (re-indexed). A negative offset counts
/// from the end, as in PHP.
pub(crate) fn slice(items: &[Value], offset: i64, length: i64) -> Vec<Value> {
    let len = items.len() as i64;
    let start = if offset < 0 { (len + offset).max(0) } else { offset.min(len) };
    let end = if length < 0 { (len + length).max(start) } else { start.saturating_add(length).min(len) };
    if start >= end { Vec::new() } else { items[start as usize..end as usize].to_vec() }
}

/// An array from pairs, assigned in order.
pub(crate) fn object<const N: usize>(pairs: [(&str, Value); N]) -> Value {
    Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)).collect())
}

/// A list.
pub(crate) fn list(items: Vec<Value>) -> Value {
    Value::Array(Array::from_list(items))
}

/// Sets `$array[$key] = $value` on an array value (a no-op on others, which
/// the adapters never write to).
pub(crate) fn set(target: &mut Value, key: &str, value: Value) {
    if let Value::Array(a) = target {
        a.set(ArrayKey::normalize(Str::from(key)), value);
    }
}

/// `strtolower()`.
pub(crate) fn lower(s: &[u8]) -> Vec<u8> {
    php_std::string::strtolower(s).into_owned()
}

/// `str_contains()` on bytes.
pub(crate) fn contains(haystack: &[u8], needle: &[u8]) -> bool {
    php_std::string::str_contains(haystack, needle)
}

/// Text from bytes (lossy where PHP keeps the bytes; only used where the
/// bytes come from UTF-8 inputs or ASCII).
pub(crate) fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

/// `$value !== '' && $value !== '0'`: a string PHP would not take as unset.
pub(crate) fn filled(s: &str) -> bool {
    !s.is_empty() && s != "0"
}
