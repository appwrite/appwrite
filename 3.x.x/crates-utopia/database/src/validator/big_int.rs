//! PHP `Utopia\Database\Validator\BigInt`.

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

/// PHP `Utopia\Database\Validator\BigInt`.
#[derive(Debug, Clone)]
pub struct BigInt {
    signed: bool,
    support_unsigned_64: bool,
}

impl BigInt {
    pub const SIGNED_MIN: &'static str = "-9223372036854775808";
    pub const SIGNED_MAX: &'static str = "9223372036854775807";
    pub const UNSIGNED_MAX: &'static str = "18446744073709551615";

    #[must_use]
    pub fn new(signed: bool, support_unsigned_64: bool) -> Self {
        Self {
            signed,
            support_unsigned_64,
        }
    }

    #[must_use]
    pub fn is_integer_string(value: &str, signed: bool) -> bool {
        let re = if signed {
            regex::Regex::new(r"^-?\d+$").expect("int")
        } else {
            regex::Regex::new(r"^\d+$").expect("uint")
        };
        re.is_match(value)
    }

    #[must_use]
    pub fn normalize_unsigned_string(value: &str) -> String {
        let value = value.trim().trim_start_matches('0');
        if value.is_empty() {
            "0".into()
        } else {
            value.to_owned()
        }
    }

    #[must_use]
    pub fn compare_unsigned_strings(a: &str, b: &str) -> i32 {
        let a = Self::normalize_unsigned_string(a);
        let b = Self::normalize_unsigned_string(b);
        match a.len().cmp(&b.len()) {
            std::cmp::Ordering::Less => -1,
            std::cmp::Ordering::Greater => 1,
            std::cmp::Ordering::Equal if a == b => 0,
            std::cmp::Ordering::Equal if a < b => -1,
            std::cmp::Ordering::Equal => 1,
        }
    }

    #[must_use]
    pub fn fits_php_int(value: &str, signed: bool) -> bool {
        if !Self::is_integer_string(value, signed) {
            return false;
        }
        let php_max = i64::MAX.to_string();
        let php_min_abs = i64::MIN.unsigned_abs().to_string();
        if signed && value.starts_with('-') {
            let digits = Self::normalize_unsigned_string(&value[1..]);
            return Self::compare_unsigned_strings(&digits, &php_min_abs) <= 0;
        }
        let digits = Self::normalize_unsigned_string(value);
        Self::compare_unsigned_strings(&digits, &php_max) <= 0
    }

    #[must_use]
    pub fn fits_big_int_range(value: &str, signed: bool, support_unsigned_64: bool) -> bool {
        if !Self::is_integer_string(value, signed) {
            return false;
        }
        if signed {
            if value.starts_with('-') {
                let digits = Self::normalize_unsigned_string(&value[1..]);
                let min_abs = Self::SIGNED_MIN
                    .trim_start_matches('-')
                    .trim_start_matches('0');
                return Self::compare_unsigned_strings(&digits, min_abs) <= 0;
            }
            return Self::compare_unsigned_strings(value, Self::SIGNED_MAX) <= 0;
        }
        let max = if support_unsigned_64 {
            Self::UNSIGNED_MAX
        } else {
            Self::SIGNED_MAX
        };
        Self::compare_unsigned_strings(value, max) <= 0
    }

    #[must_use]
    pub fn format_integer_string(value: &str) -> String {
        let negative = value.starts_with('-');
        let digits = Self::normalize_unsigned_string(if negative { &value[1..] } else { value });
        let mut formatted = String::new();
        for (i, ch) in digits.chars().rev().enumerate() {
            if i > 0 && i % 3 == 0 {
                formatted.insert(0, ',');
            }
            formatted.insert(0, ch);
        }
        if negative {
            format!("-{formatted}")
        } else {
            formatted
        }
    }
}

impl Validator for BigInt {
    fn description(&self) -> String {
        if self.signed {
            format!(
                "Value must be a valid signed 64-bit integer between {} and {}",
                Self::format_integer_string(Self::SIGNED_MIN),
                Self::format_integer_string(Self::SIGNED_MAX)
            )
        } else {
            let max = if self.support_unsigned_64 {
                Self::UNSIGNED_MAX
            } else {
                Self::SIGNED_MAX
            };
            format!(
                "Value must be a valid unsigned 64-bit integer between 0 and {}",
                Self::format_integer_string(max)
            )
        }
    }

    fn value_type(&self) -> ValueType {
        ValueType::Integer
    }

    fn is_valid(&self, value: &Value) -> bool {
        if let Some(i) = value.as_i64() {
            return if self.signed { true } else { i >= 0 };
        }
        if let Some(u) = value.as_u64() {
            return if self.signed {
                i64::try_from(u).is_ok()
            } else {
                true
            };
        }
        let Some(s) = value.as_str() else {
            return false;
        };
        Self::fits_big_int_range(s, self.signed, self.support_unsigned_64)
    }
}
