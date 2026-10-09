//! `Utopia\Emails\Validator\*`: request parameter validators. Each accepts
//! a string that [`Email::new`] parses and [`Email::is_valid`] accepts, and
//! adds its own rule. Values that are not strings are invalid.

use serde_json::Value;
use utopia_validators::Validator;

use crate::Email;

/// `Validator::TYPE_STRING`, what every email validator's `getType()` returns.
const TYPE: &str = "string";

/// Parses `value` and applies `rule` (PHP: `try { new Email(...) } catch { false }`).
fn check(value: &[u8], rule: impl FnOnce(&Email) -> bool) -> bool {
    Email::new(value).is_ok_and(|email| email.is_valid() && rule(&email))
}

macro_rules! string_only {
    ($ty:ty) => {
        impl Validator for $ty {
            fn description(&self) -> String {
                <$ty>::DESCRIPTION.to_owned()
            }

            fn is_valid(&self, value: &Value) -> bool {
                match value {
                    Value::String(s) => self.is_valid_address(s),
                    _ => false,
                }
            }

            fn is_array(&self) -> bool {
                false
            }
        }

        impl $ty {
            /// `getType()`.
            pub fn value_type(&self) -> &'static str {
                TYPE
            }
        }
    };
}

/// `Validator\Email`: a valid email address; with `allow_empty`, also `""`.
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailValidator {
    pub allow_empty: bool,
}

impl EmailValidator {
    pub const DESCRIPTION: &'static str = "Value must be a valid email address";

    /// `new Email($allowEmpty)`.
    pub fn new(allow_empty: bool) -> Self {
        Self { allow_empty }
    }

    /// `isValid()` on a string (any bytes).
    pub fn is_valid_address(&self, value: impl AsRef<[u8]>) -> bool {
        let value = value.as_ref();
        if self.allow_empty && value.is_empty() {
            return true;
        }
        check(value, |_| true)
    }
}

string_only!(EmailValidator);

/// `Validator\EmailDomain`: a valid address whose domain is valid
/// ([`Email::has_valid_domain`]).
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailDomain;

impl EmailDomain {
    pub const DESCRIPTION: &'static str = "Value must be a valid email address with a valid domain";

    /// `isValid()` on a string (any bytes).
    pub fn is_valid_address(&self, value: impl AsRef<[u8]>) -> bool {
        check(value.as_ref(), Email::has_valid_domain)
    }
}

string_only!(EmailDomain);

/// `Validator\EmailLocal`: a valid address whose local part is valid
/// ([`Email::has_valid_local`]).
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailLocal;

impl EmailLocal {
    pub const DESCRIPTION: &'static str = "Value must be a valid email address with a valid local part";

    /// `isValid()` on a string (any bytes).
    pub fn is_valid_address(&self, value: impl AsRef<[u8]>) -> bool {
        check(value.as_ref(), Email::has_valid_local)
    }
}

string_only!(EmailLocal);

/// `Validator\EmailCorporate`: a valid address on a corporate domain
/// ([`Email::is_corporate`]).
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailCorporate;

impl EmailCorporate {
    pub const DESCRIPTION: &'static str = "Value must be a valid email address from a corporate domain";

    /// `isValid()` on a string (any bytes).
    pub fn is_valid_address(&self, value: impl AsRef<[u8]>) -> bool {
        check(value.as_ref(), Email::is_corporate)
    }
}

string_only!(EmailCorporate);

/// `Validator\EmailNotDisposable`: a valid address not on a disposable
/// domain ([`Email::is_disposable`]).
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailNotDisposable;

impl EmailNotDisposable {
    pub const DESCRIPTION: &'static str =
        "Value must be a valid email address that is not from a disposable email service";

    /// `isValid()` on a string (any bytes).
    pub fn is_valid_address(&self, value: impl AsRef<[u8]>) -> bool {
        check(value.as_ref(), |email| !email.is_disposable())
    }
}

string_only!(EmailNotDisposable);

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn validators() {
        let v = EmailValidator::default();
        assert!(v.is_valid(&Value::String("  John@X.COM ".into())));
        assert!(!v.is_valid(&Value::String(String::new())));
        assert!(!v.is_valid(&Value::Null));
        assert!(EmailValidator::new(true).is_valid(&Value::String(String::new())));
        assert!(EmailLocal.is_valid_address("a.b+c@example.com"));
        assert!(!EmailLocal.is_valid_address("a..b@example.com"));
        assert!(EmailDomain.is_valid_address("a@mail.example.co.uk"));
        assert!(!EmailDomain.is_valid_address("a@example.invalidtld"));
        assert!(!EmailCorporate.is_valid_address("a@gmail.com"));
        assert!(EmailCorporate.is_valid_address("a@company.org"));
        assert!(!EmailNotDisposable.is_valid_address("a@mailinator.com"));
        assert_eq!(EmailNotDisposable.value_type(), "string");
    }
}
