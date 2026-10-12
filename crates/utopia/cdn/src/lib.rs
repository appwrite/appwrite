//! CDN cache purges and TLS certificates for custom domains, behind one
//! interface per job so a caller never needs to know which vendor it talks
//! to.
//!
//! [`cache`] purges cached content by path, domain, key or whole zone,
//! through [`cache::Cloudflare`], [`cache::Fastly`], or [`cache::Balancer`],
//! which fans a purge out over every [`CdnOption`] a balancer's filters
//! leave. [`certificates`] issues, checks, renews and deletes certificates
//! through [`certificates::Cloudflare`] (custom hostnames),
//! [`certificates::Fastly`] (domains with TLS subscriptions),
//! [`certificates::FastlyTls`] (subscriptions alone), or
//! [`certificates::Proxy`], which routes by domain.
//!
//! ```no_run
//! use utopia_cdn::cache::{Cache, Fastly};
//!
//! # async fn run() -> Result<(), utopia_cdn::Error> {
//! let cache = Cache::new(Fastly::new("api-token", "domain-").with_service_id("service-id"));
//! cache.purge_paths("example.com", &["/index.html".into(), "/app.js".into()]).await?;
//! cache.purge_keys(&["product-42".into()]).await?;
//! # Ok(())
//! # }
//! ```
//!
//! ```php
//! use Utopia\Cdn\Cache;
//! use Utopia\Cdn\Cache\Adapter\Fastly;
//!
//! $cache = new Cache(new Fastly('api-token', 'domain-', 'service-id'));
//! // $cache->purgePaths('example.com', ['/index.html', '/app.js']);
//! // $cache->purgeKeys(['product-42']);
//! ```
//!
//! Providers send through any [`utopia_client::Adapter`] (the
//! [`DefaultClient`] unless given another with `with_client`), so tests can
//! record and answer their requests without reaching a vendor.
//!
//! PHP: ports `utopia-php/cdn`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Cdn\Domain::validate()`, `validatePaths()` | [`domain::validate`], [`domain::validate_paths`] |
//! | `Utopia\Cdn\Cache` | [`cache::Cache`] |
//! | `Cache::purgePaths()`, `purgeDomain()`, `purgeKeys()`, `purgeZone()` | [`cache::Cache::purge_paths`], [`cache::Cache::purge_domain`], [`cache::Cache::purge_keys`], [`cache::Cache::purge_zone`] |
//! | `Utopia\Cdn\Cache\Adapter` | [`cache::Adapter`] |
//! | `Cache\Adapter\Cloudflare` (`PATHS_PER_PURGE`, `KEYS_PER_PURGE`) | [`cache::Cloudflare`] ([`cache::Cloudflare::PATHS_PER_PURGE`], [`cache::Cloudflare::KEYS_PER_PURGE`]) |
//! | `Cache\Adapter\Fastly` (`KEYS_PER_PURGE`) | [`cache::Fastly`] ([`cache::Fastly::KEYS_PER_PURGE`]) |
//! | `Cache\Adapter\Balancer` | [`cache::Balancer`] |
//! | `Utopia\Cdn\Extend\CdnOption` (`getAdapter()`, `getProvider()`, `isEdge()`, constants) | [`CdnOption`] ([`CdnOption::adapter`], [`CdnOption::provider`], [`CdnOption::is_edge`], [`CdnOption::PROVIDER_FASTLY`], ...) |
//! | `Utopia\Cdn\Certificates` | [`certificates::Certificates`] |
//! | `Utopia\Cdn\Certificates\Provider` | [`certificates::Provider`] |
//! | `issueCertificate()`, `isInstantGeneration()`, `getCertificateStatus()`, `isRenewRequired()`, `deleteCertificate()` | [`certificates::Provider::issue_certificate`], [`certificates::Provider::is_instant_generation`], [`certificates::Provider::certificate_status`], [`certificates::Provider::is_renew_required`], [`certificates::Provider::delete_certificate`] |
//! | `Certificates\Status` | [`certificates::Status`] |
//! | `Certificates\Challenge` | [`certificates::Challenge`] |
//! | `Certificates\Provider\Cloudflare`, `Fastly`, `FastlyTls`, `Proxy` | [`certificates::Cloudflare`], [`certificates::Fastly`], [`certificates::FastlyTls`], [`certificates::Proxy`] |
//! | `FastlyTls::CHALLENGE_MANAGED_DNS` | [`certificates::FastlyTls::CHALLENGE_MANAGED_DNS`] |
//! | the `$client` of a provider (`Psr\Http\Client\ClientInterface`) | any [`utopia_client::Adapter`]; [`DefaultClient`] by default |
//! | `Exception\Certificate` (`getStatus()`, `getChallenges()`, `getWarnings()`, `isBlocked()`) | [`Error::Certificate`] with [`Blocked`] ([`Blocked::is_blocked`]) |
//! | `Exception\Purge` (`getErrors()`) | [`Error::Purge`] |
//! | `Exception\Configuration`, `UnsupportedOperation` | [`Error::Configuration`], [`Error::UnsupportedOperation`] |
//!
//! PHP passes optional constructor arguments positionally; here each is a
//! `with_*` method (`with_service_id`, `with_soft_purge`, `with_api_base`,
//! `with_client`, ...). Certificate statuses are a [`certificates::Status`]
//! enum rather than strings.
#![warn(missing_docs)]

mod api;
pub mod cache;
pub mod certificates;
pub mod domain;
mod error;
mod option;

use std::future::Future;
use std::pin::Pin;

pub use api::DefaultClient;
pub use error::{Blocked, Error};
pub use option::CdnOption;

/// A boxed future, as the [`cache::Adapter`] and
/// [`certificates::Provider`] methods return (so both traits are object
/// safe).
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
