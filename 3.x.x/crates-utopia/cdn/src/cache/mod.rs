//! PHP `Utopia\Cdn\Cache`.

pub mod adapter;

use adapter::Adapter;

use crate::{CdnError, Domain};

/// Facade that forwards the four purge operations to an [`Adapter`].
pub struct Cache {
    adapter: Box<dyn Adapter>,
}

impl std::fmt::Debug for Cache {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Cache").finish_non_exhaustive()
    }
}

impl Cache {
    #[must_use]
    pub fn new(adapter: impl Adapter + 'static) -> Self {
        Self {
            adapter: Box::new(adapter),
        }
    }

    #[must_use]
    pub fn from_boxed(adapter: Box<dyn Adapter>) -> Self {
        Self { adapter }
    }

    pub fn purge_paths(&self, domain: &str, paths: &[String]) -> Result<(), CdnError> {
        let domain = Domain::validate(domain)?;
        let paths = Domain::validate_paths(paths)?;
        self.adapter.purge_paths(&domain, &paths)
    }

    pub fn purge_domain(&self, domain: &str) -> Result<(), CdnError> {
        self.adapter.purge_domain(&Domain::validate(domain)?)
    }

    pub fn purge_keys(&self, keys: &[String]) -> Result<(), CdnError> {
        self.adapter.purge_keys(keys)
    }

    pub fn purge_zone(&self) -> Result<(), CdnError> {
        self.adapter.purge_zone()
    }
}
