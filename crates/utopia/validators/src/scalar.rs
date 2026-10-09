use serde_json::Value;

use crate::input::View;
use crate::php::{self, Number, le_numbers};
use crate::{Error, Input, Type, Validator, Verdict, format_number, is_valid_via_validate};

/// `Utopia\Validator\Boolean`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Boolean {
    /// Accept `"true"`, `"false"`, `"1"`, `"0"`, `1` and `0`.
    pub loose: bool,
}

impl Boolean {
    pub const STRICT: Boolean = Boolean { loose: false };
    pub const LOOSE: Boolean = Boolean { loose: true };

    /// Converts an accepted value to the `bool` PHP would see in the action.
    ///
    /// PHP actions receive the raw value and cast it with `(bool)`, so the
    /// string `"false"` becomes `true`. This mirrors that cast exactly.
    pub fn to_php_bool(value: &Value) -> bool {
        php::truthy(value)
    }
}

impl Validator for Boolean {
    fn description(&self) -> String {
        "Value must be a valid boolean".to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(match value.view() {
            View::Bool(_) => true,
            View::Str(s) => self.loose && matches!(s, b"true" | b"false" | b"1" | b"0"),
            View::Int(i) => self.loose && (i == 0 || i == 1),
            _ => false,
        }))
    }

    fn kind(&self) -> Type {
        Type::Boolean
    }
}

/// `Utopia\Validator\Integer`: an integer of `bits` bits (8, 16, 32 or 64),
/// signed or unsigned; `loose` also accepts numeric strings.
#[derive(Debug, Clone, Copy)]
pub struct Integer {
    pub loose: bool,
    pub bits: u32,
    pub unsigned: bool,
}

impl Default for Integer {
    fn default() -> Self {
        Self { loose: false, bits: 32, unsigned: false }
    }
}

impl Integer {
    /// `new Integer($loose, $bits, $unsigned)`.
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

    /// `getBits()`.
    pub fn bits(&self) -> u32 {
        self.bits
    }

    /// `isUnsigned()`.
    pub fn is_unsigned(&self) -> bool {
        self.unsigned
    }

    /// `getFormat()`: the OpenAPI format, `int32`, `uint8`, ...
    pub fn format(&self) -> String {
        format!("{}{}", if self.unsigned { "uint" } else { "int" }, self.bits)
    }

    /// The bounds as PHP computes them: `2 ** 63` overflows to a float.
    fn bounds(&self) -> (Number, Number) {
        // `2 ** $bits`: an integer while it fits, a float beyond.
        let pow2 =
            |bits: u32| if bits < 63 { Number::Int(1i64 << bits) } else { Number::Float(2f64.powi(bits as i32)) };
        let minus_one = |n: Number| match n {
            Number::Int(i) => Number::Int(i - 1),
            Number::Float(f) => Number::Float(f - 1.0),
        };
        if self.unsigned {
            (Number::Int(0), minus_one(pow2(self.bits)))
        } else {
            let half = pow2(self.bits.saturating_sub(1));
            let min = match half {
                Number::Int(i) => Number::Int(-i),
                Number::Float(f) => Number::Float(-f),
            };
            (min, minus_one(half))
        }
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

    /// The integer value PHP would see (after `+ 0` when loose).
    pub fn value(&self, value: &Value) -> Option<i64> {
        match self.number(Input::Json(value))? {
            Number::Int(i) => Some(i),
            Number::Float(_) => None,
        }
    }
}

impl Validator for Integer {
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

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        let Some(Number::Int(i)) = self.number(value) else {
            return Ok(Verdict::INVALID);
        };
        let (min, max) = self.bounds();
        Ok(Verdict::of(le_numbers(min, Number::Int(i)) && le_numbers(Number::Int(i), max)))
    }

    fn kind(&self) -> Type {
        Type::Integer
    }
}

/// `Utopia\Validator\FloatValidator`: a float or an integer; `loose` also
/// accepts numeric strings.
#[derive(Debug, Clone, Copy, Default)]
pub struct Float {
    pub loose: bool,
}

impl Validator for Float {
    fn description(&self) -> String {
        "Value must be a valid float".to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(if self.loose {
            value.number().is_some()
        } else {
            matches!(value.view(), View::Int(_) | View::Float(_))
        }))
    }

    fn kind(&self) -> Type {
        Type::Float
    }
}

/// `Utopia\Validator\Numeric`: `is_numeric()`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Numeric;

impl Validator for Numeric {
    fn description(&self) -> String {
        "Value must be a valid number".to_owned()
    }

    is_valid_via_validate!();

    fn validate(&self, value: Input<'_>) -> Result<Verdict, Error> {
        Ok(Verdict::of(value.number().is_some()))
    }
}

/// `Utopia\Validator\Range`: a number between `min` and `max` (inclusive),
/// of the `format` type ([`Type::Integer`] or [`Type::Float`]; any other
/// format rejects every value).
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

    /// `new Range($min, $max, $format)`.
    pub const fn with_format(min: Number, max: Number, format: Type) -> Self {
        Self { min, max, format }
    }

    /// `getMin()`.
    pub fn min(&self) -> Number {
        self.min
    }

    /// `getMax()`.
    pub fn max(&self) -> Number {
        self.max
    }

    /// `getFormat()`.
    pub fn format(&self) -> Type {
        self.format
    }

    /// The integer value PHP would see after `+ 0`.
    pub fn value(value: &Value) -> Option<i64> {
        match php::numeric(value)? {
            Number::Int(i) => Some(i),
            Number::Float(_) => None,
        }
    }
}

impl Validator for Range {
    fn description(&self) -> String {
        format!("Value must be a valid range between {} and {}", format_number(self.min), format_number(self.max))
    }

    is_valid_via_validate!();

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

    fn kind(&self) -> Type {
        self.format
    }
}
