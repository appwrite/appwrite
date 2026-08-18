use crate::canonicals::provider::{
    remove_dots, remove_plus_addressing, require_non_empty_local, to_lower_case, Canonical,
    Provider,
};
use crate::EmailError;

/// Gmail / Googlemail canonicalization (PHP `Gmail`).
#[derive(Debug, Clone, Copy, Default)]
pub struct Gmail;

const SUPPORTED_DOMAINS: &[&str] = &["gmail.com", "googlemail.com"];
const CANONICAL_DOMAIN: &str = "gmail.com";

impl Provider for Gmail {
    fn supports(&self, domain: &str) -> bool {
        SUPPORTED_DOMAINS.contains(&domain)
    }

    fn get_canonical(&self, local: &str, _domain: &str) -> Result<Canonical, EmailError> {
        let normalized = to_lower_case(local);
        let normalized = remove_plus_addressing(&normalized);
        let normalized = remove_dots(&normalized);
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
