use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone)]
pub struct Range {
    min: f64,
    max: f64,
    format: &'static str,
}

impl Range {
    pub fn new(min: f64, max: f64) -> Self {
        Self {
            min,
            max,
            format: "float",
        }
    }

    pub fn integer(min: i64, max: i64) -> Self {
        Self {
            min: min as f64,
            max: max as f64,
            format: "integer",
        }
    }
}

impl Validator for Range {
    fn description(&self) -> String {
        format!(
            "Value must be a valid {} and no smaller than {} and no larger than {}",
            self.format, self.min, self.max
        )
    }

    fn value_type(&self) -> ValueType {
        if self.format == "integer" {
            ValueType::Integer
        } else {
            ValueType::Float
        }
    }

    fn is_valid(&self, value: &Value) -> bool {
        let n = match value {
            Value::Number(num) => num.as_f64(),
            Value::String(s) => s.parse::<f64>().ok(),
            _ => None,
        };
        match n {
            Some(v) => v >= self.min && v <= self.max,
            None => false,
        }
    }
}
