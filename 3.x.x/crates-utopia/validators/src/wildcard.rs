use crate::{Validator, ValueType};
use serde_json::Value;

/// Always valid.
#[derive(Debug, Clone, Default)]
pub struct Wildcard;

impl Validator for Wildcard {
    fn description(&self) -> String {
        "Every input is valid".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, _value: &Value) -> bool {
        true
    }
}
