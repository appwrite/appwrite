//! Password and phone validators. Rust port of
//! `Appwrite\Auth\Validator\Password` and `Appwrite\Auth\Validator\Phone`
//! (`src/Appwrite/Auth/Validator/{Password,Phone}.php`).

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

/// PHP `Appwrite\Auth\Validator\Password`: length-only password check
/// (project-specific strength/dictionary/history policy is layered on top,
/// e.g. via `appwrite-hooks::PASSWORD_VALIDATOR`).
#[derive(Debug, Clone, Copy, Default)]
pub struct Password {
    allow_empty: bool,
}

impl Password {
    /// PHP `min` bound (`Password::MIN_LENGTH` equivalent).
    pub const MIN_LENGTH: usize = 8;
    /// PHP `max` bound (`Password::MAX_LENGTH` equivalent).
    pub const MAX_LENGTH: usize = 256;

    /// PHP `new Password(bool $allowEmpty = false)`.
    #[must_use]
    pub fn new(allow_empty: bool) -> Self {
        Self { allow_empty }
    }
}

impl Validator for Password {
    fn description(&self) -> String {
        "Password must be between 8 and 256 characters long.".to_string()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(s) = value.as_str() else {
            return false;
        };
        if self.allow_empty && s.is_empty() {
            return true;
        }
        let len = s.chars().count();
        (Self::MIN_LENGTH..=Self::MAX_LENGTH).contains(&len)
    }
}

/// PHP `Appwrite\Auth\Validator\Phone extends Utopia\Validator\Phone`: same
/// E.164-ish validation as `utopia_validators::Phone`, with Appwrite's
/// override description.
#[derive(Debug, Clone, Default)]
pub struct Phone {
    inner: utopia_validators::Phone,
}

impl Phone {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Validator for Phone {
    fn description(&self) -> String {
        "Phone number must start with a '+' can have a maximum of fifteen digits.".to_string()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        self.inner.is_valid(value)
    }
}
