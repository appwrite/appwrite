//! An address split at its `@`, and what can be asked of it without its
//! domain's public suffix.
//!
//! PHP: `Utopia\Emails\Email`.

use php_std::filter::{self, FILTER_VALIDATE_EMAIL, Options};
use php_std::{mb, string};

use crate::Error;
use crate::canonicals::{Canonical, PROVIDERS, Provider};

/// The most characters allowed before the `@`.
///
/// PHP: `Email::LOCAL_MAX_LENGTH`.
pub const LOCAL_MAX_LENGTH: usize = 64;

/// The most characters allowed after the `@`.
///
/// PHP: `Email::DOMAIN_MAX_LENGTH`.
pub const DOMAIN_MAX_LENGTH: usize = 253;

/// Whether the email filter accepts `value`.
///
/// PHP: `filter_var($value, FILTER_VALIDATE_EMAIL) !== false`.
fn filtered(value: &[u8]) -> bool {
    matches!(
        filter::filter_var(&filter::Value::Str(value.to_vec()), FILTER_VALIDATE_EMAIL, &Options::Flags(0)),
        Ok(filter::Filtered { value: filter::Value::Str(_), .. })
    )
}

/// PHP `empty()` of a string.
fn empty(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

/// An address: trimmed, lower-cased (`mb_strtolower`) and split at its
/// single `@`. It can hold any bytes, as a PHP string does.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Email {
    email: Vec<u8>,
    /// Byte offset of the `@`.
    at: usize,
}

impl Email {
    /// Parses an address.
    ///
    /// # Errors
    ///
    /// [`Error::Parse`] for an empty address (or `"0"`), or one without
    /// exactly one `@` and something (not `"0"`) on both sides of it.
    ///
    /// PHP: `new Email($email)`, up to `new Domain()` of its domain.
    pub fn new(input: impl AsRef<[u8]>) -> Result<Self, Error> {
        let input = input.as_ref();
        let email = mb::mb_strtolower(string::trim(input, string::TRIM_CHARACTERS));
        if empty(&email) {
            return Err(Error::Parse(b"Email address cannot be empty".to_vec()));
        }
        let invalid = || Error::Parse([b"'", input, b"' must be a valid email address"].concat());
        let mut ats = email.iter().enumerate().filter(|(_, byte)| **byte == b'@').map(|(at, _)| at);
        let (Some(at), None) = (ats.next(), ats.next()) else {
            return Err(invalid());
        };
        if empty(&email[..at]) || empty(&email[at + 1..]) {
            return Err(invalid());
        }
        Ok(Self { email, at })
    }

    /// The whole address.
    ///
    /// PHP: `get()`.
    pub fn get(&self) -> &[u8] {
        &self.email
    }

    /// The part before the `@`.
    ///
    /// PHP: `getLocal()`.
    pub fn local(&self) -> &[u8] {
        &self.email[..self.at]
    }

    /// The part after the `@`.
    ///
    /// PHP: `getDomain()`.
    pub fn domain(&self) -> &[u8] {
        &self.email[self.at + 1..]
    }

    /// Whether PHP's email filter accepts the address.
    ///
    /// PHP: `isValid()`.
    pub fn is_valid(&self) -> bool {
        filtered(&self.email)
    }

    /// Whether the local part has at most [`LOCAL_MAX_LENGTH`] characters,
    /// all of `[a-zA-Z0-9._+-]`, no `..`, and no dot at either end.
    ///
    /// PHP: `hasValidLocal()`.
    pub fn has_valid_local(&self) -> bool {
        let local = self.local();
        if mb::mb_strlen(local) > LOCAL_MAX_LENGTH {
            return false;
        }
        // `/^[a-zA-Z0-9._+-]+$/`: without `/D`, `$` also matches before one final newline.
        let body = local.strip_suffix(b"\n").unwrap_or(local);
        let matched = !body.is_empty()
            && body.iter().all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b'+' | b'-'));
        matched && !local.windows(2).any(|pair| pair == b"..") && !local.starts_with(b".") && !local.ends_with(b".")
    }

    /// Whether the domain has at most [`DOMAIN_MAX_LENGTH`] characters and
    /// PHP's email filter accepts it after `test@`. `hasValidDomain()` then
    /// also asks that the domain be known or a test domain.
    ///
    /// PHP: `hasValidDomain()`, up to `$this->domainInstance->isKnown()`.
    pub fn has_valid_domain_syntax(&self) -> bool {
        let domain = self.domain();
        mb::mb_strlen(domain) <= DOMAIN_MAX_LENGTH && filtered(&[b"test@", domain].concat())
    }

    /// The address with the aliases of its provider removed.
    ///
    /// # Errors
    ///
    /// [`Error::EmptyLocal`] (see [`Provider::canonical`]).
    ///
    /// PHP: `getCanonical()`.
    pub fn canonical(&self) -> Result<Canonical, Error> {
        Provider::of(self.domain()).canonical(self.local(), self.domain())
    }

    /// Whether one of [`PROVIDERS`] has the domain.
    ///
    /// PHP: `isCanonicalSupported()`.
    pub fn is_canonical_supported(&self) -> bool {
        PROVIDERS.iter().any(|provider| provider.supports(self.domain()))
    }

    /// The canonical domain of the provider of [`PROVIDERS`] that has the
    /// domain, if one does.
    ///
    /// PHP: `getCanonicalDomain()`.
    pub fn canonical_domain(&self) -> Option<&'static str> {
        PROVIDERS.iter().find(|provider| provider.supports(self.domain())).map(|provider| provider.canonical_domain)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parts() {
        let email = Email::new("  User.Name+Tag@Mail.Company.CO.UK \n").unwrap();
        assert_eq!(email.get(), b"user.name+tag@mail.company.co.uk");
        assert_eq!((email.local(), email.domain()), (&b"user.name+tag"[..], &b"mail.company.co.uk"[..]));
        assert!(email.is_valid() && email.has_valid_local() && email.has_valid_domain_syntax());
        assert_eq!(Email::new("ÄB@X.COM").unwrap().get(), "äb@x.com".as_bytes());
    }

    #[test]
    fn errors() {
        let message = |input: &[u8]| Email::new(input).unwrap_err().message().to_vec();
        assert_eq!(message(b" \t"), b"Email address cannot be empty");
        assert_eq!(message(b"0"), b"Email address cannot be empty");
        assert_eq!(message(b"a@b@c"), b"'a@b@c' must be a valid email address");
        assert_eq!(message(b" \xff "), b"' \xff ' must be a valid email address");
        assert_eq!(message(b"0@x.com"), b"'0@x.com' must be a valid email address");
        assert_eq!(message(b"a@0"), b"'a@0' must be a valid email address");
    }

    #[test]
    fn local() {
        let valid = |s: &str| Email::new(s).unwrap().has_valid_local();
        assert!(valid("a.b_c+d-e@x.com") && valid("abc\n@x.com"));
        assert!(!valid("a..b@x.com") && !valid(".a@x.com") && !valid("a.@x.com") && !valid("ä@x.com"));
        assert!(!valid(&format!("{}@x.com", "a".repeat(65))));
    }

    #[test]
    fn canonical() {
        let email = Email::new("John.Doe+tag@GoogleMail.com").unwrap();
        assert_eq!(email.canonical().unwrap().address(), b"johndoe@gmail.com");
        assert_eq!(email.canonical_domain(), Some("gmail.com"));
        assert!(email.is_canonical_supported());
        let other = Email::new("a@example.com").unwrap();
        assert_eq!((other.canonical_domain(), other.is_canonical_supported()), (None, false));
    }
}
