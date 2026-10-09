use serde_json::Value;

use crate::php::{self, Number};
use crate::{Validator, number_format};

/// `Utopia\Validator\Text`.
#[derive(Debug, Clone)]
pub struct Text {
    pub length: usize,
    pub min: usize,
    pub allow_list: Vec<&'static str>,
}

impl Text {
    /// Text with a maximum length (0 = unlimited) and a minimum of 1 char.
    pub const fn new(length: usize) -> Self {
        Self { length, min: 1, allow_list: Vec::new() }
    }

    pub const fn with_min(length: usize, min: usize) -> Self {
        Self { length, min, allow_list: Vec::new() }
    }

    pub fn with_allow_list(mut self, list: Vec<&'static str>) -> Self {
        self.allow_list = list;
        self
    }

    pub const NUMBERS: [&'static str; 10] = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9"];
    pub const ALPHABET_UPPER: [&'static str; 26] = [
        "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R", "S", "T", "U", "V",
        "W", "X", "Y", "Z",
    ];
    pub const ALPHABET_LOWER: [&'static str; 26] = [
        "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v",
        "w", "x", "y", "z",
    ];
}

impl Validator for Text {
    fn description(&self) -> String {
        let mut message = String::from("Value must be a valid string");
        if self.min == self.length {
            message.push_str(&format!(" and exactly {} chars", self.length));
        } else {
            if self.min != 0 {
                message.push_str(&format!(" and at least {} chars", self.min));
            }
            if self.length != 0 {
                message.push_str(&format!(" and no longer than {} chars", self.length));
            }
        }
        if !self.allow_list.is_empty() {
            message.push_str(&format!(" and only consist of '{}' chars", self.allow_list.join(", ")));
        }
        message
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Value::String(s) = value else {
            return false;
        };
        let len = s.chars().count();
        if len < self.min {
            return false;
        }
        if self.length != 0 && len > self.length {
            return false;
        }
        if !self.allow_list.is_empty() {
            // PHP compares byte by byte (`str_split`).
            for byte in s.bytes() {
                let allowed = self.allow_list.iter().any(|a| a.len() == 1 && a.as_bytes()[0] == byte);
                if !allowed {
                    return false;
                }
            }
        }
        true
    }
}

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

    fn is_valid(&self, value: &Value) -> bool {
        if self.loose {
            match value {
                Value::String(s) if s == "true" || s == "false" || s == "1" || s == "0" => {
                    return true;
                }
                Value::Number(n) if (n.as_i64() == Some(1) || n.as_i64() == Some(0)) && (n.is_i64() || n.is_u64()) => {
                    return true;
                }
                _ => {}
            }
        }
        value.is_boolean()
    }
}

/// `Utopia\Validator\Integer` (signed, 8/16/32/64 bit).
#[derive(Debug, Clone, Copy)]
pub struct Integer {
    pub loose: bool,
    pub bits: u32,
}

impl Default for Integer {
    fn default() -> Self {
        Self { loose: false, bits: 32 }
    }
}

impl Integer {
    fn bounds(&self) -> (i128, i128) {
        let min = -(1i128 << (self.bits - 1));
        let max = (1i128 << (self.bits - 1)) - 1;
        (min, max)
    }

    /// The integer value PHP would see (after `+ 0` when loose).
    pub fn value(&self, value: &Value) -> Option<i64> {
        if self.loose {
            match php::numeric(value)? {
                Number::Int(i) => Some(i),
                Number::Float(_) => None,
            }
        } else {
            match value {
                Value::Number(n) if n.is_i64() || n.is_u64() => n.as_i64(),
                _ => None,
            }
        }
    }
}

impl Validator for Integer {
    fn description(&self) -> String {
        let (min, max) = self.bounds();
        format!(
            "Value must be a valid signed {}-bit integer between {} and {}",
            self.bits,
            number_format(min),
            number_format(max)
        )
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(v) = self.value(value) else {
            return false;
        };
        let (min, max) = self.bounds();
        (v as i128) >= min && (v as i128) <= max
    }
}

/// `Utopia\Validator\Numeric`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Numeric;

impl Validator for Numeric {
    fn description(&self) -> String {
        "Value must be a valid number".to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        php::numeric(value).is_some()
    }
}

/// `Utopia\Validator\Range` with integer format.
#[derive(Debug, Clone, Copy)]
pub struct Range {
    pub min: i64,
    pub max: i64,
}

impl Range {
    pub const fn new(min: i64, max: i64) -> Self {
        Self { min, max }
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
        format!(
            "Value must be a valid range between {} and {}",
            number_format(self.min as i128),
            number_format(self.max as i128)
        )
    }

    fn is_valid(&self, value: &Value) -> bool {
        match Self::value(value) {
            Some(v) => self.min <= v && v <= self.max,
            None => false,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn text_descriptions() {
        assert_eq!(
            Text::new(128).description(),
            "Value must be a valid string and at least 1 chars and no longer than 128 chars"
        );
        assert_eq!(Text::with_min(128, 0).description(), "Value must be a valid string and no longer than 128 chars");
        let mut list: Vec<&'static str> = Vec::new();
        list.extend(Text::NUMBERS);
        let t = Text::new(36).with_allow_list(list);
        assert_eq!(
            t.description(),
            "Value must be a valid string and at least 1 chars and no longer than 36 chars and only consist of '0, 1, 2, 3, 4, 5, 6, 7, 8, 9' chars"
        );
        assert!(t.is_valid(&json!("123")));
        assert!(!t.is_valid(&json!("12a")));
    }

    #[test]
    fn text_lengths() {
        let t = Text::new(3);
        assert!(!t.is_valid(&json!("")));
        assert!(t.is_valid(&json!("äöü")));
        assert!(!t.is_valid(&json!("abcd")));
        assert!(!t.is_valid(&json!(1)));
    }

    #[test]
    fn booleans() {
        assert!(Boolean::LOOSE.is_valid(&json!("false")));
        assert!(Boolean::LOOSE.is_valid(&json!(0)));
        assert!(!Boolean::STRICT.is_valid(&json!("false")));
        assert!(Boolean::STRICT.is_valid(&json!(false)));
        assert!(!Boolean::LOOSE.is_valid(&json!(2)));
    }

    #[test]
    fn ranges() {
        let r = Range::new(4, 128);
        assert!(r.is_valid(&json!(4)));
        assert!(r.is_valid(&json!("15")));
        assert!(!r.is_valid(&json!(1)));
        assert!(!r.is_valid(&json!("1.5")));
        assert_eq!(Range::new(60, 31536000).description(), "Value must be a valid range between 60 and 31,536,000");
    }

    #[test]
    fn integers() {
        let i = Integer::default();
        assert!(i.is_valid(&json!(8)));
        assert!(!i.is_valid(&json!("8")));
        assert!(!i.is_valid(&json!(8.0)));
        assert_eq!(
            i.description(),
            "Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647"
        );
    }
}
