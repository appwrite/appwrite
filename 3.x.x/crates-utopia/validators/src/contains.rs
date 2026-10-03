use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone)]
pub struct Contains {
    needle: String,
    ignore_case: bool,
}

impl Contains {
    pub fn new(needle: impl Into<String>) -> Self {
        Self {
            needle: needle.into(),
            ignore_case: false,
        }
    }

    pub fn ignore_case(mut self, ignore: bool) -> Self {
        self.ignore_case = ignore;
        self
    }
}

impl Validator for Contains {
    fn description(&self) -> String {
        format!("Value must contain \"{}\"", self.needle)
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(hay) = value.as_str() else {
            return false;
        };
        if self.ignore_case {
            hay.to_ascii_lowercase()
                .contains(&self.needle.to_ascii_lowercase())
        } else {
            hay.contains(&self.needle)
        }
    }
}
