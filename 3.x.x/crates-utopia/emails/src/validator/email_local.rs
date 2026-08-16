use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::Email;

/// Validate that an email has a valid local part (PHP `EmailLocal`).
#[derive(Debug, Clone, Default)]
pub struct EmailLocal;

impl EmailLocal {
    /// Create a local-part validator.
    pub fn new() -> Self {
        Self
    }
}

impl Validator for EmailLocal {
    fn description(&self) -> String {
        "Value must be a valid email address with a valid local part".into()
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
        Email::new(raw)
            .map(|email| email.is_valid() && email.has_valid_local())
            .unwrap_or(false)
    }
}
