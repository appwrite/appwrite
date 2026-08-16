use crate::canonicals::provider::{to_lower_case, Canonical, Provider};
use crate::EmailError;

/// Yandex canonicalization (PHP `Yandex`) - case + domain only.
///
/// Not registered in [`crate::Email`]'s provider list (PHP `initializeProviders`
/// omits Yandex); the type is still public and unit-tested like PHP.
#[derive(Debug, Clone, Copy, Default)]
pub struct Yandex;

const SUPPORTED_DOMAINS: &[&str] = &[
    "yandex.ru",
    "yandex.ua",
    "yandex.kz",
    "yandex.com",
    "yandex.by",
    "ya.ru",
];
const CANONICAL_DOMAIN: &str = "yandex.ru";

impl Provider for Yandex {
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
