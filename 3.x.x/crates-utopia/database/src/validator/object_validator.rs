//! PHP `Utopia\Database\Validator\ObjectValidator`.

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

/// PHP `Utopia\Database\Validator\ObjectValidator`.
#[derive(Debug, Clone, Default)]
pub struct ObjectValidator;

impl Validator for ObjectValidator {
    fn description(&self) -> String {
        "Value must be a valid object".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Object
    }

    fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::String(s) => serde_json::from_str::<Value>(s).is_ok(),
            Value::Object(_) => true,
            Value::Array(a) => a.is_empty(),
            Value::Null => true,
            _ => false,
        }
    }
}
