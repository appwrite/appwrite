use php_std::format::Arg;
use php_std::zval::{Array, Key, Zval};

/// The options document of a [`Hash`](crate::Hash) (`Hash::getOptions()`).
///
/// Appwrite persists it as a user's `hashOptions` and rebuilds the hash from
/// it ([`crate::proofs::Password::create_hash`]), so it is a contract: an
/// ordered map with PHP's array semantics (insertion order, `"7"` is key
/// `7`, setting an existing key keeps its position) holding any PHP value.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Options(Array);

impl Options {
    pub fn new() -> Self {
        Self::default()
    }

    /// `setOption($key, $value)`.
    pub fn set(&mut self, key: &str, value: impl OptionValue) {
        self.0.insert(Key::from_bytes(key.as_bytes()), value.into_zval());
    }

    /// `$options[$key]`, including a stored `null`.
    pub fn raw(&self, key: &str) -> Option<&Zval> {
        self.0.get(&Key::from_bytes(key.as_bytes()))
    }

    /// `getOption($key)`: `$options[$key] ?? null` (a stored `null` is absent).
    pub fn get(&self, key: &str) -> Option<&Zval> {
        self.raw(key).filter(|v| !matches!(v, Zval::Null))
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
            Some(Zval::String(s)) => Some(s),
            _ => None,
        }
    }

    /// The option when it is a PHP int.
    pub(crate) fn int(&self, key: &str) -> Option<i64> {
        match self.raw(key) {
            Some(Zval::Int(i)) => Some(*i),
            _ => None,
        }
    }
}

/// A value an option can hold.
pub trait OptionValue {
    fn into_zval(self) -> Zval;
}

impl OptionValue for Zval {
    fn into_zval(self) -> Zval {
        self
    }
}

impl OptionValue for &str {
    fn into_zval(self) -> Zval {
        Zval::String(self.as_bytes().to_vec())
    }
}

impl OptionValue for String {
    fn into_zval(self) -> Zval {
        Zval::String(self.into_bytes())
    }
}

impl OptionValue for Vec<u8> {
    fn into_zval(self) -> Zval {
        Zval::String(self)
    }
}

impl OptionValue for i64 {
    fn into_zval(self) -> Zval {
        Zval::Int(self)
    }
}

impl OptionValue for i32 {
    fn into_zval(self) -> Zval {
        Zval::Int(i64::from(self))
    }
}

impl OptionValue for bool {
    fn into_zval(self) -> Zval {
        Zval::Bool(self)
    }
}

impl From<Array> for Options {
    fn from(array: Array) -> Self {
        Options(array)
    }
}

/// `zval_get_long`, the `(int)` cast.
pub(crate) fn to_long(value: &Zval) -> i64 {
    arg(value).to_int()
}

/// `zend_is_true`, the `(bool)` cast.
pub(crate) fn truthy(value: &Zval) -> bool {
    arg(value).to_bool()
}

pub(crate) fn arg(value: &Zval) -> Arg<'_> {
    match value {
        Zval::Null => Arg::Null,
        Zval::Bool(b) => Arg::Bool(*b),
        Zval::Int(i) => Arg::Int(*i),
        Zval::Float(f) => Arg::Float(*f),
        Zval::String(s) => Arg::Str(s),
        Zval::Array(a) => Arg::Array(a.len()),
        Zval::Object(_) => Arg::Object,
    }
}

/// The PHP type name used in `TypeError` messages (`get_debug_type` style used by operators).
pub(crate) fn type_name(value: &Zval) -> &'static str {
    match value {
        Zval::Null => "null",
        Zval::Bool(_) => "bool",
        Zval::Int(_) => "int",
        Zval::Float(_) => "float",
        Zval::String(_) => "string",
        Zval::Array(_) => "array",
        Zval::Object(_) => "stdClass",
    }
}
