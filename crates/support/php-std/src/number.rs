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

/// `(string) $float` with PHP's default `precision = 14`.
pub fn to_string(f: f64) -> String {
    gcvt(f, 14, 'E')
}

/// `json_encode($float)` / `var_export` with `serialize_precision = -1`.
pub fn serialize(f: f64) -> String {
    gcvt(f, -1, 'e')
}

/// `zend_gcvt(value, precision, '.', exp_char)`, with PHP's special values.
/// `precision` is the ini value: `-1` selects the shortest round-trip digits.
pub fn gcvt(value: f64, precision: i32, exp_char: char) -> String {
    if value.is_nan() {
        return "NAN".into();
    }
    if value.is_infinite() {
        return if value > 0.0 { "INF".into() } else { "-INF".into() };
    }
    let (ndigit, digits, decpt) = if precision < 0 {
        let (d, p) = shortest_digits(value.abs());
        (17, d, p)
    } else {
        let n = precision.max(1) as usize;
        let (d, p) = rounded_digits(value.abs(), n);
        (n as i32, d, p)
    };
    let mut out = String::with_capacity(digits.len() + 8);
    if value.is_sign_negative() {
        out.push('-');
    }
    if decpt < -3 || decpt > ndigit {
        let exp = decpt - 1;
        let mut chars = digits.chars();
        out.push(chars.next().unwrap_or('0'));
        out.push('.');
        let rest: String = chars.collect();
        out.push_str(if rest.is_empty() { "0" } else { &rest });
        out.push(exp_char);
        out.push(if exp < 0 { '-' } else { '+' });
        out.push_str(&exp.unsigned_abs().to_string());
    } else if decpt < 0 {
        out.push_str("0.");
        for _ in decpt..0 {
            out.push('0');
        }
        out.push_str(&digits);
    } else {
        let d = digits.as_bytes();
        for i in 0..decpt as usize {
            out.push(d.get(i).map(|b| *b as char).unwrap_or('0'));
        }
        if (decpt as usize) < d.len() {
            if decpt == 0 {
                out.push('0');
            }
            out.push('.');
            out.push_str(&digits[decpt as usize..]);
        }
    }
    out
}

/// Shortest round-trip significant digits (no trailing zeros) and the
/// decimal point position (`value = 0.DIGITS × 10^decpt`). Zero is `("0", 1)`.
fn shortest_digits(v: f64) -> (String, i32) {
    if v == 0.0 {
        return ("0".into(), 1);
    }
    split_exp(&format!("{v:e}"))
}

/// `n` correctly rounded significant digits, trailing zeros removed.
fn rounded_digits(v: f64, n: usize) -> (String, i32) {
    if v == 0.0 {
        return ("0".into(), 1);
    }
    split_exp(&format!("{v:.*e}", n - 1))
}

/// `"1.2345e-3"` -> (`"12345"`, `-2`).
fn split_exp(s: &str) -> (String, i32) {
    let (mantissa, exp) = s.split_once('e').unwrap_or((s, "0"));
    let exp: i32 = exp.parse().unwrap_or(0);
    let mut digits: String = mantissa.chars().filter(char::is_ascii_digit).collect();
    while digits.len() > 1 && digits.ends_with('0') {
        digits.pop();
    }
    (digits, exp + 1)
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
    }

    #[test]
    fn serialized_like_php() {
        assert_eq!(serialize(0.1 + 0.2), "0.30000000000000004");
        assert_eq!(serialize(1.0), "1");
        assert_eq!(serialize(1e25), "1.0e+25");
        assert_eq!(serialize(-0.0), "-0");
        assert_eq!(serialize(1e15), "1000000000000000");
        assert_eq!(serialize(0.00001), "1.0e-5");
    }
}
