//! PHP `Utopia\Cdn\Cache\Adapter`.

mod balancer;
mod cloudflare;
mod fastly;

pub use balancer::Balancer;
pub use cloudflare::Cloudflare;
pub use fastly::Fastly;

use std::sync::Arc;

use crate::CdnError;

/// The four purge operations every provider adapter offers.
pub trait Adapter: Send + Sync {
    fn purge_paths(&self, domain: &str, paths: &[String]) -> Result<(), CdnError>;
    fn purge_domain(&self, domain: &str) -> Result<(), CdnError>;
    fn purge_keys(&self, keys: &[String]) -> Result<(), CdnError>;
    fn purge_zone(&self) -> Result<(), CdnError>;
}

impl Adapter for Arc<dyn Adapter> {
    fn purge_paths(&self, domain: &str, paths: &[String]) -> Result<(), CdnError> {
        (**self).purge_paths(domain, paths)
    }
    fn purge_domain(&self, domain: &str) -> Result<(), CdnError> {
        (**self).purge_domain(domain)
    }
    fn purge_keys(&self, keys: &[String]) -> Result<(), CdnError> {
        (**self).purge_keys(keys)
    }
    fn purge_zone(&self) -> Result<(), CdnError> {
        (**self).purge_zone()
    }
}
