use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone, Default)]
pub struct Boolean {
    loose: bool,
}

impl Boolean {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn loose(mut self, loose: bool) -> Self {
        self.loose = loose;
        self
    }
}

impl Validator for Boolean {
    fn description(&self) -> String {
        "Value must be a valid boolean".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Boolean
    }

    fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Bool(_) => true,
            Value::Number(n) if self.loose => n.as_u64() == Some(0) || n.as_u64() == Some(1),
            Value::String(s) if self.loose => {
                matches!(
                    s.to_ascii_lowercase().as_str(),
                    "true" | "false" | "0" | "1"
                )
            }
            _ => false,
        }
    }
}
