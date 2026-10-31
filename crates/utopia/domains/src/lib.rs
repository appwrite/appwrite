//! Domain names against the Public Suffix List, validators built on it, and
//! domain registrars (availability, prices, purchases, renewals, transfers).
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Domains\Domain` | [`Domain`] |
//! | `new Domain($domain)` | [`Domain::new`] (the global list) or [`Domain::with_list`] |
//! | `get()`, `getApex()`, `getTLD()`, `getSuffix()`, `getRule()` | [`Domain::get`], [`Domain::apex`], [`Domain::tld`], [`Domain::suffix`], [`Domain::rule`] |
//! | `getRegisterable()`, `getName()`, `getSub()` | [`Domain::registerable`], [`Domain::name`], [`Domain::sub`] |
//! | `isKnown()`, `isICANN()`, `isPrivate()`, `isTest()` | [`Domain::is_known`], [`Domain::is_icann`], [`Domain::is_private`], [`Domain::is_test`] |
//! | `data/data.php` (the list `Domain` includes) | [`PublicSuffixList`] ([`PublicSuffixList::EMBEDDED`]) |
//! | `data/import.php` | the `domains-import` binary |
//! | `Validator\PublicDomain`, `ApexDomain`, `RegistrableDomain` | [`validator::PublicDomain`], [`validator::ApexDomain`], [`validator::RegistrableDomain`] |
//! | `PublicDomain::allow()` | [`validator::PublicDomain::allow`] |
//! | `Utopia\Domains\Cache` | [`Cache`] over a [`Store`] ([`MemoryStore`], [`NoStore`]) |
//! | `Utopia\Domains\Registrar` | [`registrar::Registrar`] with a [`registrar::Config`] |
//! | `Registrar::REG_TYPE_*` | [`registrar::RegType`] |
//! | `Registrar\Adapter` (abstract) | [`registrar::Adapter`] (trait) |
//! | `setDefaultNameservers()`, `setCache()`, `setConnectTimeout()`, `setTimeout()` | the fields of [`registrar::Config`] |
//! | `Registrar\Adapter\Mock`, `NameCom`, `OpenSRS` | [`registrar::Mock`], [`registrar::NameCom`], [`registrar::OpenSrs`] |
//! | `getPurchasedDomains()`, `getTransferredDomains()`, `reset()`, `addTakenDomain()`, `addPremiumDomain()` | [`registrar::Mock::purchased`], [`registrar::Mock::transferred`], [`registrar::Mock::reset`], [`registrar::Mock::add_taken`], [`registrar::Mock::add_premium`] |
//! | `available()`, `purchase()`, `suggest()`, `tlds()` | [`registrar::Registrar::available`], [`registrar::Registrar::purchase`], [`registrar::Registrar::suggest`], [`registrar::Registrar::tlds`] |
//! | `getDomain()`, `updateDomain()`, `updateNameservers()` | [`registrar::Registrar::domain`], [`registrar::Registrar::update_domain`], [`registrar::Registrar::update_nameservers`] |
//! | `getPrice()`, `renew()`, `transfer()` | [`registrar::Registrar::price`], [`registrar::Registrar::renew`], [`registrar::Registrar::transfer`] |
//! | `getAuthCode()`, `checkTransferStatus()`, `cancelPurchase()` | [`registrar::Registrar::auth_code`], [`registrar::Registrar::transfer_status`], [`registrar::Registrar::cancel_purchase`] |
//! | `Registrar\Contact`, `toArray()` | [`registrar::Contact`], [`registrar::Contact::fields`]; an `array\|Contact` argument is [`registrar::Contacts`] |
//! | `Registrar\Domain`, `Price`, `Renewal`, `UpdateDetails` | [`registrar::Domain`], [`registrar::Price`], [`registrar::Renewal`], [`registrar::UpdateDetails`] |
//! | `Registrar\TransferStatus`, `TransferStatusEnum` | [`registrar::TransferStatus`], [`registrar::TransferState`] |
//! | the `array` `suggest()` returns | [`registrar::Suggestions`] of [`registrar::Suggestion`] |
//! | the `array` `updateNameservers()` returns | [`registrar::NameserverUpdate`] |
//! | a `Psr\Http\Client\ClientInterface` given to an adapter | [`transport::Transport`]; the default is [`transport::HttpTransport`] |
//! | `Utopia\Domains\Adapter` (`call()`) | [`api::Api`] |
//! | `Utopia\Domains\Exception`, `Registrar\Exception\*` | [`Error`], one variant per class |
//!
//! The Public Suffix List is embedded in the crate
//! (`data/public-suffix-list.txt`) and parsed once, on first use, unless a
//! list is installed with [`PublicSuffixList::install`]. The `domains-import`
//! task refreshes it from publicsuffix.org (see the guide).
//!
//! Registrar calls are async. Their settings travel with the
//! [`registrar::Registrar`], so one adapter can serve several registrars
//! with different caches.
//!
//! PHP: the list is the data file shipped with the PHP package
//! (`data/data.php`), with the same rules in the same order. PHP sets the
//! registrar settings on the adapter.

#![warn(missing_docs)]

pub mod api;
mod cache;
mod domain;
mod error;
mod php;
pub mod registrar;
pub mod transport;
pub mod validator;

pub use cache::{BoxFuture, Cache, MemoryStore, NoStore, Store};
pub use domain::{Domain, PublicSuffixList, Section};
pub use error::Error;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
