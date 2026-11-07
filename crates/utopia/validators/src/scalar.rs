//! Booleans, integers, floats, numbers and numeric ranges.

use serde_json::Value;

use crate::input::View;
use crate::php::{self, Number, le_numbers};
use crate::{Error, Input, Type, Validator, Verdict, format_number, is_valid_via_validate};

/// A boolean; when loose, also `"true"`, `"false"`, `"1"`, `"0"`, `1` and `0`.
///
/// PHP: `Utopia\Validator\Boolean`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Boolean {
    /// Accept `"true"`, `"false"`, `"1"`, `"0"`, `1` and `0`.
    pub loose: bool,
}

impl Boolean {
    /// Accepts booleans only.
    pub const STRICT: Boolean = Boolean { loose: false };
    /// Also accepts `"true"`, `"false"`, `"1"`, `"0"`, `1` and `0`.
    pub const LOOSE: Boolean = Boolean { loose: true };

    /// Converts an accepted value to a `bool` by truthiness, so the string
    /// `"false"` becomes `true`.
    ///
    /// PHP: actions receive the raw value and cast it with `(bool)`; this mirrors
    /// that cast exactly.
    pub fn to_php_bool(value: &Value) -> bool {
        php::truthy(value)
    }
}

impl Validator for Boolean {
    /// Returns "Value must be a valid boolean".
    fn description(&self) -> String {
        "Value must be a valid boolean".to_owned()
    }

    is_valid_via_validate!();

    /// Valid for a boolean; when loose, also for `"true"`, `"false"`, `"1"`, `"0"`, `1` and `0`.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value.view() {
            View::Bool(_) => true,
            View::Str(s) => self.loose && matches!(s, b"true" | b"false" | b"1" | b"0"),
            View::Int(i) => self.loose && (i == 0 || i == 1),
            _ => false,
        }))
    }

    /// Expects a boolean ([`Type::Boolean`]).
    fn kind(&self) -> Type {
        Type::Boolean
    }
}

/// An integer of `bits` bits (8, 16, 32 or 64), signed or unsigned; when
/// loose, also a numeric string.
///
/// PHP: `Utopia\Validator\Integer`.
#[derive(Debug, Clone, Copy)]
pub struct Integer {
    /// Whether numeric strings are accepted too.
    pub loose: bool,
    /// The size in bits: 8, 16, 32 or 64.
    pub bits: u32,
    /// Whether the range starts at 0 instead of being centred on it.
    pub unsigned: bool,
}

impl Default for Integer {
    /// A strict, signed 32-bit integer.
    fn default() -> Self {
        Self { loose: false, bits: 32, unsigned: false }
    }
}

impl Integer {
    /// An integer validator of `bits` bits.
    ///
    /// Fails with [`Error::InvalidArgument`] when `bits` is not 8, 16, 32 or 64,
    /// and for unsigned 64-bit integers, which `i64` cannot hold.
    ///
    /// PHP: `new Integer($loose, $bits, $unsigned)`.
    pub fn new(loose: bool, bits: i64, unsigned: bool) -> Result<Self, Error> {
        if !matches!(bits, 8 | 16 | 32 | 64) {
            return Err(Error::InvalidArgument("Bits must be 8, 16, 32, or 64".into()));
        }
        if bits == 64 && unsigned {
            return Err(Error::InvalidArgument(
                "64-bit unsigned integers are not supported due to PHP integer limitations".into(),
            ));
        }
        Ok(Self { loose, bits: bits as u32, unsigned })
    }

    /// The size in bits.
    ///
    /// PHP: `getBits()`.
    pub fn bits(&self) -> u32 {
        self.bits
    }

    /// Whether the integer is unsigned.
    ///
    /// PHP: `isUnsigned()`.
    pub fn is_unsigned(&self) -> bool {
        self.unsigned
    }

    /// The OpenAPI format: `int32`, `uint8`, ...
    ///
    /// PHP: `getFormat()`.
    pub fn format(&self) -> String {
        format!("{}{}", if self.unsigned { "uint" } else { "int" }, self.bits)
    }

    /// The exact bounds of the configured size and sign.
    ///
    /// PHP: `getMin()` and `getMax()` (private).
    fn bounds(&self) -> (Number, Number) {
        let max = if self.unsigned { (1i64 << self.bits) - 1 } else { i64::MAX >> (64 - self.bits) };
        let min = if self.unsigned { 0 } else { -max - 1 };
        (Number::Int(min), Number::Int(max))
    }

    fn number(&self, value: Input<'_>) -> Option<Number> {
        if self.loose {
            return value.number();
        }
        match value.view() {
            View::Int(i) => Some(Number::Int(i)),
            View::Float(f) => Some(Number::Float(f)),
            _ => None,
        }
    }

    /// The integer a valid value holds: the integer itself, or, when loose, the
    /// one a numeric string spells; `None` for anything else, floats included.
    ///
    /// PHP: the value PHP would see (after `+ 0` when loose).
    pub fn value(&self, value: &Value) -> Option<i64> {
        match self.number(Input::Json(value))? {
            Number::Int(i) => Some(i),
            Number::Float(_) => None,
        }
    }
}

impl Validator for Integer {
    /// The range of the configured size and sign, such as `Value must be a valid
    /// signed 32-bit integer between -2,147,483,648 and 2,147,483,647`.
    fn description(&self) -> String {
        let (min, max) = self.bounds();
        format!(
            "Value must be a valid {} {}-bit integer between {} and {}",
            if self.unsigned { "unsigned" } else { "signed" },
            self.bits,
            format_number(min),
            format_number(max)
        )
    }

    is_valid_via_validate!();

    /// Valid for an integer within the range; when loose, also for a numeric string spelling one.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let Some(Number::Int(i)) = self.number(value) else {
            return Ok(Verdict::INVALID);
        };
        let (min, max) = self.bounds();
        Ok(Verdict::of(le_numbers(min, Number::Int(i)) && le_numbers(Number::Int(i), max)))
    }

    /// Expects an integer ([`Type::Integer`]).
    fn kind(&self) -> Type {
        Type::Integer
    }
}

/// A float or an integer; when loose, also a numeric string.
///
/// PHP: `Utopia\Validator\FloatValidator`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Float {
    /// Whether numeric strings are accepted too.
    pub loose: bool,
}

impl Validator for Float {
    /// Returns "Value must be a valid float".
    fn description(&self) -> String {
        "Value must be a valid float".to_owned()
    }

    is_valid_via_validate!();

    /// Valid for a float or an integer; when loose, also for a numeric string.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(if self.loose {
            value.number().is_some()
        } else {
            matches!(value.view(), View::Int(_) | View::Float(_))
        }))
    }

    /// Expects a float ([`Type::Float`]).
    fn kind(&self) -> Type {
        Type::Float
    }
}

/// A number, or a string that spells one.
///
/// PHP: `Utopia\Validator\Numeric`, which checks `is_numeric()`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Numeric;

impl Validator for Numeric {
    /// Returns "Value must be a valid number".
    fn description(&self) -> String {
        "Value must be a valid number".to_owned()
    }

    is_valid_via_validate!();

    /// Valid for an integer, a float or a numeric string.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(value.number().is_some()))
    }
}

/// A number between a minimum and a maximum, inclusive, in the
/// [`Type::Integer`] or [`Type::Float`] format; any other format rejects every
/// value.
///
/// PHP: `Utopia\Validator\Range`.
#[derive(Debug, Clone, Copy)]
pub struct Range {
    min: Number,
    max: Number,
    format: Type,
}

impl Range {
    /// An integer range.
    pub const fn new(min: i64, max: i64) -> Self {
        Self { min: Number::Int(min), max: Number::Int(max), format: Type::Integer }
    }

    /// A range in the given format.
    ///
    /// PHP: `new Range($min, $max, $format)`.
    pub const fn with_format(min: Number, max: Number, format: Type) -> Self {
        Self { min, max, format }
    }

    /// The smallest valid number.
    ///
    /// PHP: `getMin()`.
    pub fn min(&self) -> Number {
        self.min
    }

    /// The largest valid number.
    ///
    /// PHP: `getMax()`.
    pub fn max(&self) -> Number {
        self.max
    }

    /// The format: [`Type::Integer`] or [`Type::Float`].
    ///
    /// PHP: `getFormat()`.
    pub fn format(&self) -> Type {
        self.format
    }

    /// The integer a number or numeric string holds; `None` for floats and
    /// anything else.
    ///
    /// PHP: the value PHP would see after `+ 0`.
    pub fn value(value: &Value) -> Option<i64> {
        match php::numeric(value)? {
            Number::Int(i) => Some(i),
            Number::Float(_) => None,
        }
    }
}

impl Validator for Range {
    /// The bounds, with commas between thousands: `Value must be a valid range between 1 and 1,000`.
    fn description(&self) -> String {
        format!("Value must be a valid range between {} and {}", format_number(self.min), format_number(self.max))
    }

    is_valid_via_validate!();

    /// Valid for a number or numeric string within the range: an integer (or
    /// infinity) in the integer format, any number in the float format.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let Some(n) = value.number() else {
            return Ok(Verdict::INVALID);
        };
        let n = match self.format {
            // Infinity is accepted as an integer (gettype(INF) is "double").
            Type::Integer => match (value.view(), n) {
                (View::Float(f), _) if f.is_infinite() => n,
                (_, Number::Int(_)) => n,
                _ => return Ok(Verdict::INVALID),
            },
            Type::Float => Number::Float(n.as_f64()),
            _ => return Ok(Verdict::INVALID),
        };
        Ok(Verdict::of(le_numbers(self.min, n) && le_numbers(n, self.max)))
    }

    /// The format.
    fn kind(&self) -> Type {
        self.format
    }

    fn as_range(&self) -> Option<&Range> {
        Some(self)
    }
}
