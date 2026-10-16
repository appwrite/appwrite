use crate::canonicals::provider::{to_lower_case, Canonical, Provider};
use crate::EmailError;

/// Fastmail canonicalization (PHP `Fastmail`) - case + domain only.
#[derive(Debug, Clone, Copy, Default)]
pub struct Fastmail;

const SUPPORTED_DOMAINS: &[&str] = &["fastmail.com", "fastmail.fm"];
const CANONICAL_DOMAIN: &str = "fastmail.com";

impl Provider for Fastmail {
    fn supports(&self, domain: &str) -> bool {
        SUPPORTED_DOMAINS.contains(&domain)
    }

    fn get_canonical(&self, local: &str, _domain: &str) -> Result<Canonical, EmailError> {
        Ok(Canonical {
            local: to_lower_case(local),
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
