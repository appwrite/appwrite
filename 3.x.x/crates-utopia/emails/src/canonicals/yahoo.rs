use crate::canonicals::provider::{
    remove_hyphen_subaddress, require_non_empty_local, to_lower_case, Canonical, Provider,
};
use crate::EmailError;

/// Yahoo canonicalization (PHP `Yahoo`).
#[derive(Debug, Clone, Copy, Default)]
pub struct Yahoo;

const SUPPORTED_DOMAINS: &[&str] = &[
    "yahoo.com",
    "yahoo.co.uk",
    "yahoo.ca",
    "yahoo.de",
    "yahoo.fr",
    "yahoo.in",
    "yahoo.it",
    "ymail.com",
    "rocketmail.com",
];
const CANONICAL_DOMAIN: &str = "yahoo.com";

impl Provider for Yahoo {
    fn supports(&self, domain: &str) -> bool {
        SUPPORTED_DOMAINS.contains(&domain)
    }

    fn get_canonical(&self, local: &str, _domain: &str) -> Result<Canonical, EmailError> {
        let normalized = to_lower_case(local);
        let normalized = remove_hyphen_subaddress(&normalized);
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
