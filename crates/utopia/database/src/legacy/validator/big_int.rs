//! [`BigInt`]: 64-bit integers, as PHP integers or integer strings (an
//! unsigned 64-bit value does not fit a PHP integer).
//!
//! PHP: `Utopia\Database\Validator\BigInt`.

use crate::value::{Str, Value};

/// `BigInt::SIGNED_MIN`.
pub const SIGNED_MIN: &str = "-9223372036854775808";
/// `BigInt::SIGNED_MAX`.
pub const SIGNED_MAX: &str = "9223372036854775807";
/// `BigInt::UNSIGNED_MAX`.
pub const UNSIGNED_MAX: &str = "18446744073709551615";

/// A signed or unsigned 64-bit integer: a PHP integer (any, when signed;
/// not negative, when unsigned) or a string of digits in range. Without
/// `support_unsigned_64bit`, unsigned values stop at the signed maximum.
///
/// ```
/// use utopia_database::validator::big_int::BigInt;
/// use utopia_database::value::{Value, string};
///
/// let unsigned = BigInt::new(false, true);
/// assert!(unsigned.is_valid(&string("18446744073709551615")));
/// assert!(!unsigned.is_valid(&Value::Int(-1)));
/// assert_eq!(BigInt::format_integer_string("-1234567"), "-1,234,567");
/// ```
///
/// PHP: `Utopia\Database\Validator\BigInt` (`new BigInt($signed, $supportUnsigned64Bit)`).
#[derive(Clone, Debug)]
pub struct BigInt {
    signed: bool,
    support_unsigned_64bit: bool,
}

impl BigInt {
    /// `new BigInt($signed, $supportUnsigned64Bit)`.
    pub fn new(signed: bool, support_unsigned_64bit: bool) -> Self {
        Self { signed, support_unsigned_64bit }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        if self.signed {
            return format!(
                "Value must be a valid signed 64-bit integer between {} and {}",
                Self::format_integer_string(SIGNED_MIN),
                Self::format_integer_string(SIGNED_MAX)
            );
        }
        let max = if self.support_unsigned_64bit { UNSIGNED_MAX } else { SIGNED_MAX };
        format!("Value must be a valid unsigned 64-bit integer between 0 and {}", Self::format_integer_string(max))
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Int(i) => self.signed || *i >= 0,
            Value::Str(s) => Self::fits_big_int_range(s.as_bytes(), self.signed, self.support_unsigned_64bit),
            _ => false,
        }
    }

    /// `getType()`: `bigint` (`Database::VAR_BIGINT`).
    pub fn type_name(&self) -> &'static str {
        "bigint"
    }

    /// `BigInt::isIntegerString($value, $signed)`: `/^-?\d+$/` (or
    /// `/^\d+$/` unsigned), where `$` also matches before a final newline.
    pub fn is_integer_string(value: &[u8], signed: bool) -> bool {
        let body = value.strip_suffix(b"\n").unwrap_or(value);
        let digits = match body.split_first() {
            Some((b'-', rest)) if signed => rest,
            _ => body,
        };
        !digits.is_empty() && digits.iter().all(u8::is_ascii_digit)
    }

    /// `BigInt::fitsPhpInt($value, $signed)`: an integer string within PHP's
    /// integer range.
    pub fn fits_php_int(value: &[u8], signed: bool) -> bool {
        if !Self::is_integer_string(value, signed) {
            return false;
        }
        if signed && let Some(rest) = value.strip_prefix(b"-") {
            let digits = Self::normalize_unsigned_string(rest);
            return Self::compare_unsigned_strings(&digits, b"9223372036854775808") <= 0;
        }
        let digits = Self::normalize_unsigned_string(value);
        Self::compare_unsigned_strings(&digits, SIGNED_MAX.as_bytes()) <= 0
    }

    /// `BigInt::fitsBigIntRange($value, $signed, $supportUnsigned64Bit)`.
    pub fn fits_big_int_range(value: &[u8], signed: bool, support_unsigned_64bit: bool) -> bool {
        if !Self::is_integer_string(value, signed) {
            return false;
        }
        if signed {
            if let Some(rest) = value.strip_prefix(b"-") {
                let digits = Self::normalize_unsigned_string(rest);
                return Self::compare_unsigned_strings(&digits, b"9223372036854775808") <= 0;
            }
            return Self::compare_unsigned_strings(value, SIGNED_MAX.as_bytes()) <= 0;
        }
        let max = if support_unsigned_64bit { UNSIGNED_MAX } else { SIGNED_MAX };
        Self::compare_unsigned_strings(value, max.as_bytes()) <= 0
    }

    /// `BigInt::normalizeUnsignedString($value)`: trimmed, without leading
    /// zeros, `0` when nothing is left.
    pub fn normalize_unsigned_string(value: &[u8]) -> Vec<u8> {
        let trimmed = php_std::string::trim(value, php_std::string::TRIM_CHARACTERS);
        let stripped = php_std::string::ltrim(trimmed, b"0");
        if stripped.is_empty() { b"0".to_vec() } else { stripped.to_vec() }
    }

    /// `BigInt::compareUnsignedStrings($a, $b)`: -1, 0 or 1, by length of the
    /// normalized strings, then by PHP's `<` on them.
    pub fn compare_unsigned_strings(a: &[u8], b: &[u8]) -> i64 {
        let a = Self::normalize_unsigned_string(a);
        let b = Self::normalize_unsigned_string(b);
        if a.len() != b.len() {
            return if a.len() < b.len() { -1 } else { 1 };
        }
        if a == b {
            return 0;
        }
        let (a, b): (Value, Value) = (Value::Str(Str::from(a)), Value::Str(Str::from(b)));
        if a.compare(&b) < 0 { -1 } else { 1 }
    }

    /// `BigInt::formatIntegerString($value)`: normalized, with commas between
    /// thousands (`/\B(?=(\d{3})+(?!\d))/`).
    pub fn format_integer_string(value: &str) -> String {
        let (negative, rest) = match value.strip_prefix('-') {
            Some(rest) => (true, rest),
            None => (false, value),
        };
        let s = Self::normalize_unsigned_string(rest.as_bytes());
        let is_word = |c: u8| c.is_ascii_alphanumeric() || c == b'_';
        let mut out = Vec::with_capacity(s.len() + s.len() / 3 + 1);
        for (p, &c) in s.iter().enumerate() {
            if p > 0 && is_word(s[p - 1]) && is_word(c) {
                let run = s[p..].iter().take_while(|d| d.is_ascii_digit()).count();
                if run >= 3 && run % 3 == 0 {
                    out.push(b',');
                }
            }
            out.push(c);
        }
        let formatted = String::from_utf8_lossy(&out).into_owned();
        if negative { format!("-{formatted}") } else { formatted }
    }
}

super::validator!(BigInt, utopia_validators::Type::Mixed, false);
