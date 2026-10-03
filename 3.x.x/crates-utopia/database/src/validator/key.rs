//! PHP `Utopia\Database\Validator\Key`.

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::MAX_UID_DEFAULT_LENGTH;

/// PHP `Utopia\Database\Validator\Key`.
#[derive(Debug, Clone)]
pub struct Key {
    allow_internal: bool,
    max_length: i64,
    message: String,
}

impl Key {
    #[must_use]
    pub fn new(allow_internal: bool, max_length: i64) -> Self {
        Self {
            allow_internal,
            max_length,
            message: format!(
                "Parameter must contain at most {max_length} chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char"
            ),
        }
    }

    #[must_use]
    pub fn default() -> Self {
        Self::new(false, MAX_UID_DEFAULT_LENGTH)
    }

    #[must_use]
    pub fn max_length(&self) -> i64 {
        self.max_length
    }
}

impl Default for Key {
    fn default() -> Self {
        Self::new(false, MAX_UID_DEFAULT_LENGTH)
    }
}

impl Validator for Key {
    fn description(&self) -> String {
        self.message.clone()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(s) = value.as_str() else {
            return false;
        };
        if s.is_empty() {
            return false;
        }
        let leading = s.chars().next().unwrap_or('\0');
        if leading == '_' || leading == '.' || leading == '-' {
            return false;
        }
        let is_internal = leading == '$';
        if is_internal && !self.allow_internal {
            return false;
        }
        if is_internal {
            return matches!(s, "$id" | "$createdAt" | "$updatedAt");
        }
        if s.chars()
            .any(|c| !c.is_ascii_alphanumeric() && c != '_' && c != '-' && c != '.')
        {
            return false;
        }
        if s.chars().count() as i64 > self.max_length {
            return false;
        }
        true
    }
}
