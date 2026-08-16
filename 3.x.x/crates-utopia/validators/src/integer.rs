use crate::{Validator, ValueType};
use serde_json::Value;

/// Validate integers with optional loose string parsing and bit width.
#[derive(Debug, Clone)]
pub struct Integer {
    loose: bool,
    bits: u8,
    unsigned: bool,
}

impl Integer {
    pub fn new() -> Self {
        Self {
            loose: false,
            bits: 32,
            unsigned: false,
        }
    }

    pub fn loose(mut self, loose: bool) -> Self {
        self.loose = loose;
        self
    }

    pub fn bits(mut self, bits: u8) -> Self {
        assert!(
            matches!(bits, 8 | 16 | 32 | 64),
            "Bits must be 8, 16, 32, or 64"
        );
        assert!(
            !(bits == 64 && self.unsigned),
            "64-bit unsigned integers are not supported"
        );
        self.bits = bits;
        self
    }

    pub fn unsigned(mut self, unsigned: bool) -> Self {
        assert!(
            !(self.bits == 64 && unsigned),
            "64-bit unsigned integers are not supported"
        );
        self.unsigned = unsigned;
        self
    }

    fn bounds(&self) -> (i128, i128) {
        if self.unsigned {
            (0, (1i128 << self.bits) - 1)
        } else {
            let half = 1i128 << (self.bits - 1);
            (-half, half - 1)
        }
    }
}

impl Default for Integer {
    fn default() -> Self {
        Self::new()
    }
}

impl Validator for Integer {
    fn description(&self) -> String {
        let (min, max) = self.bounds();
        let signedness = if self.unsigned { "unsigned" } else { "signed" };
        format!(
            "Value must be a valid {signedness} {}-bit integer between {min} and {max}",
            self.bits
        )
    }

    fn value_type(&self) -> ValueType {
        ValueType::Integer
    }

    fn is_valid(&self, value: &Value) -> bool {
        let n = match value {
            Value::Number(num) => {
                if let Some(i) = num.as_i64() {
                    i128::from(i)
                } else if let Some(u) = num.as_u64() {
                    i128::from(u)
                } else {
                    return false;
                }
            }
            Value::String(s) if self.loose => {
                if let Ok(i) = s.parse::<i128>() {
                    i
                } else {
                    return false;
                }
            }
            _ => return false,
        };
        let (min, max) = self.bounds();
        n >= min && n <= max
    }
}
