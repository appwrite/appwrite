use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone, Default)]
pub struct FloatValidator {
    loose: bool,
}

impl FloatValidator {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn loose(mut self, loose: bool) -> Self {
        self.loose = loose;
        self
    }
}

impl Validator for FloatValidator {
    fn description(&self) -> String {
        "Value must be a valid float".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Float
    }

    fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Number(n) => n.as_f64().is_some(),
            Value::String(s) if self.loose => s.parse::<f64>().is_ok(),
            _ => false,
        }
    }
}
