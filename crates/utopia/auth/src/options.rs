use php_std::format::Arg;
use php_std::{Array, ArrayKey, Str, Value};

/// The options document of a [`Hash`](crate::Hash) (`Hash::getOptions()`).
///
/// Appwrite persists it as a user's `hashOptions` and rebuilds the hash from
/// it ([`crate::proofs::Password::create_hash`]), so it is a contract: an
/// ordered map with PHP's array semantics (insertion order, `"7"` is key
/// `7`, setting an existing key keeps its position) holding any PHP value.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Options(Array);

impl Options {
    /// An empty document.
    pub fn new() -> Self {
        Self::default()
    }

    /// `setOption($key, $value)`.
    pub fn set(&mut self, key: &str, value: impl OptionValue) {
        self.0.set(ArrayKey::from_bytes(key.as_bytes()), value.into_value());
    }

    /// `$options[$key]`, including a stored `null`.
    pub fn raw(&self, key: &str) -> Option<&Value> {
        self.0.get(&ArrayKey::from_bytes(key.as_bytes()))
    }

    /// `getOption($key)`: `$options[$key] ?? null` (a stored `null` is absent).
    pub fn get(&self, key: &str) -> Option<&Value> {
        self.raw(key).filter(|v| !matches!(v, Value::Null))
    }

    /// The document as a PHP array.
    pub fn as_array(&self) -> &Array {
        &self.0
    }

    /// The `(int)` cast of an option, or `default` when it is absent.
    pub(crate) fn long_or(&self, key: &str, default: i64) -> i64 {
        self.raw(key).map(to_long).unwrap_or(default)
    }

    /// The option when it is a PHP string.
    pub(crate) fn string(&self, key: &str) -> Option<&[u8]> {
        match self.raw(key) {
            Some(Value::Str(s)) => Some(s),
            _ => None,
        }
    }

    /// The option when it is a PHP int.
    pub(crate) fn int(&self, key: &str) -> Option<i64> {
        match self.raw(key) {
            Some(Value::Int(i)) => Some(*i),
            _ => None,
        }
    }
}

/// A value an option can hold.
pub trait OptionValue {
    /// The PHP value stored.
    fn into_value(self) -> Value;
}

impl OptionValue for Value {
    fn into_value(self) -> Value {
        self
    }
}

impl OptionValue for &str {
    fn into_value(self) -> Value {
        Value::Str(Str::copy_from(self.as_bytes()))
    }
}

impl OptionValue for String {
    fn into_value(self) -> Value {
        Value::Str(Str::from(self.into_bytes()))
    }
}

impl OptionValue for Vec<u8> {
    fn into_value(self) -> Value {
        Value::Str(Str::from(self))
    }
}

impl OptionValue for i64 {
    fn into_value(self) -> Value {
        Value::Int(self)
    }
}

impl OptionValue for i32 {
    fn into_value(self) -> Value {
        Value::Int(i64::from(self))
    }
}

impl OptionValue for bool {
    fn into_value(self) -> Value {
        Value::Bool(self)
    }
}

impl From<Array> for Options {
    fn from(array: Array) -> Self {
        Options(array)
    }
}

/// `zval_get_long`, the `(int)` cast.
pub(crate) fn to_long(value: &Value) -> i64 {
    arg(value).to_int()
}

/// `zend_is_true`, the `(bool)` cast.
pub(crate) fn truthy(value: &Value) -> bool {
    arg(value).to_bool()
}

pub(crate) fn arg(value: &Value) -> Arg<'_> {
    match value {
        Value::Null => Arg::Null,
        Value::Bool(b) => Arg::Bool(*b),
        Value::Int(i) => Arg::Int(*i),
        Value::Float(f) => Arg::Float(*f),
        Value::Str(s) => Arg::Str(s),
        Value::Array(a) => Arg::Array(a.len()),
        Value::Object(_) => Arg::Object,
        Value::Ext(never) => match *never {},
    }
}

/// The PHP type name used in `TypeError` messages (`get_debug_type` style used by operators).
pub(crate) fn type_name(value: &Value) -> &'static str {
    match value {
        Value::Null => "null",
        Value::Bool(_) => "bool",
        Value::Int(_) => "int",
        Value::Float(_) => "float",
        Value::Str(_) => "string",
        Value::Array(_) => "array",
        Value::Object(_) => "stdClass",
        Value::Ext(never) => match *never {},
    }
}
