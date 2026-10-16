use crate::canonicals::provider::{
    remove_plus_addressing, require_non_empty_local, to_lower_case, Canonical, Provider,
};
use crate::EmailError;

/// iCloud / me.com / mac.com canonicalization (PHP `Icloud`).
#[derive(Debug, Clone, Copy, Default)]
pub struct Icloud;

const SUPPORTED_DOMAINS: &[&str] = &["icloud.com", "me.com", "mac.com"];
const CANONICAL_DOMAIN: &str = "icloud.com";

impl Provider for Icloud {
    fn supports(&self, domain: &str) -> bool {
        SUPPORTED_DOMAINS.contains(&domain)
    }

    fn get_canonical(&self, local: &str, _domain: &str) -> Result<Canonical, EmailError> {
        let normalized = to_lower_case(local);
        let normalized = remove_plus_addressing(&normalized);
        let normalized = require_non_empty_local(normalized)?;
        Ok(Canonical {
            local: normalized,
            domain: CANONICAL_DOMAIN.to_string(),
        })
    }

    fn get_canonical_domain(&self) -> &'static str {
        CANONICAL_DOMAIN
    }

    fn get_supported_domains(&self) -> &'static [&'static str] {
        SUPPORTED_DOMAINS
    }
}
