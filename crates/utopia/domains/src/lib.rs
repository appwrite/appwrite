//! Domain names against the Public Suffix List.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Domains\Domain` | [`Domain`] |
//! | `new Domain($domain)` | [`Domain::new`] (the global list) or [`Domain::with_list`] |
//! | `get()`, `getApex()`, `getTLD()`, `getSuffix()`, `getRule()` | [`Domain::get`], [`Domain::apex`], [`Domain::tld`], [`Domain::suffix`], [`Domain::rule`] |
//! | `getRegisterable()`, `getName()`, `getSub()` | [`Domain::registerable`], [`Domain::name`], [`Domain::sub`] |
//! | `isKnown()`, `isICANN()`, `isPrivate()`, `isTest()` | [`Domain::is_known`], [`Domain::is_icann`], [`Domain::is_private`], [`Domain::is_test`] |
//! | `data/data.php` (the list `Domain` includes) | [`PublicSuffixList`] ([`PublicSuffixList::EMBEDDED`]) |
//!
//! The Public Suffix List is embedded in the crate
//! (`data/public-suffix-list.txt`, exported from the PHP package's
//! `data/data.php` by `data/export.php`) and parsed once, on first use,
//! unless a list is installed with [`PublicSuffixList::install`].
//!
//! PHP: the registrars and validators of `utopia-php/domains` are not ported
//! here; the registrars make network calls, which stay in PHP.

#![warn(missing_docs)]

mod domain;
mod error;

pub use domain::{Domain, PublicSuffixList, Section};
pub use error::Error;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
