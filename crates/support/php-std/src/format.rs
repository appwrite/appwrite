//! Number casts and rounding: `round`/`ceil` and the `(int)` cast.
//!
//! Ported from PHP 8.5.11 (`ext/standard/math.c`, `Zend/zend_operators.c`).

use crate::value::Number;

/// `zend_dval_to_lval`: a float to an integer as `(int)` converts it: NaN
/// and infinities are 0, values outside `i64` wrap modulo 2^64 (PHP warns).
pub fn dval_to_lval(d: f64) -> i64 {
    if !d.is_finite() {
        return 0;
    }
    if !fits_long(d) {
        let two64 = 18446744073709551616.0f64;
        let mut m = d % two64;
        if m < 0.0 {
            m += two64;
        }
        return (m as u64) as i64;
    }
    d as i64
}

/// `ZEND_DOUBLE_FITS_LONG`: `-2^63 <= d < 2^63`.
fn fits_long(d: f64) -> bool {
    (-9223372036854775808.0..9223372036854775808.0).contains(&d)
}

/// Parses an ASCII decimal float literal, correctly rounded (as `zend_strtod`).
fn parse_decimal(text: &[u8]) -> f64 {
    std::str::from_utf8(text).ok().and_then(|t| t.parse::<f64>().ok()).unwrap_or(0.0)
}

// ---------------------------------------------------------------------------
// Rounding
// ---------------------------------------------------------------------------

/// `round()`'s `$mode` (`RoundingMode` cases and `PHP_ROUND_*` constants).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum RoundingMode {
    /// `PHP_ROUND_HALF_UP` (1), `RoundingMode::HalfAwayFromZero`: the default.
    #[default]
    HalfAwayFromZero,
    /// `PHP_ROUND_HALF_DOWN` (2), `RoundingMode::HalfTowardsZero`.
    HalfTowardsZero,
    /// `PHP_ROUND_HALF_EVEN` (3), `RoundingMode::HalfEven`.
    HalfEven,
    /// `PHP_ROUND_HALF_ODD` (4), `RoundingMode::HalfOdd`.
    HalfOdd,
    /// `RoundingMode::PositiveInfinity` (5).
    PositiveInfinity,
    /// `RoundingMode::NegativeInfinity` (6).
    NegativeInfinity,
    /// `RoundingMode::TowardsZero` (7).
    TowardsZero,
    /// `RoundingMode::AwayFromZero` (8).
    AwayFromZero,
}

impl RoundingMode {
    /// The mode for PHP's integer value (`PHP_ROUND_*`, 1 to 8); `None`
    /// for anything else (PHP's `ValueError`).
    pub fn from_php(mode: i64) -> Option<Self> {
        Some(match mode {
            1 => Self::HalfAwayFromZero,
            2 => Self::HalfTowardsZero,
            3 => Self::HalfEven,
            4 => Self::HalfOdd,
            5 => Self::PositiveInfinity,
            6 => Self::NegativeInfinity,
            7 => Self::TowardsZero,
            8 => Self::AwayFromZero,
            _ => return None,
        })
    }
}

/// `php_intpow10`: 10^power, exact for 0..=22 and C `pow(10, power)` above.
///
/// Quirk: PHP's image links musl, whose `pow(10, n)` is one ULP above the
/// correctly rounded power for n = 23, 126 and 210 (checked for every n up
/// to 330 against the PHP image); those three are pinned here.
fn intpow10(power: i32) -> f64 {
    match power {
        23 => f64::from_bits(0x44b5_2d02_c7e1_4af7),
        126 => f64::from_bits(0x5a17_a2ec_c414_a040),
        210 => f64::from_bits(0x6b88_557f_3132_6bbc),
        _ => format!("1e{power}").parse().unwrap_or(f64::INFINITY),
    }
}

fn round_helper(integral: f64, value: f64, exponent: f64, places: i32, mode: RoundingMode) -> f64 {
    let value_abs = value.abs();
    let basic_edge = || {
        let v = integral + 0.5f64.copysign(integral);
        (if places > 0 { v / exponent } else { v * exponent }).abs()
    };
    let zero_edge = || (if places > 0 { integral / exponent } else { integral * exponent }).abs();
    let away = integral + 1.0f64.copysign(integral);
    match mode {
        RoundingMode::HalfAwayFromZero => {
            if value_abs >= basic_edge() {
                away
            } else {
                integral
            }
        }
        RoundingMode::HalfTowardsZero => {
            if value_abs > basic_edge() {
                away
            } else {
                integral
            }
        }
        RoundingMode::PositiveInfinity => {
            if value > 0.0 && value_abs > zero_edge() {
                integral + 1.0
            } else {
                integral
            }
        }
        RoundingMode::NegativeInfinity => {
            if value < 0.0 && value_abs > zero_edge() {
                integral - 1.0
            } else {
                integral
            }
        }
        RoundingMode::TowardsZero => integral,
        RoundingMode::AwayFromZero => {
            if value_abs > zero_edge() {
                away
            } else {
                integral
            }
        }
        RoundingMode::HalfEven | RoundingMode::HalfOdd => {
            let edge = basic_edge();
            if value_abs > edge {
                away
            } else if value_abs == edge {
                let even = integral % 2.0 == 0.0;
                if even == (mode == RoundingMode::HalfOdd) { away } else { integral }
            } else {
                integral
            }
        }
    }
}

/// `_php_math_round` (PHP 8.5.11): `value` rounded to `places` decimal
/// places, with PHP's pre-rounding correction for values like 0.285.
fn math_round(value: f64, places: i32, mode: RoundingMode) -> f64 {
    if !value.is_finite() || value == 0.0 {
        return value;
    }
    // GH-22410 (8.5.11): an integral value rounded to 0 places is returned
    // as it is (8.5.11 rounded 2^52 up to 2^52 + 1 in the pre-rounding step).
    if places == 0 && value == value.trunc() {
        return value;
    }
    let places = places.max(i32::MIN + 1);
    let exponent = intpow10(places.abs());
    let scaled = if places > 0 { value * exponent } else { value / exponent };
    let (mut tmp, tmp2) = if value >= 0.0 {
        let t = scaled.floor();
        (t, t + 1.0)
    } else {
        let t = scaled.ceil();
        (t, t - 1.0)
    };
    if (if places > 0 { tmp2 / exponent } else { tmp2 * exponent }) == value {
        tmp = tmp2;
    }
    if tmp.abs() >= 1e16 {
        return value;
    }
    tmp = round_helper(tmp, value, exponent, places, mode);
    if places.abs() < 23 {
        if places > 0 { tmp / exponent } else { tmp * exponent }
    } else {
        // PHP prints "%15fe%d" and parses it back with zend_strtod.
        let sign = if tmp < 0.0 { "-" } else { "" };
        let text = format!("{sign}{:.6}e{}", tmp.abs(), -(places as i64));
        let r = parse_decimal(text.as_bytes());
        if r.is_finite() { r } else { value }
    }
}

/// Clamps a PHP `int` precision to a C `int` as `round` and `number_format` do.
fn clamp_places(precision: i64) -> i32 {
    precision.clamp(i32::MIN as i64, i32::MAX as i64) as i32
}

/// PHP `round($num, $precision, $mode)`: always a float. Integers with a
/// non-negative precision are returned as they are.
pub fn round(num: Number, precision: i64, mode: RoundingMode) -> f64 {
    let places = clamp_places(precision);
    match num {
        Number::Int(i) if places >= 0 => i as f64,
        n => math_round(n.as_f64(), places, mode),
    }
}

/// PHP `ceil($num)`: always a float.
pub fn ceil(num: Number) -> f64 {
    match num {
        Number::Int(i) => i as f64,
        Number::Float(f) => f.ceil(),
    }
}
