//! PHP `Utopia\Database\Validator\ByteLength`.

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

/// PHP `Utopia\Database\Validator\ByteLength`.
#[derive(Debug, Clone)]
pub struct ByteLength {
    max: usize,
}

impl ByteLength {
    #[must_use]
    pub fn new(max: usize) -> Self {
        Self { max }
    }
}

impl Validator for ByteLength {
    fn description(&self) -> String {
        format!(
            "Value must be a valid string no longer than {} bytes",
            self.max
        )
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(s) = value.as_str() else {
            return false;
        };
        self.max == 0 || s.len() <= self.max
    }
}
