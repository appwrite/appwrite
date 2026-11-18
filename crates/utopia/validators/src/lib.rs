//! The checks of `utopia-php/validators` (`packages/validators`), as PHP runs them.
//!
//! Each function is the body of a PHP validator's method over a value as PHP sees it
//! ([`Input`]): the same coercions (`is_numeric()`, `$value + 0`), the same comparisons and
//! the same messages. The validator classes themselves, and the state their constructors
//! keep, live in the PHP extension (`crates/extension`), which passes that state in.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Boolean::isValid()` | [`boolean`] |
//! | `Integer::isValid()`, `getDescription()`, `getFormat()` | [`Integer`] |
//! | `FloatValidator::isValid()` | [`float`] |
//! | `Numeric::isValid()` | [`numeric`] |
//! | `Range::isValid()`, `getDescription()` | [`range`], [`range_description`] |
//! | `Port::isValid()` | [`port`] |
//! | `HexColor::isValid()` | [`hex_color`] |
//! | `Text::isValid()`, `getDescription()` | [`is_blank`], [`text_length`], [`text_description`] |
//! | `Identifier::isValid()`, `getDescription()` | [`identifier`], [`identifier_description`] |
//! | `WhiteList::__construct()`, `getDescription()` | [`ascii_lower`], [`white_list_description`] |
//! | `Contains::isValid()`, `getDescription()` | [`lower`], [`contains`], [`contains_description`] |
//! | `Label::isValid()`, `getDescription()` | [`label`], [`label_description`] |
//!
//! Comparing a value with the PHP values a validator holds (the loose `in_array()` of `Text`'s
//! allow list and of `WhiteList`, `(string)` of a pattern) is left to the PHP engine.

mod scalar;
mod text;

pub use php_std::value::Number;
pub use scalar::{
    Integer, IntegerError, boolean, float, hex_color, number_le, numeric, port, port_host, port_number, range,
    range_description, range_number,
};
pub use text::{
    LABEL_MAX_LENGTH, ascii_lower, chars, contains, contains_description, identifier, identifier_description, is_blank,
    label, label_description, lower, text_description, text_length, white_list_description,
};

/// A PHP value as the validators look at it: scalars by value, strings as bytes, and
/// everything else (arrays, objects, resources) as [`Input::Other`], which no check here
/// accepts as a number or a string.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Input<'a> {
    /// `null`.
    Null,
    /// A `bool`.
    Bool(bool),
    /// An `int`.
    Int(i64),
    /// A `float`.
    Float(f64),
    /// A `string`, as bytes.
    Str(&'a [u8]),
    /// An array, an object or a resource.
    Other,
}

impl Input<'_> {
    /// PHP `is_numeric($value) ? $value + 0 : null`: ints and floats as they are, numeric
    /// strings (leading and trailing whitespace allowed, no hex) parsed as the engine
    /// parses them, an integer that overflows becoming a float.
    pub fn numeric(self) -> Option<Number> {
        match self {
            Input::Int(i) => Some(Number::Int(i)),
            Input::Float(f) => Some(Number::Float(f)),
            Input::Str(s) => php_std::value::numeric_bytes_ex(s).map(|(n, _)| n),
            Input::Null | Input::Bool(_) | Input::Other => None,
        }
    }
}
