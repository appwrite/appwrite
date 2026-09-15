use crate::canonicals::provider::{to_lower_case, Canonical, Provider};
use crate::EmailError;

/// Walla canonicalization (PHP `Walla`) - case + domain only.
#[derive(Debug, Clone, Copy, Default)]
pub struct Walla;

const SUPPORTED_DOMAINS: &[&str] = &["walla.co.il", "walla.com"];
const CANONICAL_DOMAIN: &str = "walla.co.il";

impl Provider for Walla {
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
