use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::Email;

/// Validate that an email is not disposable (PHP `EmailNotDisposable`).
#[derive(Debug, Clone, Default)]
pub struct EmailNotDisposable;

impl EmailNotDisposable {
    /// Create a non-disposable validator.
    pub fn new() -> Self {
        Self
    }
}

impl Validator for EmailNotDisposable {
    fn description(&self) -> String {
        "Value must be a valid email address that is not from a disposable email service".into()
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
            .map(|email| email.is_valid() && !email.is_disposable())
            .unwrap_or(false)
    }
}
