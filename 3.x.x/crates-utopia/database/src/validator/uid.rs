//! PHP `Utopia\Database\Validator\UID`.

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::MAX_UID_DEFAULT_LENGTH;
use crate::validator::Key;

/// PHP `Utopia\Database\Validator\UID`.
#[derive(Debug, Clone)]
pub struct Uid {
    inner: Key,
    max_length: i64,
}

impl Uid {
    #[must_use]
    pub fn new(max_length: i64) -> Self {
        Self {
            inner: Key::new(false, max_length),
            max_length,
        }
    }
}

impl Default for Uid {
    fn default() -> Self {
        Self::new(MAX_UID_DEFAULT_LENGTH)
    }
}

impl Validator for Uid {
    fn description(&self) -> String {
        format!(
            "UID must contain at most {} chars. Valid chars are a-z, A-Z, 0-9, and underscore. Can't start with a leading underscore",
            self.max_length
        )
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        self.inner.is_valid(value)
    }
}
