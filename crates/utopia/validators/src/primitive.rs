//! Booleans, integers, floats, numbers and numeric ranges: validators over the
//! checks in [`crate::scalar`].

use serde_json::Value;

use crate::php::{self, Number};
use crate::{Error, Input, Type, Validator, Verdict, is_valid_via_validate, scalar};

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
        Ok(Verdict::of(scalar::boolean(self.loose, value.scalar())))
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
        let check = scalar::Integer::new(bits, unsigned).map_err(|e| Error::InvalidArgument(e.message().into()))?;
        Ok(Self { loose, bits: check.bits as u32, unsigned })
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
        scalar::Integer::format(i64::from(self.bits), self.unsigned)
    }

    /// The check of the configured size and sign.
    fn check(&self) -> scalar::Integer {
        scalar::Integer { bits: i64::from(self.bits), unsigned: self.unsigned }
    }

    /// The integer a valid value holds: the integer itself, or, when loose, the
    /// one a numeric string spells; `None` for anything else, floats included.
    ///
    /// PHP: the value PHP would see (after `+ 0` when loose).
    pub fn value(&self, value: &Value) -> Option<i64> {
        match Input::Json(value).scalar() {
            scalar::Input::Int(i) => Some(i),
            value if self.loose => match value.numeric()? {
                Number::Int(i) => Some(i),
                Number::Float(_) => None,
            },
            _ => None,
        }
    }
}

impl Validator for Integer {
    /// The range of the configured size and sign, such as `Value must be a valid
    /// signed 32-bit integer between -2,147,483,648 and 2,147,483,647`.
    fn description(&self) -> String {
        let mut description = Vec::new();
        self.check().description(&mut description);
        String::from_utf8_lossy(&description).into_owned()
    }

    is_valid_via_validate!();

    /// Valid for an integer within the range; when loose, also for a numeric string spelling one.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(self.check().accepts(self.loose, value.scalar())))
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
        Ok(Verdict::of(scalar::float(self.loose, value.scalar())))
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
        Ok(Verdict::of(scalar::numeric(value.scalar())))
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
        let mut description = Vec::new();
        scalar::range_description(self.min, self.max, &mut description);
        String::from_utf8_lossy(&description).into_owned()
    }

    is_valid_via_validate!();

    /// Valid for a number or numeric string within the range: an integer (or
    /// infinity) in the integer format, any number in the float format.
    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(scalar::range(self.min, self.max, self.format.as_str().as_bytes(), value.scalar())))
    }

    /// The format.
    fn kind(&self) -> Type {
        self.format
    }

    fn as_range(&self) -> Option<&Range> {
        Some(self)
    }
}
