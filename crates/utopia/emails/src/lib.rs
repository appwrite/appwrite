//! Email addresses with the semantics of `utopia-php/emails`: parsing,
//! validation (PHP's `FILTER_VALIDATE_EMAIL`, through `php-std`), the
//! canonical form of an address per mail provider, and the free /
//! disposable / corporate classification of its domain.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Emails\Email` | [`Email`] |
//! | `new Email($email)` | [`Email::new`] |
//! | `get()`, `getLocal()`, `getDomain()` | [`Email::get`], [`Email::local`], [`Email::domain`] |
//! | `isValid()`, `hasValidLocal()`, `hasValidDomain()` | [`Email::is_valid`], [`Email::has_valid_local`], [`Email::has_valid_domain`] |
//! | `isDisposable()`, `isFree()`, `isCorporate()` | [`Email::is_disposable`], [`Email::is_free`], [`Email::is_corporate`] |
//! | `getProvider()`, `getSubdomain()`, `hasSubdomain()` | [`Email::provider`], [`Email::subdomain`], [`Email::has_subdomain`] |
//! | `getCanonical()`, `isCanonicalSupported()`, `getCanonicalDomain()` | [`Email::canonical`], [`Email::is_canonical_supported`], [`Email::canonical_domain`] (and `*_with` for other providers) |
//! | `getFormatted($format)`, `Email::FORMAT_*` | [`Email::formatted`], [`Format`] |
//! | `Email::LOCAL_MAX_LENGTH`, `DOMAIN_MAX_LENGTH` | [`LOCAL_MAX_LENGTH`], [`DOMAIN_MAX_LENGTH`] |
//! | `data/free-domains.php`, `data/disposable-domains.php` | [`Lists`] |
//! | `Utopia\Emails\Canonicals\Provider` (abstract) | [`Provider`] (trait) |
//! | `Canonicals\Providers\{Gmail, Outlook, Yahoo, Icloud, Protonmail, Fastmail, Yandex, Walla, Generic}` | [`Gmail`], [`Outlook`], [`Yahoo`], [`Icloud`], [`Protonmail`], [`Fastmail`], [`Yandex`], [`Walla`], [`Generic`] |
//! | `getCanonical()` result `['local' => ..., 'domain' => ...]` | [`Canonical`] |
//! | the providers `Email` consults | [`PROVIDERS`] |
//! | `Validator\Email`, `EmailDomain`, `EmailLocal`, `EmailCorporate`, `EmailNotDisposable` | [`EmailValidator`], [`EmailDomain`], [`EmailLocal`], [`EmailCorporate`], [`EmailNotDisposable`] |
//! | validator `isValid($value)` | `utopia_validators::Validator::is_valid` (JSON values), `is_valid_address` (strings of any bytes) |
//! | validator `getDescription()`, `getType()`, `isArray()` | `Validator::description`, `value_type()`, `Validator::is_array` |
//! | `Exception` (constructor), `InvalidArgumentException` (canonical form) | [`Error::Parse`], [`Error::InvalidArgument`] |
//!
//! Domain parsing (provider, subdomain, known suffixes) is `utopia-domains`,
//! as `utopia-php/emails` uses `utopia-php/domains`.
//!
//! The free and disposable lists are the PHP package's data files. PHP
//! includes them from the package directory on first use; Rust reads them
//! from there too ([`Lists::default_dir`]) unless a server installs lists
//! loaded from its assets with [`Lists::install`] at startup.

mod canonicals;
mod email;
mod error;
mod lists;
mod literal;
mod validator;

pub use canonicals::{
    Canonical, Fastmail, Generic, Gmail, Icloud, Outlook, PROVIDERS, Protonmail, Provider, Walla, Yahoo, Yandex,
};
pub use email::{DOMAIN_MAX_LENGTH, Email, Format, LOCAL_MAX_LENGTH};
pub use error::Error;
pub use lists::{DomainList, Lists};
pub use validator::{EmailCorporate, EmailDomain, EmailLocal, EmailNotDisposable, EmailValidator};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
