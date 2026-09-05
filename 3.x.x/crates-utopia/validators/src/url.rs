use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone, Default)]
pub struct Url {
    allowed_schemes: Vec<String>,
}

impl Url {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn schemes(mut self, schemes: impl IntoIterator<Item = impl Into<String>>) -> Self {
        self.allowed_schemes = schemes.into_iter().map(Into::into).collect();
        self
    }
}

impl Validator for Url {
    fn description(&self) -> String {
        "Value must be a valid URL".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(s) = value.as_str() else {
            return false;
        };
        let Ok(parsed) = url::Url::parse(s) else {
            return false;
        };
        if !self.allowed_schemes.is_empty() {
            let scheme = parsed.scheme();
            return self
                .allowed_schemes
                .iter()
                .any(|s| s.eq_ignore_ascii_case(scheme));
        }
        matches!(parsed.scheme(), "http" | "https")
    }
}
