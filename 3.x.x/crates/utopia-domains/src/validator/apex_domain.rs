use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use super::PublicDomain;
use crate::Domain;

/// Validate that a value is a public apex domain (PHP `ApexDomain`).
#[derive(Debug, Clone, Default)]
pub struct ApexDomain {
    inner: PublicDomain,
}

impl ApexDomain {
    /// Create an apex-domain validator.
    pub fn new() -> Self {
        Self::default()
    }
}

impl Validator for ApexDomain {
    fn description(&self) -> String {
        "Value must be a public apex domain".into()
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
        let host = PublicDomain::extract_host(raw);
        if !self.inner.is_valid(&Value::String(host.clone())) {
            return false;
        }
        let Ok(domain) = Domain::new(&host) else {
            return false;
        };
        domain.get_apex() == host
    }
}
