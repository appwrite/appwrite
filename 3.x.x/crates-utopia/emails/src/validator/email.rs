use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::Email as EmailParser;

/// Validate that a value is a valid email address (PHP `Utopia\Emails\Validator\Email`).
#[derive(Debug, Clone)]
pub struct Email {
    allow_empty: bool,
}

impl Email {
    /// PHP `new Email($allowEmpty = false)`.
    pub fn new(allow_empty: bool) -> Self {
        Self { allow_empty }
    }
}

impl Default for Email {
    fn default() -> Self {
        Self::new(false)
    }
}

impl Validator for Email {
    fn description(&self) -> String {
        "Value must be a valid email address".into()
    }

    fn is_array(&self) -> bool {
        false
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(raw) = value.as_str() else {
            return false;
        };
        if self.allow_empty && raw.is_empty() {
            return true;
        }
        EmailParser::new(raw)
            .map(|email| email.is_valid())
            .unwrap_or(false)
    }
}
