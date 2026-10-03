use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone, Default)]
pub struct Numeric;

impl Validator for Numeric {
    fn description(&self) -> String {
        "Value must be numeric".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Float
    }

    fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Number(_) => true,
            Value::String(s) => s.parse::<f64>().is_ok(),
            _ => false,
        }
    }
}
