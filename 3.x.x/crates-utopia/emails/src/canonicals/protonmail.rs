use crate::canonicals::provider::{remove_plus_addressing, to_lower_case, Canonical, Provider};
use crate::EmailError;

/// Proton Mail canonicalization (PHP `Protonmail`).
///
/// Keeps the original domain when it is supported; otherwise `protonmail.com`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Protonmail;

const SUPPORTED_DOMAINS: &[&str] = &["protonmail.com", "proton.me", "pm.me", "protonmail.ch"];
const CANONICAL_DOMAIN: &str = "protonmail.com";

impl Provider for Protonmail {
    fn supports(&self, domain: &str) -> bool {
        SUPPORTED_DOMAINS.contains(&domain)
    }

    fn get_canonical(&self, local: &str, domain: &str) -> Result<Canonical, EmailError> {
        let normalized = to_lower_case(local);
        let normalized = remove_plus_addressing(&normalized);
        let canonical_domain = if SUPPORTED_DOMAINS.contains(&domain) {
            domain
        } else {
            CANONICAL_DOMAIN
        };
        Ok(Canonical {
            local: normalized,
            domain: canonical_domain.to_string(),
        })
    }

    fn get_canonical_domain(&self) -> &'static str {
        CANONICAL_DOMAIN
    }

    fn get_supported_domains(&self) -> &'static [&'static str] {
        SUPPORTED_DOMAINS
    }
}
