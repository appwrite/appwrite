use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone, Default)]
pub struct Assoc;

impl Validator for Assoc {
    fn description(&self) -> String {
        "Value must be an associative array / object".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.as_object().is_some()
    }
}
