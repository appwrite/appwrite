use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone)]
pub struct Host {
    allow: Vec<String>,
}

impl Host {
    pub fn new(allow: impl IntoIterator<Item = impl Into<String>>) -> Self {
        Self {
            allow: allow.into_iter().map(Into::into).collect(),
        }
    }
}

impl Validator for Host {
    fn description(&self) -> String {
        format!(
            "Value must be one of these hosts ({})",
            self.allow.join(", ")
        )
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(s) = value.as_str() else {
            return false;
        };
        self.allow.iter().any(|h| h.eq_ignore_ascii_case(s))
    }
}
