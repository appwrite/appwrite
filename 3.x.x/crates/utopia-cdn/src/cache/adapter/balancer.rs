//! PHP `Utopia\Cdn\Cache\Adapter\Balancer`.

use super::Adapter;
use crate::extend::{OptionBalancer, OptionKind};
use crate::{CdnError, Configuration, Domain, Purge, UnsupportedOperation};

/// Purges through every option a balancer's filters leave standing.
pub struct Balancer {
    balancer: OptionBalancer,
}

impl std::fmt::Debug for Balancer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Balancer").finish_non_exhaustive()
    }
}

impl Balancer {
    #[must_use]
    pub fn new(balancer: OptionBalancer) -> Self {
        Self { balancer }
    }

    fn each<F>(&self, operation: &str, mut purge: F) -> Result<(), CdnError>
    where
        F: FnMut(&dyn Adapter) -> Result<(), CdnError>,
    {
        let options = self.balancer.filtered();
        if options.is_empty() {
            return Err(
                Configuration("No cache options matched the balancer filters.".into()).into(),
            );
        }
        let mut errors = Vec::new();
        let mut failed = Vec::new();
        let mut purged = false;
        for option in &options {
            let OptionKind::Cdn(cdn) = option else {
                return Err(Configuration(
                    "Cache options must be instances of Utopia\\Cdn\\Extend\\CdnOption.".into(),
                )
                .into());
            };
            let adapter = cdn.get_adapter()?;
            match purge(adapter.as_ref()) {
                Ok(()) => purged = true,
                Err(CdnError::UnsupportedOperation(_)) => {}
                Err(err) => {
                    failed.push(cdn.get_provider()?.to_owned());
                    errors.push(err);
                }
            }
        }
        if !errors.is_empty() {
            // PHP `array_unique` keeps first-seen order.
            let mut unique = Vec::new();
            for name in &failed {
                if !unique.contains(name) {
                    unique.push(name.clone());
                }
            }
            return Err(Purge::new(
                format!("Cache {operation} failed for {}.", unique.join(", ")),
                errors,
            )
            .into());
        }
        if !purged {
            return Err(UnsupportedOperation(format!(
                "Cache {operation} is not supported by any matching option."
            ))
            .into());
        }
        Ok(())
    }
}

impl Adapter for Balancer {
    fn purge_paths(&self, domain: &str, paths: &[String]) -> Result<(), CdnError> {
        let domain = Domain::validate(domain)?;
        let paths = Domain::validate_paths(paths)?;
        if paths.is_empty() {
            return Ok(());
        }
        self.each("path purging", |adapter| {
            adapter.purge_paths(&domain, &paths)
        })
    }

    fn purge_domain(&self, domain: &str) -> Result<(), CdnError> {
        let domain = Domain::validate(domain)?;
        self.each("domain purging", |adapter| adapter.purge_domain(&domain))
    }

    fn purge_keys(&self, keys: &[String]) -> Result<(), CdnError> {
        if keys.is_empty() {
            return Ok(());
        }
        self.each("cache key purging", |adapter| adapter.purge_keys(keys))
    }

    fn purge_zone(&self) -> Result<(), CdnError> {
        self.each("zone purging", |adapter| adapter.purge_zone())
    }
}
