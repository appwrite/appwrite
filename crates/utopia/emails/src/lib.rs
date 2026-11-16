//! Email addresses: split at the `@`, checked, made canonical per mail
//! provider, and their domains classified as free or disposable.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Emails\Email` | [`Email`] |
//! | `new Email($email)` | [`Email::new`] |
//! | `get()`, `getLocal()`, `getDomain()` | [`Email::get`], [`Email::local`], [`Email::domain`] |
//! | `isValid()`, `hasValidLocal()` | [`Email::is_valid`], [`Email::has_valid_local`] |
//! | `hasValidDomain()` | [`Email::has_valid_domain_syntax`], then the domain's known suffix (`utopia-domains`) |
//! | `isDisposable()`, `isFree()` | [`Lists::is_disposable`], [`Lists::is_free`] |
//! | `getCanonical()`, `isCanonicalSupported()`, `getCanonicalDomain()` | [`Email::canonical`], [`Email::is_canonical_supported`], [`Email::canonical_domain`] |
//! | `Email::LOCAL_MAX_LENGTH`, `DOMAIN_MAX_LENGTH` | [`LOCAL_MAX_LENGTH`], [`DOMAIN_MAX_LENGTH`] |
//! | `data/free-domains.php`, `data/disposable-domains.php` | [`Lists`] ([`Lists::FREE`], [`Lists::DISPOSABLE`]) |
//! | `Canonicals\Providers\{Gmail, Outlook, Yahoo, Icloud, Protonmail, Fastmail, Walla, Generic}` | [`Provider`] ([`PROVIDERS`], [`Provider::GENERIC`]) |
//!
//! What `Email` asks of its domain (`getProvider()`, `getSubdomain()`,
//! `isKnown()`) is `Utopia\Domains\Domain`'s, which the extension calls
//! as PHP does, so this crate does not parse domains.
//!
//! The lists are embedded in the crate (`data/*-domains.txt`, exported from
//! the PHP package's `data/*-domains.php` by `data/export.php`) and parsed
//! once, on first use. The PHP validators, providers and import task are not
//! ported: `Email` is.

#![warn(missing_docs)]

mod canonicals;
mod email;
mod error;
mod lists;

pub use canonicals::{Canonical, PROVIDERS, Provider};
pub use email::{DOMAIN_MAX_LENGTH, Email, LOCAL_MAX_LENGTH};
pub use error::Error;
pub use lists::{DomainList, Lists};

/// The README, compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../README.md")]
pub struct ReadMe;
