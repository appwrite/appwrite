//! Email addresses: split at the `@`, checked, made canonical per mail
//! provider, and their domains classified as free, disposable or corporate;
//! and validators for request parameters.
//!
//! PHP: the semantics of `utopia-php/emails`; validation is PHP's
//! `FILTER_VALIDATE_EMAIL`, through `php-std`. Addresses are bytes, as PHP
//! strings are.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Emails\Email` | [`Address`] (with its domain parsed), [`Email`] (without) |
//! | `new Email($email)` | [`Address::new`]; [`Email::new`] stops before `new Domain()` |
//! | `get()`, `getLocal()`, `getDomain()` | [`Email::get`], [`Email::local`], [`Email::domain`] |
//! | `isValid()`, `hasValidLocal()` | [`Email::is_valid`], [`Email::has_valid_local`] |
//! | `hasValidDomain()` | [`Address::has_valid_domain`] ([`Email::has_valid_domain_syntax`] without the known suffix) |
//! | `isDisposable()`, `isFree()`, `isCorporate()` | [`Address::is_disposable`], [`Address::is_free`], [`Address::is_corporate`] ([`Lists::is_disposable`], [`Lists::is_on_free_list`] given the domain) |
//! | `getProvider()`, `getSubdomain()`, `hasSubdomain()` | [`Address::provider`], [`Address::subdomain`], [`Address::has_subdomain`] |
//! | `getCanonical()`, `isCanonicalSupported()`, `getCanonicalDomain()` | [`Email::canonical`], [`Email::is_canonical_supported`], [`Email::canonical_domain`] |
//! | `getFormatted($format)`, `Email::FORMAT_*` | [`Address::formatted`], [`Format`] |
//! | `Email::LOCAL_MAX_LENGTH`, `DOMAIN_MAX_LENGTH` | [`LOCAL_MAX_LENGTH`], [`DOMAIN_MAX_LENGTH`] |
//! | `data/free-domains.php`, `data/disposable-domains.php` | [`Lists`] ([`Lists::FREE`], [`Lists::DISPOSABLE`]) |
//! | `import.php` (`composer import:*`) | the `emails-import` binary |
//! | `Canonicals\Providers\{Gmail, Outlook, Yahoo, Icloud, Protonmail, Fastmail, Yandex, Walla, Generic}` | [`Provider`] ([`Provider::GMAIL`] to [`Provider::GENERIC`]) |
//! | provider `supports()`, `getCanonical()`, `getCanonicalDomain()`, `getSupportedDomains()` | [`Provider::supports`], [`Provider::canonical`], [`Provider::canonical_domain`], [`Provider::domains`] |
//! | `getCanonical()` result `['local' => ..., 'domain' => ...]` | [`Canonical`] |
//! | the providers `Email` consults | [`PROVIDERS`] |
//! | `Validator\Email`, `EmailDomain`, `EmailLocal`, `EmailCorporate`, `EmailNotDisposable` | [`EmailValidator`], [`EmailDomain`], [`EmailLocal`], [`EmailCorporate`], [`EmailNotDisposable`] |
//! | validator `isValid($value)` | `utopia_validators::Validator::is_valid` (JSON values), `is_valid_address` (strings of any bytes) |
//! | validator `getDescription()`, `getType()`, `isArray()` | `Validator::description`, `value_type()`, `Validator::is_array` |
//! | `Exception` (constructor), `InvalidArgumentException` (canonical form) | [`Error::Parse`], [`Error::EmptyLocal`] |
//!
//! Domain parsing (provider, subdomain, known suffixes) is `utopia-domains`.
//! The `utopia` PHP extension builds `Utopia\Emails\Email` on [`Email`] and
//! asks PHP's `Utopia\Domains\Domain` for the rest, as PHP does.
//!
//! The lists are embedded in the crate (`data/*-domains.txt`) and parsed
//! once, on first use. The `emails-import` task refreshes them from their
//! sources (see the guide); `data/export.php` writes them from the PHP
//! package's `data/*-domains.php`, which hold the same domains in the same
//! order.

#![warn(missing_docs)]

mod address;
mod canonicals;
mod email;
mod error;
mod lists;
mod validator;

pub use address::{Address, Format};
pub use canonicals::{Canonical, PROVIDERS, Provider};
pub use email::{DOMAIN_MAX_LENGTH, Email, LOCAL_MAX_LENGTH};
pub use error::Error;
pub use lists::{DomainList, Lists};
pub use validator::{EmailCorporate, EmailDomain, EmailLocal, EmailNotDisposable, EmailValidator};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
