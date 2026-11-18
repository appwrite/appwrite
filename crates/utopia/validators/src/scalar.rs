//! Booleans, integers, floats, numbers, ranges, ports and hex colors.

use php_std::filter::{self, FILTER_FLAG_IPV4, FILTER_FLAG_IPV6, FILTER_VALIDATE_IP, Options};
use php_std::format::number_format;

use crate::{Input, Number};

/// PHP `$a <= $b` on two numbers: exact between ints, as floats otherwise (NaN is never
/// smaller or equal).
pub fn number_le(a: Number, b: Number) -> bool {
    match (a, b) {
        (Number::Int(a), Number::Int(b)) => a <= b,
        _ => a.as_f64() <= b.as_f64(),
    }
}

/// PHP `number_format($number)`: rounded to an integer, digits grouped by `,`.
fn grouped(number: Number) -> Vec<u8> {
    number_format(number, 0, b".", b",")
}

/// A boolean; when `loose`, also `'true'`, `'false'`, `'1'`, `'0'`, `1` and `0`.
///
/// PHP: `Boolean::isValid()`.
pub fn boolean(loose: bool, value: Input<'_>) -> bool {
    match value {
        Input::Bool(_) => true,
        Input::Str(s) => loose && matches!(s, b"true" | b"false" | b"1" | b"0"),
        Input::Int(i) => loose && (i == 1 || i == 0),
        _ => false,
    }
}

/// An int or a float; when `loose`, anything `is_numeric()`.
///
/// PHP: `FloatValidator::isValid()`.
pub fn float(loose: bool, value: Input<'_>) -> bool {
    if loose {
        return value.numeric().is_some();
    }
    matches!(value, Input::Int(_) | Input::Float(_))
}

/// Anything `is_numeric()`: ints, floats (`NAN` and `INF` included) and numeric strings.
///
/// PHP: `Numeric::isValid()`.
pub fn numeric(value: Input<'_>) -> bool {
    value.numeric().is_some()
}

/// Why `new Integer()` refuses its arguments; [`IntegerError::message`] is the
/// `InvalidArgumentException`'s.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum IntegerError {
    /// `$bits` is not 8, 16, 32 or 64.
    Bits,
    /// An unsigned 64-bit integer.
    Unsigned64,
}

impl IntegerError {
    /// The exception's message, as `Integer.php` words it.
    pub fn message(self) -> &'static str {
        match self {
            IntegerError::Bits => "Bits must be 8, 16, 32, or 64",
            IntegerError::Unsigned64 => "64-bit unsigned integers are not supported due to PHP integer limitations",
        }
    }
}

/// An integer of `bits` bits, signed or `unsigned`.
///
/// PHP: `Integer`'s `$bits` and `$unsigned`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Integer {
    /// The size in bits.
    pub bits: i64,
    /// Whether the range starts at 0.
    pub unsigned: bool,
}

impl Integer {
    /// The constructor's checks of `$bits` and `$unsigned` (on a 64-bit build, the third,
    /// that the size fits the build's integers, follows from the first two).
    ///
    /// PHP: `new Integer($loose, $bits, $unsigned)`.
    pub fn new(bits: i64, unsigned: bool) -> Result<Self, IntegerError> {
        if !matches!(bits, 8 | 16 | 32 | 64) {
            return Err(IntegerError::Bits);
        }
        if bits == 64 && unsigned {
            return Err(IntegerError::Unsigned64);
        }
        Ok(Self { bits, unsigned })
    }

    /// The largest valid value.
    ///
    /// PHP: `getMax()` (private): `(1 << $bits) - 1` unsigned, `PHP_INT_MAX >> (64 - $bits)`
    /// signed.
    pub fn max(self) -> i64 {
        if self.unsigned {
            let shifted = if (0..64).contains(&self.bits) { 1i64 << self.bits } else { 0 };
            shifted.wrapping_sub(1)
        } else {
            let shift = 64 - self.bits;
            if (0..64).contains(&shift) { i64::MAX >> shift } else { 0 }
        }
    }

    /// The smallest valid value.
    ///
    /// PHP: `getMin()` (private).
    pub fn min(self) -> i64 {
        if self.unsigned { 0 } else { -self.max() - 1 }
    }

    /// An int within the bounds; when `loose`, also a numeric string that `+ 0` makes one.
    /// A float is never valid, `1.0` included.
    ///
    /// PHP: `Integer::isValid()`.
    pub fn accepts(self, loose: bool, value: Input<'_>) -> bool {
        let value = if loose {
            value.numeric()
        } else if let Input::Int(i) = value {
            Some(Number::Int(i))
        } else {
            None
        };
        match value {
            Some(Number::Int(i)) => i >= self.min() && i <= self.max(),
            _ => false,
        }
    }

    /// `Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647`.
    ///
    /// PHP: `Integer::getDescription()`.
    pub fn description(self) -> Vec<u8> {
        let mut out = format!(
            "Value must be a valid {} {}-bit integer between ",
            if self.unsigned { "unsigned" } else { "signed" },
            self.bits
        )
        .into_bytes();
        out.extend(grouped(Number::Int(self.min())));
        out.extend_from_slice(b" and ");
        out.extend(grouped(Number::Int(self.max())));
        out
    }

    /// The OpenAPI format, `int32` or `uint8`, ..., given what `isUnsigned()` answers.
    ///
    /// PHP: `Integer::getFormat()`.
    pub fn format(bits: i64, unsigned: bool) -> String {
        format!("{}{bits}", if unsigned { "uint" } else { "int" })
    }
}

/// The number `Range::isValid()` compares with its bounds, for a value that passed
/// `is_numeric()`: as `'integer'`, an int (from `+ 0`, so a numeric string that makes one) or
/// a float that already is `INF`/`-INF` (PHP checks `$value === INF` before `+ 0`, so a
/// string such as `'1e400'` that overflows to `INF` is not one); as `'double'`, any number as
/// a float; `None` when the value is not one, and for any other format.
pub fn range_number(format: &[u8], value: Input<'_>) -> Option<Number> {
    let number = value.numeric()?;
    match format {
        b"integer" => match (value, number) {
            (Input::Float(f), _) if f.is_infinite() => Some(number),
            (_, Number::Int(_)) => Some(number),
            (_, Number::Float(_)) => None,
        },
        b"double" => Some(Number::Float(number.as_f64())),
        _ => None,
    }
}

/// A number from `min` to `max`, both included, of `format` (see [`range_number`]).
///
/// PHP: `Range::isValid()`.
pub fn range(min: Number, max: Number, format: &[u8], value: Input<'_>) -> bool {
    range_number(format, value).is_some_and(|number| number_le(min, number) && number_le(number, max))
}

/// `Value must be a valid range between 1 and 10`, both bounds as `number_format()` prints
/// them.
///
/// PHP: `Range::getDescription()`.
pub fn range_description(min: Number, max: Number) -> Vec<u8> {
    let mut out = b"Value must be a valid range between ".to_vec();
    out.extend(grouped(min));
    out.extend_from_slice(b" and ");
    out.extend(grouped(max));
    out
}

/// A port from 1 to 65535, optionally after an IPv4 address or a bracketed IPv6 one and a
/// colon (`127.0.0.1:80`, `[::1]:443`). An int is checked as its decimal string.
///
/// PHP: `Port::isValid()`.
pub fn port(value: Input<'_>) -> bool {
    let digits;
    let value = match value {
        Input::Int(i) => {
            digits = i.to_string();
            digits.as_bytes()
        }
        Input::Str(s) => s,
        _ => return false,
    };
    match value.iter().rposition(|&b| b == b':') {
        None => port_number(value),
        Some(at) => port_host(&value[..at]) && port_number(&value[at + 1..]),
    }
}

/// An IPv4 address, or an IPv6 address in brackets.
///
/// PHP: `Port::isValidHost()` (private).
pub fn port_host(host: &[u8]) -> bool {
    let (address, flag) = match host {
        [b'[', .., b']'] => (&host[1..host.len() - 1], FILTER_FLAG_IPV6),
        // `substr('[', 1, -1)`: a lone bracket both starts and ends the string.
        [b'['] => (&host[..0], FILTER_FLAG_IPV6),
        _ => (host, FILTER_FLAG_IPV4),
    };
    matches!(
        filter::filter_var(&filter::Value::Str(address.to_vec()), FILTER_VALIDATE_IP, &Options::Flags(flag)),
        Ok(filter::Filtered { value: filter::Value::Str(_), .. })
    )
}

/// Decimal digits without a leading zero, from 1 to 65535.
///
/// PHP: `Port::isValidPort()` (private).
pub fn port_number(port: &[u8]) -> bool {
    if port.is_empty() || !port.iter().all(u8::is_ascii_digit) || port[0] == b'0' {
        return false;
    }
    // `(int)` of a longer run of digits saturates far above 65535.
    port.len() <= 5 && port.iter().fold(0u32, |n, &d| n * 10 + u32::from(d - b'0')) <= 65535
}

/// A string of 3 or 6 hex digits, no `#`. The pattern's `$` also matches before a final
/// newline, so one trailing `"\n"` is allowed.
///
/// PHP: `HexColor::isValid()` (`/^([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/`).
pub fn hex_color(value: Input<'_>) -> bool {
    let Input::Str(s) = value else {
        return false;
    };
    let s = s.strip_suffix(b"\n").unwrap_or(s);
    matches!(s.len(), 3 | 6) && s.iter().all(u8::is_ascii_hexdigit)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn integer_bounds() {
        let signed = Integer::new(32, false).unwrap();
        assert_eq!((signed.min(), signed.max()), (-2_147_483_648, 2_147_483_647));
        let byte = Integer::new(8, true).unwrap();
        assert_eq!((byte.min(), byte.max()), (0, 255));
        let wide = Integer::new(64, false).unwrap();
        assert_eq!((wide.min(), wide.max()), (i64::MIN, i64::MAX));
        assert_eq!(Integer::new(12, false), Err(IntegerError::Bits));
        assert_eq!(Integer::new(64, true), Err(IntegerError::Unsigned64));
    }

    #[test]
    fn integer_accepts() {
        let int = Integer::new(8, false).unwrap();
        assert!(int.accepts(false, Input::Int(127)) && !int.accepts(false, Input::Int(128)));
        assert!(!int.accepts(false, Input::Str(b"5")) && int.accepts(true, Input::Str(b" 5 ")));
        assert!(!int.accepts(true, Input::Str(b"1e1")) && !int.accepts(true, Input::Float(1.0)));
        assert_eq!(
            String::from_utf8(int.description()).unwrap(),
            "Value must be a valid signed 8-bit integer between -128 and 127"
        );
    }

    #[test]
    fn ranges() {
        let (one, ten) = (Number::Int(1), Number::Int(10));
        assert!(range(one, ten, b"integer", Input::Str(b"5")) && !range(one, ten, b"integer", Input::Float(5.0)));
        assert!(range(one, ten, b"double", Input::Float(5.5)) && !range(one, ten, b"string", Input::Int(5)));
        assert!(range(Number::Float(f64::NEG_INFINITY), ten, b"integer", Input::Float(f64::NEG_INFINITY)));
        assert!(!range(one, ten, b"double", Input::Float(f64::NAN)));
        let unbounded = Number::Float(f64::INFINITY);
        assert!(
            range(one, unbounded, b"integer", Input::Float(f64::INFINITY))
                && !range(one, unbounded, b"integer", Input::Str(b"1e400"))
        );
    }

    #[test]
    fn ports_and_colors() {
        assert!(port(Input::Int(80)) && port(Input::Str(b"127.0.0.1:8080")) && port(Input::Str(b"[::1]:443")));
        assert!(!port(Input::Str(b"080")) && !port(Input::Str(b"65536")) && !port(Input::Str(b"[::1]")));
        assert!(!port(Input::Str(b"::1:80")) && !port(Input::Int(0)) && !port(Input::Str(b"")));
        assert!(hex_color(Input::Str(b"fff")) && hex_color(Input::Str(b"A0B1C2\n")) && !hex_color(Input::Str(b"#fff")));
        assert!(!hex_color(Input::Str(b"ffff")) && !hex_color(Input::Str(b"fff\n\n")));
    }
}
