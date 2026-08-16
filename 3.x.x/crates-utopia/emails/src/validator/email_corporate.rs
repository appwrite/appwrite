use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::Email;

/// Validate that an email is corporate - not free or disposable (PHP `EmailCorporate`).
#[derive(Debug, Clone, Default)]
pub struct EmailCorporate;

impl EmailCorporate {
    /// Create a corporate-email validator.
    pub fn new() -> Self {
        Self
    }
}

impl Validator for EmailCorporate {
    fn description(&self) -> String {
        "Value must be a valid email address from a corporate domain".into()
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
            .map(|email| email.is_valid() && email.is_corporate())
            .unwrap_or(false)
    }
}
