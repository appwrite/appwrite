//! PHP `Utopia\Database\Validator\Label`.

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::MAX_UID_DEFAULT_LENGTH;
use crate::validator::Key;

/// PHP `Utopia\Database\Validator\Label`.
#[derive(Debug, Clone)]
pub struct Label {
    inner: Key,
    max_length: i64,
}

impl Label {
    #[must_use]
    pub fn new(allow_internal: bool, max_length: i64) -> Self {
        Self {
            inner: Key::new(allow_internal, max_length),
            max_length,
        }
    }
}

impl Default for Label {
    fn default() -> Self {
        Self::new(false, MAX_UID_DEFAULT_LENGTH)
    }
}

impl Validator for Label {
    fn description(&self) -> String {
        format!(
            "Value must be a valid string between 1 and {} chars containing only alphanumeric chars",
            self.max_length
        )
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        if !self.inner.is_valid(value) {
            return false;
        }
        let Some(s) = value.as_str() else {
            return false;
        };
        s.chars().all(|c| c.is_ascii_alphanumeric())
    }
}
