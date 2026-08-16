use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::Domain;

static ALLOWED_DOMAINS: Mutex<Vec<String>> = Mutex::new(Vec::new());

/// Validate that a value is a known public domain (PHP `PublicDomain`).
#[derive(Debug, Clone, Default)]
pub struct PublicDomain;

impl PublicDomain {
    /// Create a public-domain validator.
    pub fn new() -> Self {
        Self
    }

    /// Append domains that should pass even when they are not on the PSL
    /// (PHP `PublicDomain::allow()`). Shared static state, like PHP.
    pub fn allow(domains: impl IntoIterator<Item = impl Into<String>>) {
        ALLOWED_DOMAINS
            .lock()
            .extend(domains.into_iter().map(Into::into));
    }

    /// Clear the allow-list (test helper; PHP has no reset).
    pub fn reset_allowed() {
        ALLOWED_DOMAINS.lock().clear();
    }

    pub(crate) fn extract_host(value: &str) -> String {
        if let Ok(parsed) = url::Url::parse(value) {
            if matches!(parsed.scheme(), "http" | "https") {
                if let Some(host) = parsed.host_str() {
                    return host.to_string();
                }
            }
        }
        value.to_string()
    }

    pub(crate) fn is_valid_host(host: &str) -> bool {
        let Ok(domain) = Domain::new(host) else {
            return false;
        };
        if domain.is_known() {
            return true;
        }
        ALLOWED_DOMAINS
            .lock()
            .iter()
            .any(|allowed| allowed == domain.get())
    }
}

impl Validator for PublicDomain {
    fn description(&self) -> String {
        "Value must be a public domain".into()
    }

    fn is_array(&self) -> bool {
        false
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(raw) = value.as_str() else {
            return false;
        };
        let host = Self::extract_host(raw);
        Self::is_valid_host(&host)
    }
}
