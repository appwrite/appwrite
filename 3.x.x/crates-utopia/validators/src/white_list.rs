use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone)]
pub struct WhiteList {
    list: Vec<String>,
    strict: bool,
    value_type: ValueType,
}

impl WhiteList {
    pub fn new(list: impl IntoIterator<Item = impl Into<String>>) -> Self {
        Self {
            list: list.into_iter().map(Into::into).collect(),
            strict: false,
            value_type: ValueType::String,
        }
    }

    pub fn strict(mut self, strict: bool) -> Self {
        if !strict {
            self.list = self.list.iter().map(|s| s.to_ascii_lowercase()).collect();
        }
        self.strict = strict;
        self
    }

    pub fn value_type(mut self, t: ValueType) -> Self {
        self.value_type = t;
        self
    }

    pub fn list(&self) -> &[String] {
        &self.list
    }
}

impl Validator for WhiteList {
    fn description(&self) -> String {
        format!("Value must be one of ({})", self.list.join(", "))
    }

    fn value_type(&self) -> ValueType {
        self.value_type
    }

    fn is_valid(&self, value: &Value) -> bool {
        let s = match value {
            Value::String(s) => s.clone(),
            Value::Number(n) => n.to_string(),
            Value::Bool(b) => b.to_string(),
            _ => return false,
        };
        if self.strict {
            self.list.iter().any(|item| item == &s)
        } else {
            let lower = s.to_ascii_lowercase();
            self.list.iter().any(|item| item == &lower)
        }
    }
}
