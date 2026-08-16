//! Users-module-local validators. Rust port of the pieces of
//! `Utopia\Emails\Validator\Email` the Users API needs. `utopia-validators`
//! has no email validator (email parsing lives in the separate
//! `utopia-php/emails` library, not yet ported), so this is a minimal
//! regex-based stand-in: syntactic validity only, no disposable/free/
//! corporate/canonical classification (see `super::base` module docs for
//! how that gap is handled at the call sites that need it).

use std::sync::OnceLock;

use regex::Regex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

fn email_re() -> &'static Regex {
    static RE: OnceLock<Regex> = OnceLock::new();
    RE.get_or_init(|| {
        Regex::new(r"^[^@\s]+@[^@\s]+\.[^@\s]+$").expect("static email regex is valid")
    })
}

/// PHP `Utopia\Emails\Validator\Email(bool $allowEmpty = false)`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Email {
    allow_empty: bool,
}

impl Email {
    #[must_use]
    pub fn new(allow_empty: bool) -> Self {
        Self { allow_empty }
    }
}

impl Validator for Email {
    fn description(&self) -> String {
        "Value must be a valid email address".to_string()
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
        s.len() <= 320 && email_re().is_match(s)
    }
}
