//! The options document of a password hash: the settings stored with each
//! hash so it can be rebuilt and verified later.

use php_std::format::Arg;
use php_std::{Array, ArrayKey, Str, Value};

/// The options of a [`Hash`](crate::Hash): an ordered map of names to values.
///
/// Appwrite persists it as a user's `hashOptions` and rebuilds the hash from
/// it ([`crate::proofs::Password::create_hash`]), so it is a contract: keys
/// keep their insertion order, a numeric string such as `"7"` is the integer
/// key `7`, and setting an existing key keeps its position.
///
/// PHP: `Hash::getOptions()`, a PHP array holding any PHP value.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Options(Array);

impl Options {
    /// An empty document.
    pub fn new() -> Self {
        Self::default()
    }

    /// Sets the option `key`, keeping its position when it already exists.
    ///
    /// PHP: `setOption($key, $value)`.
    pub fn set(&mut self, key: &str, value: impl OptionValue) {
        self.0.set(ArrayKey::from_bytes(key.as_bytes()), value.into_value());
    }

    /// The option `key` as stored, including a null value.
    ///
    /// PHP: `$options[$key]`.
    pub fn raw(&self, key: &str) -> Option<&Value> {
        self.0.get(&ArrayKey::from_bytes(key.as_bytes()))
    }

    /// The option `key`, or `None` when it is missing or null.
    ///
    /// PHP: `getOption($key)`, which is `$options[$key] ?? null`.
    pub fn get(&self, key: &str) -> Option<&Value> {
        self.raw(key).filter(|v| !matches!(v, Value::Null))
    }

    /// The options as an ordered array, to persist or inspect.
    ///
    /// PHP: the array `getOptions()` returns.
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

/// A Rust value that can be stored as an option: strings, byte strings,
/// integers, booleans and [`Value`].
pub trait OptionValue {
    /// The value as stored in the options document.
    fn into_value(self) -> Value;
}

impl OptionValue for Value {
    /// Stores the value as is.
    fn into_value(self) -> Value {
        self
    }
}

impl OptionValue for &str {
    /// Stores the text as a string.
    fn into_value(self) -> Value {
        Value::Str(Str::copy_from(self.as_bytes()))
    }
}

impl OptionValue for String {
    /// Stores the text as a string.
    fn into_value(self) -> Value {
        Value::Str(Str::from(self.into_bytes()))
    }
}

impl OptionValue for Vec<u8> {
    /// Stores the bytes as a string.
    fn into_value(self) -> Value {
        Value::Str(Str::from(self))
    }
}

impl OptionValue for i64 {
    /// Stores the integer.
    fn into_value(self) -> Value {
        Value::Int(self)
    }
}

impl OptionValue for i32 {
    /// Stores the integer, widened to 64 bits.
    fn into_value(self) -> Value {
        Value::Int(i64::from(self))
    }
}

impl OptionValue for bool {
    /// Stores the boolean.
    fn into_value(self) -> Value {
        Value::Bool(self)
    }
}

impl From<Array> for Options {
    /// Options from an array, such as a user's stored `hashOptions`.
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
