//! Request parameter validators. Each accepts a string that [`Email::new`]
//! parses and [`Email::is_valid`] accepts, and adds its own rule. Values that
//! are not strings are invalid.
//!
//! PHP: `Utopia\Emails\Validator\*`.

use serde_json::Value;
use utopia_validators::Validator;

use crate::Email;

/// The value type every email validator accepts.
///
/// PHP: `Validator::TYPE_STRING`, what every email validator's `getType()`
/// returns.
const TYPE: &str = "string";

/// Parses `value` and applies `rule`; an address that does not parse or is
/// not valid fails.
///
/// PHP: `try { new Email(...) } catch { false }`.
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
            /// The type of value this validator accepts: `string`.
            ///
            /// PHP: `getType()`.
            pub fn value_type(&self) -> &'static str {
                TYPE
            }
        }
    };
}

/// Validates an email address; with `allow_empty`, also accepts `""`.
///
/// PHP: `Validator\Email`.
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailValidator {
    /// Whether the empty string is accepted.
    pub allow_empty: bool,
}

impl EmailValidator {
    /// What the validator reports a valid value to be.
    pub const DESCRIPTION: &'static str = "Value must be a valid email address";

    /// A validator that accepts `""` when `allow_empty` is set.
    ///
    /// PHP: `new Email($allowEmpty)`.
    pub fn new(allow_empty: bool) -> Self {
        Self { allow_empty }
    }

    /// Whether a string, of any bytes, is a value this validator accepts.
    ///
    /// PHP: `isValid()` on a string.
    pub fn is_valid_address(&self, value: impl AsRef<[u8]>) -> bool {
        let value = value.as_ref();
        if self.allow_empty && value.is_empty() {
            return true;
        }
        check(value, |_| true)
    }
}

string_only!(EmailValidator);

/// Validates an email address whose domain is valid
/// ([`Email::has_valid_domain`]).
///
/// PHP: `Validator\EmailDomain`.
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailDomain;

impl EmailDomain {
    /// What the validator reports a valid value to be.
    pub const DESCRIPTION: &'static str = "Value must be a valid email address with a valid domain";

    /// Whether a string, of any bytes, is a value this validator accepts.
    ///
    /// PHP: `isValid()` on a string.
    pub fn is_valid_address(&self, value: impl AsRef<[u8]>) -> bool {
        check(value.as_ref(), Email::has_valid_domain)
    }
}

string_only!(EmailDomain);

/// Validates an email address whose local part is valid
/// ([`Email::has_valid_local`]).
///
/// PHP: `Validator\EmailLocal`.
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailLocal;

impl EmailLocal {
    /// What the validator reports a valid value to be.
    pub const DESCRIPTION: &'static str = "Value must be a valid email address with a valid local part";

    /// Whether a string, of any bytes, is a value this validator accepts.
    ///
    /// PHP: `isValid()` on a string.
    pub fn is_valid_address(&self, value: impl AsRef<[u8]>) -> bool {
        check(value.as_ref(), Email::has_valid_local)
    }
}

string_only!(EmailLocal);

/// Validates an email address on a corporate domain
/// ([`Email::is_corporate`]).
///
/// PHP: `Validator\EmailCorporate`.
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailCorporate;

impl EmailCorporate {
    /// What the validator reports a valid value to be.
    pub const DESCRIPTION: &'static str = "Value must be a valid email address from a corporate domain";

    /// Whether a string, of any bytes, is a value this validator accepts.
    ///
    /// PHP: `isValid()` on a string.
    pub fn is_valid_address(&self, value: impl AsRef<[u8]>) -> bool {
        check(value.as_ref(), Email::is_corporate)
    }
}

string_only!(EmailCorporate);

/// Validates an email address that is not on a disposable domain
/// ([`Email::is_disposable`]).
///
/// PHP: `Validator\EmailNotDisposable`.
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailNotDisposable;

impl EmailNotDisposable {
    /// What the validator reports a valid value to be.
    pub const DESCRIPTION: &'static str =
        "Value must be a valid email address that is not from a disposable email service";

    /// Whether a string, of any bytes, is a value this validator accepts.
    ///
    /// PHP: `isValid()` on a string.
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
