//! An address with its domain parsed: what PHP's `Email` answers with the
//! help of its `Utopia\Domains\Domain`.
//!
//! PHP: `Utopia\Emails\Email`, whose constructor also makes the domain's
//! `Domain`.

use std::borrow::Cow;
use std::ops::Deref;

use utopia_domains::Domain;

use crate::{Email, Error, Lists};

/// Which part of an address [`Address::formatted`] returns.
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
    pub fn from_name(name: &[u8]) -> Self {
        match name {
            b"local" => Format::Local,
            b"domain" => Format::Domain,
            b"provider" => Format::Provider,
            b"subdomain" => Format::Subdomain,
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

/// PHP `empty()` of a string.
fn empty(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

/// An [`Email`] and its domain, parsed by `utopia-domains`: everything PHP's
/// `Email` answers. It dereferences to the [`Email`].
#[derive(Debug, Clone)]
pub struct Address {
    email: Email,
    domain: Domain<'static>,
}

impl Address {
    /// Parses an address, which can be any bytes, and its domain.
    ///
    /// # Errors
    ///
    /// [`Error::Parse`] where [`Email::new`] fails, or for a URL as the
    /// domain.
    ///
    /// PHP: `new Email($email)`.
    pub fn new(input: impl AsRef<[u8]>) -> Result<Self, Error> {
        let email = Email::new(input)?;
        let domain = Domain::new(email.domain()).map_err(|e| Error::Parse(e.to_string().into_bytes()))?;
        Ok(Self { email, domain })
    }

    /// The address without its parsed domain.
    pub fn email(&self) -> &Email {
        &self.email
    }

    /// The parsed domain.
    ///
    /// PHP: the `$domainInstance` property.
    pub fn domain_instance(&self) -> &Domain<'static> {
        &self.domain
    }

    /// Whether the domain is valid: [`Email::has_valid_domain_syntax`], and a
    /// known public suffix or a test TLD.
    ///
    /// PHP: `hasValidDomain()`.
    pub fn has_valid_domain(&self) -> bool {
        self.email.has_valid_domain_syntax() && (self.domain.is_known() || self.domain.is_test())
    }

    /// Whether the domain, or its registrable domain, is on the disposable
    /// list.
    ///
    /// A provider listed by its registrable domain owns every subdomain under
    /// it (`user@6s.lucktaro.com` is `lucktaro.com`). Matching on the public
    /// suffix list, not on any parent, keeps one site on a shared suffix such
    /// as `it.com` from listing the rest.
    ///
    /// PHP: `isDisposable()`.
    pub fn is_disposable(&self) -> bool {
        Lists::global().is_disposable(self.email.domain(), self.domain.registerable().as_bytes())
    }

    /// Whether the domain is on the free list and not disposable.
    ///
    /// PHP: `isFree()`.
    pub fn is_free(&self) -> bool {
        Lists::global().is_on_free_list(self.email.domain()) && !self.is_disposable()
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
    pub fn provider(&self) -> Cow<'_, [u8]> {
        let registerable = self.domain.registerable();
        if empty(registerable.as_bytes()) {
            Cow::Borrowed(self.email.domain())
        } else {
            Cow::Owned(registerable.into_bytes())
        }
    }

    /// What comes before the registrable domain.
    ///
    /// PHP: `getSubdomain()`.
    pub fn subdomain(&self) -> &[u8] {
        self.domain.sub().as_bytes()
    }

    /// Whether there is a subdomain (one that is not empty nor `"0"`).
    ///
    /// PHP: `hasSubdomain()`.
    pub fn has_subdomain(&self) -> bool {
        !empty(self.subdomain())
    }

    /// One part of the address, chosen by `format`.
    ///
    /// PHP: `getFormatted($format)`.
    pub fn formatted(&self, format: Format) -> Cow<'_, [u8]> {
        match format {
            Format::Full => Cow::Borrowed(self.email.get()),
            Format::Local => Cow::Borrowed(self.email.local()),
            Format::Domain => Cow::Borrowed(self.email.domain()),
            Format::Provider => self.provider(),
            Format::Subdomain => Cow::Borrowed(self.subdomain()),
        }
    }
}

impl Deref for Address {
    type Target = Email;

    fn deref(&self) -> &Email {
        &self.email
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parts() {
        let address = Address::new("  User.Name+Tag@Mail.Company.CO.UK \n").unwrap();
        assert_eq!(address.get(), b"user.name+tag@mail.company.co.uk");
        assert!(address.has_valid_domain());
        assert_eq!((&*address.provider(), address.subdomain()), (&b"company.co.uk"[..], &b"mail"[..]));
        assert!(address.has_subdomain());
        assert_eq!(&*address.formatted(Format::from_name(b"provider")), b"company.co.uk");
        assert_eq!(&*address.formatted(Format::from_name(b"nonsense")), address.get());
        assert!(!Address::new("a@example.invalidtld").unwrap().has_valid_domain());
    }

    #[test]
    fn errors() {
        let url = Address::new("a@HTTP://x.com").unwrap_err();
        assert_eq!(
            (url.php_class(), url.to_string().as_str()),
            ("Exception", "'http://x.com' must be a valid domain or hostname")
        );
        assert_eq!(Address::new("a@b@c").unwrap_err().message(), b"'a@b@c' must be a valid email address");
    }

    #[test]
    fn quirks() {
        let address = Address::new("a@0.example.com").unwrap();
        assert_eq!((address.subdomain(), address.has_subdomain()), (&b"0"[..], false));
    }

    #[test]
    fn classification() {
        let class = |s: &str| {
            let address = Address::new(s).unwrap();
            (address.is_free(), address.is_disposable(), address.is_corporate())
        };
        assert_eq!(class("a@gmail.com"), (true, false, false));
        assert_eq!(class("a@10minutemail.com"), (false, true, false));
        assert_eq!(class("a@x.mailinator.com"), (false, true, false));
        assert_eq!(class("a@company.org"), (false, false, true));
    }
}
