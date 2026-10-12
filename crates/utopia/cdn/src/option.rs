//! [`CdnOption`]: a balancer option carrying a cache adapter.

use std::fmt;
use std::sync::Arc;

use crate::cache::Adapter;

/// A balancer option that carries a cache adapter, the vendor it talks to,
/// and whether it fronts the platform's own edge network rather than
/// customer-owned custom domains.
///
/// Filters read the typed accessors, so a [`cache::Balancer`] can narrow
/// its options by provider or edge without string keys.
///
/// ```
/// use std::sync::Arc;
///
/// use utopia_balancer::{Balancer, First};
/// use utopia_cdn::CdnOption;
/// use utopia_cdn::cache::Cloudflare;
///
/// let mut balancer = Balancer::new(First);
/// balancer
///     .add_option(CdnOption::new(Arc::new(Cloudflare::new("zone", "token")), CdnOption::PROVIDER_CLOUDFLARE, false))
///     .add_filter(|option: &CdnOption| !option.is_edge());
/// assert_eq!(balancer.filtered().len(), 1);
/// assert_eq!(balancer.filtered()[0].provider(), "cloudflare");
/// ```
///
/// ```php
/// use Utopia\Balancer\Algorithm\First;
/// use Utopia\Balancer\Balancer;
/// use Utopia\Cdn\Cache\Adapter\Cloudflare;
/// use Utopia\Cdn\Extend\CdnOption;
///
/// $balancer = new Balancer(new First());
/// $balancer
///     ->addOption(new CdnOption(new Cloudflare('zone', 'token'), CdnOption::PROVIDER_CLOUDFLARE))
///     ->addFilter(fn (CdnOption $option): bool => !$option->isEdge());
/// echo count($balancer->getFilteredOptions()), "\n"; // 1
/// echo $balancer->getFilteredOptions()[0]->getProvider(), "\n"; // cloudflare
/// ```
///
/// PHP: `Utopia\Cdn\Extend\CdnOption`, a `Utopia\Balancer\Option` whose
/// state keys are typed fields here, so state of the wrong type (which PHP
/// reports with `Exception\Configuration`) cannot be set.
///
/// [`cache::Balancer`]: crate::cache::Balancer
#[derive(Clone)]
pub struct CdnOption {
    adapter: Arc<dyn Adapter>,
    provider: String,
    edge: bool,
}

impl CdnOption {
    /// The state key of the adapter.
    ///
    /// PHP: `CdnOption::ADAPTER`.
    pub const ADAPTER: &str = "adapter";
    /// The state key of the provider.
    ///
    /// PHP: `CdnOption::PROVIDER`.
    pub const PROVIDER: &str = "provider";
    /// The state key of the edge flag.
    ///
    /// PHP: `CdnOption::EDGE`.
    pub const EDGE: &str = "edge";
    /// Fastly.
    ///
    /// PHP: `CdnOption::PROVIDER_FASTLY`.
    pub const PROVIDER_FASTLY: &str = "fastly";
    /// Cloudflare.
    ///
    /// PHP: `CdnOption::PROVIDER_CLOUDFLARE`.
    pub const PROVIDER_CLOUDFLARE: &str = "cloudflare";

    /// An option purging through `adapter`, for the vendor `provider` (one
    /// of the `PROVIDER_*` constants, or your own name), fronting the edge
    /// network when `edge` is set.
    ///
    /// PHP: `new CdnOption($adapter, $provider, $edge)`.
    pub fn new(adapter: Arc<dyn Adapter>, provider: impl Into<String>, edge: bool) -> Self {
        CdnOption { adapter, provider: provider.into(), edge }
    }

    /// The adapter that purges for this option.
    ///
    /// PHP: `getAdapter()`.
    pub fn adapter(&self) -> &Arc<dyn Adapter> {
        &self.adapter
    }

    /// The vendor the adapter talks to.
    ///
    /// PHP: `getProvider()`.
    pub fn provider(&self) -> &str {
        &self.provider
    }

    /// Whether the option fronts the platform's own edge network.
    ///
    /// PHP: `isEdge()`.
    pub fn is_edge(&self) -> bool {
        self.edge
    }
}

impl fmt::Debug for CdnOption {
    /// Writes the provider and the edge flag.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("CdnOption").field("provider", &self.provider).field("edge", &self.edge).finish_non_exhaustive()
    }
}
