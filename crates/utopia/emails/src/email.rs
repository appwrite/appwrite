//! A parsed email address and what can be asked of it.
//!
//! PHP: `Utopia\Emails\Email`.

use std::borrow::Cow;

use php_std::filter::{self, FILTER_VALIDATE_EMAIL, Options};
use php_std::{mb, string};
use utopia_domains::Domain;

use crate::canonicals::{Generic, PROVIDERS, Provider};
use crate::{Error, Lists};

/// The most characters allowed before the `@`.
///
/// PHP: `Email::LOCAL_MAX_LENGTH`.
pub const LOCAL_MAX_LENGTH: usize = 64;

/// The most characters allowed after the `@`.
///
/// PHP: `Email::DOMAIN_MAX_LENGTH`.
pub const DOMAIN_MAX_LENGTH: usize = 253;

/// Which part of an address [`Email::formatted`] returns.
///
/// PHP: `Email::FORMAT_*`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Format {
    /// The whole address (named `full`).
    #[default]
    Full,
    /// The part before the `@` (named `local`).
    Local,
    /// The part after the `@` (named `domain`).
    Domain,
    /// The registrable domain (named `provider`).
    Provider,
    /// What comes before the registrable domain (named `subdomain`).
    Subdomain,
}

impl Format {
    /// The format named `name` (see [`Format::name`]); any other name is
    /// [`Format::Full`].
    ///
    /// PHP: unknown names fall back to `full` as in `getFormatted()`.
    pub fn from_name(name: &str) -> Self {
        match name {
            "local" => Format::Local,
            "domain" => Format::Domain,
            "provider" => Format::Provider,
            "subdomain" => Format::Subdomain,
            _ => Format::Full,
        }
    }

    /// The format's name: `full`, `local`, `domain`, `provider` or
    /// `subdomain`.
    ///
    /// PHP: the value of the `Email::FORMAT_*` constant.
    pub fn name(self) -> &'static str {
        match self {
            Format::Full => "full",
            Format::Local => "local",
            Format::Domain => "domain",
            Format::Provider => "provider",
            Format::Subdomain => "subdomain",
        }
    }
}

/// Whether a string is empty or `"0"`.
///
/// PHP: `empty()` of a string.
fn empty(s: &str) -> bool {
    s.is_empty() || s == "0"
}

/// Whether the email filter accepts `value`.
///
/// PHP: `filter_var($value, FILTER_VALIDATE_EMAIL) !== false`.
fn filter_email(value: &[u8]) -> bool {
    matches!(
        filter::filter_var(&filter::Value::Str(value.to_vec()), FILTER_VALIDATE_EMAIL, &Options::Flags(0)),
        Ok(filter::Filtered { value: filter::Value::Str(_), .. })
    )
}

/// A parsed email address: trimmed, lower-cased and split at its single
/// `@`.
///
/// PHP: lower-cased with `mb_strtolower`.
#[derive(Debug, Clone)]
pub struct Email {
    email: String,
    /// Byte offset of the `@`.
    at: usize,
    domain: Domain<'static>,
}

impl Email {
    /// Parses an address, which can be any bytes.
    ///
    /// # Errors
    ///
    /// [`Error::Parse`] for an empty address, one without exactly one `@`
    /// and something on both sides of it, or one whose domain is a URL.
    ///
    /// PHP: `new Email($email)`.
    pub fn new(email: impl AsRef<[u8]>) -> Result<Self, Error> {
        let input = email.as_ref();
        let lowered = mb::mb_strtolower(string::trim(input, string::TRIM_CHARACTERS));
        let email = String::from_utf8(lowered).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned());
        if empty(&email) {
            return Err(Error::Parse(b"Email address cannot be empty".to_vec()));
        }
        let invalid = || {
            let mut message = Vec::with_capacity(input.len() + 34);
            message.push(b'\'');
            message.extend_from_slice(input);
            message.extend_from_slice(b"' must be a valid email address");
            Error::Parse(message)
        };
        let mut ats = email.match_indices('@').map(|(i, _)| i);
        let (Some(at), None) = (ats.next(), ats.next()) else {
            return Err(invalid());
        };
        if empty(&email[..at]) || empty(&email[at + 1..]) {
            return Err(invalid());
        }
        let domain = Domain::new(&email[at + 1..]).map_err(|e| Error::Parse(e.to_string().into_bytes()))?;
        Ok(Self { email, at, domain })
    }

    /// The whole address.
    ///
    /// PHP: `get()`.
    pub fn get(&self) -> &str {
        &self.email
    }

    /// The part before the `@`.
    ///
    /// PHP: `getLocal()`.
    pub fn local(&self) -> &str {
        &self.email[..self.at]
    }

    /// The part after the `@`.
    ///
    /// PHP: `getDomain()`.
    pub fn domain(&self) -> &str {
        &self.email[self.at + 1..]
    }

    /// Whether the address is a valid email address.
    ///
    /// PHP: `isValid()`; PHP's `FILTER_VALIDATE_EMAIL` accepts the address.
    pub fn is_valid(&self) -> bool {
        filter_email(self.email.as_bytes())
    }

    /// Whether the local part is valid: at most [`LOCAL_MAX_LENGTH`]
    /// characters of `[a-zA-Z0-9._+-]`, no `..`, not starting or ending with
    /// a dot.
    ///
    /// PHP: `hasValidLocal()`.
    pub fn has_valid_local(&self) -> bool {
        let local = self.local();
        if mb::mb_strlen(local.as_bytes()) > LOCAL_MAX_LENGTH {
            return false;
        }
        // `/^[a-zA-Z0-9._+-]+$/`: without `/D`, `$` also matches before one
        // final newline.
        let body = local.strip_suffix('\n').unwrap_or(local);
        let matched = !body.is_empty()
            && body.bytes().all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'+' | b'-'));
        if !matched {
            return false;
        }
        !(local.contains("..") || local.starts_with('.') || local.ends_with('.'))
    }

    /// Whether the domain is valid: at most [`DOMAIN_MAX_LENGTH`] characters,
    /// valid in an address after `test@`, and with a known public suffix or a
    /// test TLD.
    ///
    /// PHP: `hasValidDomain()`.
    pub fn has_valid_domain(&self) -> bool {
        let domain = self.domain();
        if mb::mb_strlen(domain.as_bytes()) > DOMAIN_MAX_LENGTH {
            return false;
        }
        let mut probe = Vec::with_capacity(domain.len() + 5);
        probe.extend_from_slice(b"test@");
        probe.extend_from_slice(domain.as_bytes());
        if !filter_email(&probe) {
            return false;
        }
        self.domain.is_known() || self.domain.is_test()
    }

    /// Whether the domain is on the disposable list.
    ///
    /// PHP: `isDisposable()`.
    pub fn is_disposable(&self) -> bool {
        Lists::global().disposable.contains(self.domain())
    }

    /// Whether the domain is on the free list and not disposable.
    ///
    /// PHP: `isFree()`.
    pub fn is_free(&self) -> bool {
        Lists::global().free.contains(self.domain()) && !self.is_disposable()
    }

    /// Whether the domain is neither free nor disposable.
    ///
    /// PHP: `isCorporate()`.
    pub fn is_corporate(&self) -> bool {
        !self.is_free() && !self.is_disposable()
    }

    /// The registrable domain (`example.co.uk` for `mail.example.co.uk`), else
    /// the whole domain.
    ///
    /// PHP: `getProvider()`.
    pub fn provider(&self) -> Cow<'_, str> {
        let registerable = self.domain.registerable();
        if empty(&registerable) { Cow::Borrowed(self.domain()) } else { Cow::Owned(registerable) }
    }

    /// What comes before the registrable domain.
    ///
    /// PHP: `getSubdomain()`.
    pub fn subdomain(&self) -> String {
        self.domain.sub().to_owned()
    }

    /// Whether there is a subdomain (one that is not empty nor `"0"`).
    ///
    /// PHP: `hasSubdomain()`.
    pub fn has_subdomain(&self) -> bool {
        !empty(self.domain.sub())
    }

    /// The address with the aliases of its provider ([`PROVIDERS`]) removed.
    ///
    /// PHP: `getCanonical()`.
    pub fn canonical(&self) -> Result<String, Error> {
        self.canonical_with(PROVIDERS)
    }

    /// [`Email::canonical`] with another set of providers, consulted in
    /// order; [`Generic`] when none supports the domain.
    pub fn canonical_with(&self, providers: &[&dyn Provider]) -> Result<String, Error> {
        let domain = self.domain();
        let provider = providers.iter().copied().find(|p| p.supports(domain)).unwrap_or(&Generic);
        provider.canonical(self.local(), domain).map(|c| c.to_string())
    }

    /// Whether one of [`PROVIDERS`] supports the domain.
    ///
    /// PHP: `isCanonicalSupported()`.
    pub fn is_canonical_supported(&self) -> bool {
        PROVIDERS.iter().any(|p| p.supports(self.domain()))
    }

    /// The canonical domain of the provider of [`PROVIDERS`] that supports the
    /// domain, if one does.
    ///
    /// PHP: `getCanonicalDomain()`.
    pub fn canonical_domain(&self) -> Option<&'static str> {
        Self::canonical_domain_in(PROVIDERS, self.domain())
    }

    /// [`Email::canonical_domain`] with another set of providers.
    pub fn canonical_domain_with<'p>(&self, providers: &[&'p dyn Provider]) -> Option<&'p str> {
        Self::canonical_domain_in(providers, self.domain())
    }

    fn canonical_domain_in<'p>(providers: &[&'p dyn Provider], domain: &str) -> Option<&'p str> {
        providers.iter().copied().find(|p| p.supports(domain)).map(|p| p.canonical_domain())
    }

    /// One part of the address, chosen by `format`.
    ///
    /// PHP: `getFormatted($format)`.
    pub fn formatted(&self, format: Format) -> Cow<'_, str> {
        match format {
            Format::Full => Cow::Borrowed(self.get()),
            Format::Local => Cow::Borrowed(self.local()),
            Format::Domain => Cow::Borrowed(self.domain()),
            Format::Provider => self.provider(),
            Format::Subdomain => Cow::Owned(self.subdomain()),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parts() {
        let email = Email::new("  User.Name+Tag@Mail.Company.CO.UK \n").unwrap();
        assert_eq!(
            (email.get(), email.local(), email.domain()),
            ("user.name+tag@mail.company.co.uk", "user.name+tag", "mail.company.co.uk")
        );
        assert!(email.is_valid() && email.has_valid_local() && email.has_valid_domain());
        assert_eq!((email.provider(), email.subdomain()), (Cow::Borrowed("company.co.uk"), "mail".to_owned()));
        assert!(email.has_subdomain());
        assert_eq!(email.formatted(Format::from_name("provider")), "company.co.uk");
        assert_eq!(email.formatted(Format::from_name("nonsense")), email.get());
    }

    #[test]
    fn errors() {
        let message = |input: &[u8]| Email::new(input).unwrap_err().message().to_vec();
        assert_eq!(message(b" \t"), b"Email address cannot be empty");
        assert_eq!(message(b"0"), b"Email address cannot be empty");
        assert_eq!(message(b"a@b@c"), b"'a@b@c' must be a valid email address");
        assert_eq!(message(b"\xff"), b"'\xff' must be a valid email address");
        assert_eq!(message(b"0@x.com"), b"'0@x.com' must be a valid email address");
        let url = Email::new("a@HTTP://x.com").unwrap_err();
        assert_eq!(
            (url.php_class(), url.to_string().as_str()),
            ("Exception", "'http://x.com' must be a valid domain or hostname")
        );
    }

    #[test]
    fn canonical() {
        let canonical = |s: &str| Email::new(s).unwrap().canonical();
        assert_eq!(canonical("John.Doe+tag@GoogleMail.com").unwrap(), "johndoe@gmail.com");
        assert_eq!(canonical("a.b+c@hotmail.co.uk").unwrap(), "a.b@outlook.com");
        assert_eq!(canonical("a.b+c@pm.me").unwrap(), "a.b@pm.me");
        assert_eq!(canonical("a.b+c@yandex.ru").unwrap(), "a.b+c@yandex.ru");
        assert_eq!(canonical("0+x@gmail.com").unwrap_err().php_class(), "InvalidArgumentException");
        let email = Email::new("a@me.com").unwrap();
        assert_eq!(email.canonical_domain(), Some("icloud.com"));
        assert_eq!(email.canonical_with(&[&crate::Yandex]).unwrap(), "a@me.com");
        assert_eq!(Email::new("a@example.com").unwrap().canonical_domain(), None);
    }

    #[test]
    fn quirks() {
        // `$` without /D matches before a final newline.
        assert!(Email::new("abc\n@example.com").unwrap().has_valid_local());
        let email = Email::new("a@0.example.com").unwrap();
        assert_eq!((email.subdomain().as_str(), email.has_subdomain()), ("0", false));
    }

    #[test]
    fn classification() {
        let class = |s: &str| {
            let e = Email::new(s).unwrap();
            (e.is_free(), e.is_disposable(), e.is_corporate())
        };
        assert_eq!(class("a@gmail.com"), (true, false, false));
        assert_eq!(class("a@10minutemail.com"), (false, true, false));
        assert_eq!(class("a@company.org"), (false, false, true));
    }
}
