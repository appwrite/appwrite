//! The resource kinds, by area, and their array forms.
//!
//! Each kind is a plain struct with public fields, held by a
//! [`crate::Resource`] ([`crate::Kind`]). Its `from_array` reads the array
//! form a source or a JSON backup carries (PHP: `fromArray()`), with PHP's
//! weak-mode argument coercion: an integer where a string is expected
//! becomes its decimal string, a numeric string an integer, and so on, and a
//! value that cannot be coerced is a `TypeError`.

pub mod auth;
pub mod backups;
pub mod database;
pub mod domains;
pub mod functions;
pub mod integrations;
pub mod messaging;
pub mod settings;
pub mod sites;
pub mod storage;
pub mod templates;

use php_std::{Array, Value};

use crate::error::{Error, Result};

/// Reads a PHP array the way `fromArray()` does.
pub(crate) struct Input<'a> {
    array: &'a Array,
}

impl<'a> Input<'a> {
    pub(crate) fn new(array: &'a Array) -> Self {
        Self { array }
    }

    /// `$array['key']` (null when absent: PHP warns and reads null).
    pub(crate) fn raw(&self, key: &str) -> Value {
        self.array.get_str(key).cloned().unwrap_or(Value::Null)
    }

    /// `$array['key'] ?? null`, as an option.
    pub(crate) fn get(&self, key: &str) -> Option<&'a Value> {
        self.array.get_str(key).filter(|v| !matches!(v, Value::Null))
    }

    /// `$array['key'] ?? $default`.
    pub(crate) fn or(&self, key: &str, default: Value) -> Value {
        self.get(key).cloned().unwrap_or(default)
    }

    /// `$array['a'] ?? $array['b']` (null when neither is set).
    pub(crate) fn either(&self, a: &str, b: &str) -> Value {
        self.get(a).or_else(|| self.get(b)).cloned().unwrap_or(Value::Null)
    }

    /// `$array['key']` as a nested array (`Table::fromArray($array['table'])`).
    pub(crate) fn array(&self, key: &str, param: &Param) -> Result<Array> {
        to_array(&self.raw(key), param)
    }
}

/// A constructor parameter, for weak-mode coercion errors.
pub(crate) struct Param {
    /// `Class::method`.
    pub(crate) function: &'static str,
    /// 1-based position.
    pub(crate) position: usize,
    /// The parameter name.
    pub(crate) name: &'static str,
}

/// A parameter of `function` at `position`.
pub(crate) const fn param(function: &'static str, position: usize, name: &'static str) -> Param {
    Param { function, position, name }
}

fn type_error(param: &Param, expected: &str, value: &Value) -> Error {
    let given = match value {
        Value::Null => "null",
        Value::Bool(_) => "bool",
        Value::Int(_) => "int",
        Value::Float(_) => "float",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) => "stdClass",
        Value::Ext(_) => "object",
    };
    Error::TypeError(format!(
        "{}(): Argument #{} (${}) must be of type {}, {} given",
        param.function, param.position, param.name, expected, given
    ))
}

/// Coerces to a `string` parameter.
pub(crate) fn to_string(value: &Value, param: &Param) -> Result<String> {
    match value {
        Value::Str(s) => Ok(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => {
            Ok(value.to_php_string().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default())
        }
        _ => Err(type_error(param, "string", value)),
    }
}

/// Coerces to a `string` parameter that carries bytes (chunk data).
pub(crate) fn to_bytes(value: &Value, param: &Param) -> Result<bytes::Bytes> {
    match value {
        Value::Str(s) => Ok(s.to_bytes()),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => {
            Ok(value.to_php_string().map(|s| s.to_bytes()).unwrap_or_default())
        }
        _ => Err(type_error(param, "string", value)),
    }
}

/// Coerces to a `?string` parameter.
pub(crate) fn to_opt_string(value: &Value, param: &Param) -> Result<Option<String>> {
    match value {
        Value::Null => Ok(None),
        v => to_string(v, &Param { ..*param }).map(Some).map_err(|_| type_error(param, "?string", v)),
    }
}

/// Coerces to an `int` parameter.
pub(crate) fn to_int(value: &Value, param: &Param) -> Result<i64> {
    match value {
        Value::Int(i) => Ok(*i),
        Value::Bool(b) => Ok(i64::from(*b)),
        Value::Float(f) if f.is_finite() && *f >= -9.223_372_036_854_776e18 && *f < 9.223_372_036_854_776e18 => {
            Ok(*f as i64)
        }
        Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some((php_std::value::Number::Int(i), 0)) => Ok(i),
            Some((php_std::value::Number::Float(f), _))
                if f.is_finite() && (-9.223_372_036_854_776e18..9.223_372_036_854_776e18).contains(&f) =>
            {
                Ok(f as i64)
            }
            _ => Err(type_error(param, "int", value)),
        },
        _ => Err(type_error(param, "int", value)),
    }
}

/// Coerces to a `?int` parameter.
pub(crate) fn to_opt_int(value: &Value, param: &Param) -> Result<Option<i64>> {
    match value {
        Value::Null => Ok(None),
        v => to_int(v, param).map(Some).map_err(|_| type_error(param, "?int", v)),
    }
}

/// Coerces to a `float` parameter.
pub(crate) fn to_float(value: &Value, param: &Param) -> Result<f64> {
    match value {
        Value::Int(i) => Ok(*i as f64),
        Value::Float(f) => Ok(*f),
        Value::Bool(b) => Ok(f64::from(u8::from(*b))),
        Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some((n, _)) => Ok(n.as_f64()),
            None => Err(type_error(param, "float", value)),
        },
        _ => Err(type_error(param, "float", value)),
    }
}

/// Coerces to a `?float` parameter.
pub(crate) fn to_opt_float(value: &Value, param: &Param) -> Result<Option<f64>> {
    match value {
        Value::Null => Ok(None),
        v => to_float(v, param).map(Some).map_err(|_| type_error(param, "?float", v)),
    }
}

/// Coerces to a `bool` parameter.
pub(crate) fn to_bool(value: &Value, param: &Param) -> Result<bool> {
    match value {
        Value::Bool(b) => Ok(*b),
        Value::Int(_) | Value::Float(_) | Value::Str(_) => Ok(value.truthy()),
        _ => Err(type_error(param, "bool", value)),
    }
}

/// Coerces to a `?bool` parameter.
pub(crate) fn to_opt_bool(value: &Value, param: &Param) -> Result<Option<bool>> {
    match value {
        Value::Null => Ok(None),
        v => to_bool(v, param).map(Some).map_err(|_| type_error(param, "?bool", v)),
    }
}

/// Coerces to an `array` parameter.
pub(crate) fn to_array(value: &Value, param: &Param) -> Result<Array> {
    match value {
        Value::Array(a) => Ok(a.clone()),
        _ => Err(type_error(param, "array", value)),
    }
}

/// Coerces to a `?array` parameter.
pub(crate) fn to_opt_array(value: &Value, param: &Param) -> Result<Option<Array>> {
    match value {
        Value::Null => Ok(None),
        Value::Array(a) => Ok(Some(a.clone())),
        v => Err(type_error(param, "?array", v)),
    }
}

/// `(bool) $value`.
pub(crate) fn cast_bool(value: &Value) -> bool {
    value.truthy()
}

/// `(int) $value`.
pub(crate) fn cast_int(value: &Value) -> i64 {
    match value {
        Value::Null => 0,
        Value::Bool(b) => i64::from(*b),
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) => php_std::format::str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        _ => 1,
    }
}

/// `(string) $value` for scalars (arrays become `"Array"`).
pub(crate) fn cast_string(value: &Value) -> String {
    match value {
        Value::Array(_) => "Array".to_owned(),
        v => v.to_php_string().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default(),
    }
}

/// `(array) $value`.
pub(crate) fn cast_array(value: &Value) -> Array {
    match value {
        Value::Array(a) => a.clone(),
        Value::Null => Array::new(),
        v => Array::from_list(vec![v.clone()]),
    }
}

/// A list of strings from an array's values (`array<string>` fields).
pub(crate) fn strings(array: &Array) -> Vec<String> {
    array.values().map(cast_string).collect()
}
