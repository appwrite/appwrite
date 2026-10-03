//! PHP `Utopia\Database\Validator\Vector`.

use serde_json::Value;
use utopia_validators::{Validator, ValueType};

/// PHP `Utopia\Database\Validator\Vector`.
#[derive(Debug, Clone)]
pub struct Vector {
    size: usize,
}

impl Vector {
    #[must_use]
    pub fn new(size: usize) -> Self {
        Self { size }
    }
}

impl Validator for Vector {
    fn description(&self) -> String {
        format!("Value must be an array of {} numeric values", self.size)
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        let arr = match value {
            Value::String(s) => match serde_json::from_str::<Value>(s) {
                Ok(Value::Array(a)) => a,
                _ => return false,
            },
            Value::Array(a) => a.clone(),
            _ => return false,
        };
        if arr.len() != self.size {
            return false;
        }
        arr.iter().all(|c| c.is_i64() || c.is_u64() || c.is_f64())
    }
}
