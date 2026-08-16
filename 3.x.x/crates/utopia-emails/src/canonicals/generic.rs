use crate::canonicals::provider::{to_lower_case, Canonical, Provider};
use crate::EmailError;

/// Generic canonicalization (PHP `Generic`) - lowercase only, original domain kept.
#[derive(Debug, Clone, Copy, Default)]
pub struct Generic;

impl Provider for Generic {
    fn supports(&self, _domain: &str) -> bool {
        true
    }

    fn get_canonical(&self, local: &str, domain: &str) -> Result<Canonical, EmailError> {
        Ok(Canonical {
            local: to_lower_case(local),
            domain: domain.to_string(),
        })
    }

    fn get_canonical_domain(&self) -> &'static str {
        ""
    }

    fn get_supported_domains(&self) -> &'static [&'static str] {
        &[]
    }
}
