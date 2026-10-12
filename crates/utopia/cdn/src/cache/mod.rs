//! Purging cached content: the [`Adapter`] every provider implements, the
//! [`Cache`] front that validates what it is given, and the providers
//! ([`Cloudflare`], [`Fastly`], and [`Balancer`], which fans out over
//! several).

mod balancer;
mod cloudflare;
mod fastly;

use std::sync::Arc;

pub use balancer::Balancer;
pub use cloudflare::Cloudflare;
pub use fastly::Fastly;

use crate::{BoxFuture, Error, domain};

/// The purge operations every provider offers.
///
/// Providers differ in what they expose natively (Cloudflare purges a
/// hostname directly, Fastly has to be told which surrogate key stands for
/// one), but a caller does not have to know which: every adapter implements
/// every operation, or fails with [`Error::UnsupportedOperation`] when it
/// cannot serve one with the configuration it was given. Implement it to
/// add a provider.
///
/// The futures borrow their arguments, so the trait is object safe: a
/// [`Balancer`] holds `Arc<dyn Adapter>`s.
///
/// PHP: `Utopia\Cdn\Cache\Adapter`.
pub trait Adapter: Send + Sync {
    /// Purges these paths of a domain.
    ///
    /// PHP: `purgePaths($domain, $paths)`.
    fn purge_paths<'a>(&'a self, domain: &'a str, paths: &'a [String]) -> BoxFuture<'a, Result<(), Error>>;

    /// Purges everything cached for a domain.
    ///
    /// PHP: `purgeDomain($domain)`.
    fn purge_domain<'a>(&'a self, domain: &'a str) -> BoxFuture<'a, Result<(), Error>>;

    /// Purges what was cached under these keys (Cloudflare's cache tags,
    /// Fastly's surrogate keys).
    ///
    /// PHP: `purgeKeys($keys)`.
    fn purge_keys<'a>(&'a self, keys: &'a [String]) -> BoxFuture<'a, Result<(), Error>>;

    /// Purges everything the adapter is configured for, whatever domain it
    /// belongs to: Cloudflare's `purge_everything` for the zone, Fastly's
    /// `purge_all` for the service. Everything is then fetched from origin
    /// again, so prefer [`Adapter::purge_domain`] or [`Adapter::purge_keys`].
    ///
    /// PHP: `purgeZone()`.
    fn purge_zone<'a>(&'a self) -> BoxFuture<'a, Result<(), Error>>;
}

impl<T: Adapter + ?Sized> Adapter for Arc<T> {
    /// Purges through the shared adapter.
    fn purge_paths<'a>(&'a self, domain: &'a str, paths: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        (**self).purge_paths(domain, paths)
    }

    /// Purges through the shared adapter.
    fn purge_domain<'a>(&'a self, domain: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        (**self).purge_domain(domain)
    }

    /// Purges through the shared adapter.
    fn purge_keys<'a>(&'a self, keys: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        (**self).purge_keys(keys)
    }

    /// Purges through the shared adapter.
    fn purge_zone<'a>(&'a self) -> BoxFuture<'a, Result<(), Error>> {
        (**self).purge_zone()
    }
}

impl<T: Adapter + ?Sized> Adapter for Box<T> {
    /// Purges through the boxed adapter.
    fn purge_paths<'a>(&'a self, domain: &'a str, paths: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        (**self).purge_paths(domain, paths)
    }

    /// Purges through the boxed adapter.
    fn purge_domain<'a>(&'a self, domain: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        (**self).purge_domain(domain)
    }

    /// Purges through the boxed adapter.
    fn purge_keys<'a>(&'a self, keys: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        (**self).purge_keys(keys)
    }

    /// Purges through the boxed adapter.
    fn purge_zone<'a>(&'a self) -> BoxFuture<'a, Result<(), Error>> {
        (**self).purge_zone()
    }
}

/// Purges through an adapter, checking the domain and paths first.
///
/// ```no_run
/// use utopia_cdn::cache::{Cache, Cloudflare};
///
/// # async fn run() -> Result<(), utopia_cdn::Error> {
/// let cache = Cache::new(Cloudflare::new("zone-id", "api-token"));
/// cache.purge_paths("Example.com", &["/index.html".into()]).await?;
/// cache.purge_domain("example.com").await?;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Cdn\Cache;
/// use Utopia\Cdn\Cache\Adapter\Cloudflare;
///
/// $cache = new Cache(new Cloudflare('zone-id', 'api-token'));
/// // $cache->purgePaths('Example.com', ['/index.html']);
/// // $cache->purgeDomain('example.com');
/// ```
///
/// PHP: `Utopia\Cdn\Cache`.
#[derive(Debug, Clone)]
pub struct Cache<A> {
    adapter: A,
}

impl<A: Adapter> Cache<A> {
    /// A cache purging through `adapter`.
    ///
    /// PHP: `new Cache($adapter)`.
    pub fn new(adapter: A) -> Self {
        Cache { adapter }
    }

    /// The adapter.
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// Purges these paths of a domain, once the domain is a hostname and
    /// every path begins with `/` (else [`Error::InvalidArgument`]). The
    /// adapter receives the domain lower-cased.
    ///
    /// PHP: `purgePaths($domain, $paths)`.
    pub async fn purge_paths(&self, domain: &str, paths: &[String]) -> Result<(), Error> {
        let domain = domain::validate(domain)?;
        domain::validate_paths(paths)?;
        self.adapter.purge_paths(&domain, paths).await
    }

    /// Purges everything cached for a domain, once it is a hostname.
    ///
    /// PHP: `purgeDomain($domain)`.
    pub async fn purge_domain(&self, domain: &str) -> Result<(), Error> {
        let domain = domain::validate(domain)?;
        self.adapter.purge_domain(&domain).await
    }

    /// Purges what was cached under these keys.
    ///
    /// PHP: `purgeKeys($keys)`.
    pub async fn purge_keys(&self, keys: &[String]) -> Result<(), Error> {
        self.adapter.purge_keys(keys).await
    }

    /// Purges the adapter's entire cache. Prefer [`Cache::purge_domain`] or
    /// [`Cache::purge_keys`].
    ///
    /// PHP: `purgeZone()`.
    pub async fn purge_zone(&self) -> Result<(), Error> {
        self.adapter.purge_zone().await
    }
}
