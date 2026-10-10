//! The PHP value model: [`Value`], [`Str`], [`Array`], [`ArrayKey`].
//!
//! One model for every crate that carries PHP values (request payloads,
//! documents, cache entries, queue messages), with the engine's semantics:
//! byte strings, ordered arrays with integer and string keys, `stdClass`,
//! integers distinct from floats. The codecs ([`crate::json`],
//! [`crate::serialize`], [`crate::igbinary`]), type juggling and comparison
//! ([`Value::loose_eq`], [`Value::cmp_php`]) and sorting ([`crate::sort`])
//! work on it, and each is fuzzed against the engine (`bin/compat fuzz php-std`).
//!
//! | PHP | Rust |
//! |---|---|
//! | `null`, `bool`, `int`, `float` | [`Value::Null`], [`Value::Bool`], [`Value::Int`], [`Value::Float`] |
//! | `string` (bytes) | [`Value::Str`] / [`Str`] |
//! | `array` | [`Value::Array`] / [`Array`], keys [`ArrayKey`] |
//! | `stdClass` | [`Value::Object`] (its property table, string keys as given) |
//! | other objects (`Utopia\Database\Document`, ...) | [`Value::Ext`], an [`Extension`] |
//! | `ZEND_HANDLE_NUMERIC_STR` | [`ArrayKey::normalize`], [`numeric_key`] |
//! | `gettype`, `(bool)`, `(string)`, `(float)` | [`Value::type_name`], [`Value::truthy`], [`Value::to_php_string`], [`Value::to_double`] |
//! | `==`, `===`, `<=>` (`zend_compare`) | [`Value::loose_eq`], [`Value::strict_eq`], [`Value::cmp_php`], [`Value::compare`] |
//! | `Utopia\Http\Request` body decoding | [`Value::from_json`] |
//!
//! Values have no identity: PHP references and shared instances are copies
//! here. Two object values are never `===` (separately created PHP objects
//! are distinct instances), which is the one place where identity shows.
//!
//! Against the skeleton in rfc/rust-database.md: [`Array::iter`] yields
//! borrowed [`KeyRef`]s (a packed array stores no keys), and the engine's
//! packed-array bookkeeping is part of [`Array`] because it decides the next
//! free index (see its docs).

mod array;
mod str;
pub(crate) mod value;

use std::fmt;

pub use array::{Array, ArrayFull, ArrayKey, Iter, KeyRef, numeric_key};
pub use str::Str;
pub use value::Value;

/// Objects of classes other than `stdClass` that a crate carries in
/// [`Value::Ext`] (for the database: `Document` and `Operator`).
pub trait Extension: Clone + PartialEq + fmt::Debug + Send + Sync + 'static {
    /// `get_class()`.
    fn class(&self) -> &str;
    /// `(array)` / `getArrayCopy()`: what the codecs write for the object.
    fn to_array(&self) -> Array<Self>;
}

/// No extension: plain PHP values.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Never {}

impl Extension for Never {
    fn class(&self) -> &str {
        match *self {}
    }

    fn to_array(&self) -> Array<Self> {
        match *self {}
    }
}

/// An engine error thrown while juggling a value: `TypeError`, `ValueError`,
/// `JsonException`, or `Error` (an object that cannot become a string).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct EngineError {
    pub class: &'static str,
    pub message: String,
}

impl EngineError {
    pub fn new(class: &'static str, message: impl Into<String>) -> Self {
        EngineError { class, message: message.into() }
    }
}

impl fmt::Display for EngineError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for EngineError {}
