//! [`Rounding`]: how a fractional change, bound or limit on an integer
//! attribute becomes a whole number.
//!
//! PHP: `Utopia\Database\Rounding` (`@internal`).

use crate::validator::big_int::{BigInt, Integer, Operand};
use crate::value::Value;

/// A rounding rule, exact at any size.
///
/// ```
/// use utopia_database::rounding::Rounding;
/// use utopia_database::validator::big_int::Integer;
/// use utopia_database::value::{Value, string};
///
/// assert_eq!(Rounding::HalfAwayFromZero.round(&Value::Float(-2.5)), Some(Integer::Int(-3)));
/// assert_eq!(Rounding::Floor.round(&string("101.9")), Some(Integer::Int(101)));
/// assert_eq!(Rounding::Ceil.round(&string("99.1")), Some(Integer::Int(100)));
/// assert_eq!(Rounding::Floor.round(&string("1.5e2")), None);
/// ```
///
/// PHP: `Utopia\Database\Rounding`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Rounding {
    /// Halves away from zero (`HalfAwayFromZero`).
    HalfAwayFromZero,
    /// Toward negative infinity (`Floor`).
    Floor,
    /// Toward positive infinity (`Ceil`).
    Ceil,
}

impl Rounding {
    /// `$value` as a whole number: a finite float, or decimal text (an
    /// optional sign, digits around a point, surrounding whitespace, no
    /// exponent). `None` for anything else, which PHP returns as given for
    /// the caller to accept or refuse.
    ///
    /// PHP: `Rounding::round()`.
    pub fn round(self, value: &Value) -> Option<Integer> {
        let (negative, whole, half, fractional) = match value {
            Value::Float(f) if f.is_finite() => {
                let negative = *f < 0.0;
                let magnitude = f.abs().floor();
                let fraction = f.abs() - magnitude;
                let whole = BigInt::integral_value(Operand::Float(if negative { -magnitude } else { magnitude }))?;
                (negative, whole, fraction >= 0.5, fraction > 0.0)
            }
            Value::Str(s) => {
                let (sign, digits, decimals) = decimal(s.as_bytes())?;
                let negative = sign == Some(b'-');
                let mut text = Vec::with_capacity(digits.len() + 1);
                if negative {
                    text.push(b'-');
                }
                text.extend_from_slice(if digits.is_empty() || digits == b"0" { b"0" } else { digits });
                let whole = BigInt::to_native(Operand::Str(&text)).ok()?;
                let half = decimals.first().is_some_and(|d| *d >= b'5');
                let fractional = decimals.iter().any(|d| *d != b'0');
                (negative, whole, half, fractional)
            }
            _ => return None,
        };
        let away = match self {
            Rounding::HalfAwayFromZero => half,
            Rounding::Floor => negative && fractional,
            Rounding::Ceil => !negative && fractional,
        };
        if !away {
            return Some(whole);
        }
        BigInt::add(&whole, if negative { -1 } else { 1 }).ok()
    }
}

/// `/^\s*([+-]?)(?=\.?\d)(\d*)\.(\d*)\s*$/D`: the sign, the whole digits
/// and the decimals of decimal text.
fn decimal(s: &[u8]) -> Option<(Option<u8>, &[u8], &[u8])> {
    let space = |c: &u8| matches!(c, b' ' | b'\t' | b'\n' | b'\r' | 0x0B | 0x0C);
    let start = s.iter().position(|c| !space(c))?;
    let end = s.iter().rposition(|c| !space(c))? + 1;
    let mut body = &s[start..end];
    let sign = match body.first() {
        Some(c @ (b'+' | b'-')) => {
            body = &body[1..];
            Some(*c)
        }
        _ => None,
    };
    let dot = body.iter().position(|c| *c == b'.')?;
    let (digits, decimals) = (&body[..dot], &body[dot + 1..]);
    let all_digits = |part: &[u8]| part.iter().all(u8::is_ascii_digit);
    if !all_digits(digits) || !all_digits(decimals) || (digits.is_empty() && decimals.is_empty()) {
        return None;
    }
    Some((sign, digits, decimals))
}
