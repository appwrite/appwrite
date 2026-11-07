//! A purge fanned out over every option a balancer's filters leave.

use utopia_balancer::Algorithm;

use crate::cache::Adapter;
use crate::{BoxFuture, CdnOption, Error, domain};

/// Purges through every [`CdnOption`] a balancer's filters leave standing.
///
/// The balancer holds every configured provider and the filters that narrow
/// them down, so a caller states what to purge and which options qualify,
/// never which API to call. A domain cached by several providers is purged
/// from all of them.
///
/// Options are purged one after the other, and one failing does not stop
/// the rest: the failures are reported together, as [`Error::Purge`], once
/// every option has been tried. An option that cannot serve the operation
/// ([`Error::UnsupportedOperation`]) is skipped; when every option skips,
/// the purge fails with [`Error::UnsupportedOperation`]. No option left
/// after the filters is [`Error::Configuration`].
///
/// ```
/// use std::sync::Arc;
///
/// use utopia_balancer::{Balancer as Options, First};
/// use utopia_cdn::CdnOption;
/// use utopia_cdn::cache::{Balancer, Cache, Cloudflare, Fastly};
///
/// let mut options = Options::new(First);
/// options
///     .add_option(CdnOption::new(Arc::new(Fastly::new("token", "domain-").with_service_id("edge")), CdnOption::PROVIDER_FASTLY, true))
///     .add_option(CdnOption::new(Arc::new(Cloudflare::new("zone", "token")), CdnOption::PROVIDER_CLOUDFLARE, false))
///     .add_filter(|option: &CdnOption| !option.is_edge());
///
/// let cache = Cache::new(Balancer::new(options));
/// assert_eq!(cache.adapter().options().filtered().len(), 1);
/// // cache.purge_domain("example.com").await purges through Cloudflare only.
/// ```
///
/// ```php
/// use Utopia\Balancer\Algorithm\First;
/// use Utopia\Balancer\Balancer as Options;
/// use Utopia\Cdn\Cache;
/// use Utopia\Cdn\Cache\Adapter\Balancer;
/// use Utopia\Cdn\Cache\Adapter\Cloudflare;
/// use Utopia\Cdn\Cache\Adapter\Fastly;
/// use Utopia\Cdn\Extend\CdnOption;
///
/// $options = new Options(new First());
/// $options
///     ->addOption(new CdnOption(new Fastly('token', 'domain-', 'edge'), CdnOption::PROVIDER_FASTLY, true))
///     ->addOption(new CdnOption(new Cloudflare('zone', 'token'), CdnOption::PROVIDER_CLOUDFLARE))
///     ->addFilter(fn (CdnOption $option): bool => !$option->isEdge());
///
/// $cache = new Cache(new Balancer($options));
/// echo count($options->getFilteredOptions()), "\n"; // 1
/// ```
///
/// PHP: `Utopia\Cdn\Cache\Adapter\Balancer`. PHP balancers take any
/// `Option`; this one holds [`CdnOption`]s only, so the `Configuration`
/// error PHP raises for another kind of option cannot happen.
pub struct Balancer<A> {
    options: utopia_balancer::Balancer<CdnOption, A>,
}

impl<A: Algorithm<CdnOption>> Balancer<A> {
    /// A fan-out over the options `options` filters leave.
    ///
    /// PHP: `new Balancer($balancer)`.
    pub fn new(options: utopia_balancer::Balancer<CdnOption, A>) -> Self {
        Balancer { options }
    }

    /// The balancer holding the options and filters.
    pub fn options(&self) -> &utopia_balancer::Balancer<CdnOption, A> {
        &self.options
    }

    /// The balancer, to add options or filters.
    pub fn options_mut(&mut self) -> &mut utopia_balancer::Balancer<CdnOption, A> {
        &mut self.options
    }

    async fn each<'a, F>(&'a self, operation: &str, purge: F) -> Result<(), Error>
    where
        F: Fn(&'a CdnOption) -> BoxFuture<'a, Result<(), Error>>,
    {
        let options = self.options.filtered();
        if options.is_empty() {
            return Err(Error::Configuration("No cache options matched the balancer filters.".into()));
        }
        let mut errors = Vec::new();
        let mut failed: Vec<&str> = Vec::new();
        let mut purged = false;
        for option in options {
            match purge(option).await {
                Ok(()) => purged = true,
                // An option that cannot serve this operation is not a
                // failure; the others still have to be purged.
                Err(Error::UnsupportedOperation(_)) => {}
                Err(error) => {
                    errors.push(error);
                    if !failed.contains(&option.provider()) {
                        failed.push(option.provider());
                    }
                }
            }
        }
        if !errors.is_empty() {
            return Err(Error::purge(format!("Cache {operation} failed for {}.", failed.join(", ")), errors));
        }
        if !purged {
            return Err(Error::UnsupportedOperation(format!(
                "Cache {operation} is not supported by any matching option."
            )));
        }
        Ok(())
    }
}

impl<A: Algorithm<CdnOption> + Send + Sync> Adapter for Balancer<A> {
    /// Purges the paths through every matching option (nothing for no paths).
    fn purge_paths<'a>(&'a self, domain: &'a str, paths: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move {
            let domain = domain::validate(domain)?;
            domain::validate_paths(paths)?;
            if paths.is_empty() {
                return Ok(());
            }
            let domain = &domain;
            self.each("path purging", |option| {
                Box::pin(async move { option.adapter().purge_paths(domain, paths).await })
            })
            .await
        })
    }

    /// Purges the domain through every matching option.
    fn purge_domain<'a>(&'a self, domain: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move {
            let domain = domain::validate(domain)?;
            let domain = &domain;
            self.each("domain purging", |option| Box::pin(async move { option.adapter().purge_domain(domain).await }))
                .await
        })
    }

    /// Purges the keys through every matching option (nothing for no keys).
    fn purge_keys<'a>(&'a self, keys: &'a [String]) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move {
            if keys.is_empty() {
                return Ok(());
            }
            self.each("cache key purging", |option| option.adapter().purge_keys(keys)).await
        })
    }

    /// Purges every zone behind a matching option: each provider drops
    /// everything it holds, for every domain.
    fn purge_zone<'a>(&'a self) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(self.each("zone purging", |option| option.adapter().purge_zone()))
    }
}
