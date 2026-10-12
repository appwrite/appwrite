//! How PHP prints floats.
//!
//! PHP formats floats with `zend_gcvt`: `ndigit` significant digits (mode 2),
//! or the shortest round-trip digits when the precision setting is `-1`
//! (mode 0, exponent threshold 17), switching to exponent notation when the
//! decimal point is more than `ndigit` places right or more than 3 places
//! left of the digits. The mantissa always has a fraction (`1.0E+25`).
//!
//! - `(string) $float`, `echo`, string interpolation, string comparison use
//!   the `precision` setting (14): [`to_string`].
//! - `json_encode`, `var_export` use `serialize_precision` (-1) and a
//!   lowercase `e`: [`serialize`].

use std::fmt::Write as _;

/// `(string) $float` with PHP's default `precision = 14`.
pub fn to_string(f: f64) -> String {
    gcvt(f, 14, 'E')
}

/// `json_encode($float)` / `var_export` with `serialize_precision = -1`.
pub fn serialize(f: f64) -> String {
    gcvt(f, -1, 'e')
}

/// [`serialize`], appended to `out` (no allocation of its own).
pub(crate) fn serialize_into(out: &mut Vec<u8>, f: f64) {
    gcvt_into(out, f, -1, b'e');
}

/// `zend_gcvt(value, precision, '.', exp_char)`, with PHP's special values.
/// `precision` is the ini value: `-1` selects the shortest round-trip digits.
pub fn gcvt(value: f64, precision: i32, exp_char: char) -> String {
    let mut out = Vec::with_capacity(24);
    gcvt_into(&mut out, value, precision, exp_char as u8);
    // ASCII digits, signs, '.', and the ASCII exponent character.
    String::from_utf8(out).unwrap_or_default()
}

/// [`gcvt`], appended to `out`; `exp_char` is an ASCII letter.
pub(crate) fn gcvt_into(out: &mut Vec<u8>, value: f64, precision: i32, exp_char: u8) {
    if value.is_nan() {
        out.extend_from_slice(b"NAN");
        return;
    }
    if value.is_infinite() {
        out.extend_from_slice(if value > 0.0 { b"INF" } else { b"-INF" });
        return;
    }
    let mut digits = Digits::new();
    let (ndigit, decpt) = if precision < 0 {
        (17, digits.shortest(value.abs()))
    } else {
        let n = precision.max(1) as usize;
        (n as i32, digits.rounded(value.abs(), n))
    };
    let digits = digits.as_bytes();
    if value.is_sign_negative() {
        out.push(b'-');
    }
    if decpt < -3 || decpt > ndigit {
        let exp = decpt - 1;
        out.push(digits[0]);
        out.push(b'.');
        out.extend_from_slice(if digits.len() > 1 { &digits[1..] } else { b"0" });
        out.push(exp_char);
        out.push(if exp < 0 { b'-' } else { b'+' });
        crate::json::push_int(out, i64::from(exp.unsigned_abs()));
    } else if decpt < 0 {
        out.extend_from_slice(b"0.");
        for _ in decpt..0 {
            out.push(b'0');
        }
        out.extend_from_slice(digits);
    } else {
        let decpt = decpt as usize;
        if decpt <= digits.len() {
            out.extend_from_slice(&digits[..decpt]);
        } else {
            out.extend_from_slice(digits);
            out.resize(out.len() + decpt - digits.len(), b'0');
        }
        if decpt < digits.len() {
            if decpt == 0 {
                out.push(b'0');
            }
            out.push(b'.');
            out.extend_from_slice(&digits[decpt..]);
        }
    }
}

/// Whether `zend_dtoa` keeps the trailing zeros of the `n` digits it rounds
/// `v` (not negative) to: `v` is an integer below 1e15 whose digits after
/// the first `n` are exactly half a unit, rounded down to even. Its
/// floating-point fast path cannot decide that tie, and the "small integer"
/// path it falls back to returns the `n` digits without removing zeros
/// (`printf("%G", 4975205)` is `4.97520E+6`).
fn tie_rounded_down(v: f64, n: usize) -> bool {
    if v.fract() != 0.0 || v >= 1e15 {
        return false;
    }
    let int = v as u64;
    let digits = int.checked_ilog10().map_or(1, |l| l as usize + 1);
    if digits <= n {
        return false;
    }
    let unit = 10u64.pow((digits - n) as u32);
    int % unit * 2 == unit && (int / unit).is_multiple_of(2)
}

/// Significant decimal digits of a float, on the stack: the shortest
/// round-trip digits (Ryū), or the requested number correctly rounded
/// (Rust's `{:e}`), without trailing zeros as `zend_dtoa` returns them.
struct Digits {
    buf: [u8; 32],
    len: usize,
    /// The digits when `{:e}` does not fit in `buf` (a precision above 25).
    heap: Vec<u8>,
}

impl std::fmt::Write for Digits {
    /// Appends `s` to the buffer, or fails when it does not fit.
    fn write_str(&mut self, s: &str) -> std::fmt::Result {
        let end = self.len + s.len();
        let dest = self.buf.get_mut(self.len..end).ok_or(std::fmt::Error)?;
        dest.copy_from_slice(s.as_bytes());
        self.len = end;
        Ok(())
    }
}

impl Digits {
    fn new() -> Self {
        Digits { buf: [0; 32], len: 0, heap: Vec::new() }
    }

    /// Shortest round-trip digits of `v` (not negative); returns the decimal
    /// point position (`v = 0.DIGITS × 10^decpt`). Zero is `"0"` at 1.
    fn shortest(&mut self, v: f64) -> i32 {
        if v == 0.0 {
            return self.zero();
        }
        // Ryū's shortest form: "123.456", "0.001", "1e25" or "1.5e-7".
        let mut buffer = ryu::Buffer::new();
        let text = buffer.format_finite(v).as_bytes();
        let (mantissa, exp) = match text.iter().position(|&b| b == b'e') {
            Some(e) => (&text[..e], std::str::from_utf8(&text[e + 1..]).ok().and_then(|x| x.parse().ok())),
            None => (text, Some(0)),
        };
        let point = mantissa.iter().position(|&b| b == b'.').unwrap_or(mantissa.len());
        let mut decpt = point as i32 + exp.unwrap_or(0);
        let mut len = 0;
        for &b in mantissa.iter().filter(|b| b.is_ascii_digit()) {
            if len == 0 && b == b'0' {
                // A leading zero: the point is one place further left.
                decpt -= 1;
            } else {
                self.buf[len] = b;
                len += 1;
            }
        }
        while len > 1 && self.buf[len - 1] == b'0' {
            len -= 1;
        }
        self.len = len;
        decpt
    }

    /// `n` correctly rounded significant digits of `v` (not negative).
    fn rounded(&mut self, v: f64, n: usize) -> i32 {
        if v == 0.0 {
            return self.zero();
        }
        if write!(self, "{v:.*e}", n - 1).is_err() {
            self.heap = format!("{v:.*e}", n - 1).into_bytes();
        }
        self.split(!tie_rounded_down(v, n))
    }

    fn zero(&mut self) -> i32 {
        self.buf[0] = b'0';
        self.len = 1;
        1
    }

    /// `"1.2345e-3"` in place -> digits `"12345"`, returns `-2`. Trailing
    /// zeros are removed when `strip`.
    fn split(&mut self, strip: bool) -> i32 {
        let text: &mut [u8] = if self.heap.is_empty() { &mut self.buf[..self.len] } else { &mut self.heap };
        let e = text.iter().position(|&b| b == b'e').unwrap_or(text.len());
        let exp = std::str::from_utf8(&text[(e + 1).min(text.len())..]).ok().and_then(|x| x.parse::<i32>().ok());
        // The mantissa's digits, moved over the point.
        let mut len = 0;
        for i in 0..e {
            if text[i].is_ascii_digit() {
                text[len] = text[i];
                len += 1;
            }
        }
        while strip && len > 1 && text[len - 1] == b'0' {
            len -= 1;
        }
        if self.heap.is_empty() {
            self.len = len;
        } else {
            self.heap.truncate(len);
        }
        exp.unwrap_or(0) + 1
    }

    fn as_bytes(&self) -> &[u8] {
        if self.heap.is_empty() { &self.buf[..self.len] } else { &self.heap }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn strings_like_php() {
        assert_eq!(to_string(0.1), "0.1");
        assert_eq!(to_string(0.1 + 0.2), "0.3");
        assert_eq!(to_string(1e25), "1.0E+25");
        assert_eq!(to_string(1.0), "1");
        assert_eq!(to_string(-0.0), "-0");
        assert_eq!(to_string(0.00001), "1.0E-5");
        assert_eq!(to_string(0.0001), "0.0001");
        assert_eq!(to_string(123456789012345678.0), "1.2345678901235E+17");
        assert_eq!(to_string(1e15), "1.0E+15");
        assert_eq!(to_string(1e14), "1.0E+14");
        assert_eq!(to_string(1e13), "10000000000000");
        assert_eq!(to_string(f64::INFINITY), "INF");
        // A tie rounded down keeps its zeros, as `zend_dtoa`'s small-integer path does.
        assert_eq!(to_string(100000000000005.0), "1.0000000000000E+14");
        assert_eq!(gcvt(4975205.0, 6, 'E'), "4.97520E+6");
        assert_eq!(gcvt(4975005.0, 6, 'E'), "4.97500E+6");
        assert_eq!(gcvt(125.0, 2, 'E'), "1.2E+2");
        assert_eq!(gcvt(25.0, 1, 'E'), "2.0E+1");
        assert_eq!(gcvt(4975215.0, 6, 'E'), "4.97522E+6");
    }

    #[test]
    fn serialized_like_php() {
        assert_eq!(serialize(0.1 + 0.2), "0.30000000000000004");
        assert_eq!(serialize(1.0), "1");
        assert_eq!(serialize(1e25), "1.0e+25");
        assert_eq!(serialize(-0.0), "-0");
        assert_eq!(serialize(1e15), "1000000000000000");
        assert_eq!(serialize(0.00001), "1.0e-5");
        // Exactly between two shortest forms (…323.25): PHP, as Ryū, prints the even one.
        assert_eq!(serialize(1690060720831323.3), "1690060720831323.2");
        assert_eq!(serialize(-163559682789802.63), "-163559682789802.62");
    }
}
