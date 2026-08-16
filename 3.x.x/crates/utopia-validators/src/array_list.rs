use crate::{Validator, ValueType};
use serde_json::Value;
use std::fmt;
use std::sync::Arc;

#[derive(Clone)]
pub struct ArrayList {
    element: Arc<dyn Validator>,
    length: Option<usize>,
}

impl fmt::Debug for ArrayList {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("ArrayList")
            .field("element", &"Arc<dyn Validator>")
            .field("length", &self.length)
            .finish()
    }
}

impl ArrayList {
    pub fn new(element: impl Validator + 'static) -> Self {
        Self {
            element: Arc::new(element),
            length: None,
        }
    }

    pub fn length(mut self, length: usize) -> Self {
        self.length = Some(length);
        self
    }
}

impl Validator for ArrayList {
    fn description(&self) -> String {
        format!("Value must be an array of {}", self.element.description())
    }

    fn is_array(&self) -> bool {
        true
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(arr) = value.as_array() else {
            return false;
        };
        if let Some(len) = self.length {
            if arr.len() != len {
                return false;
            }
        }
        arr.iter().all(|v| self.element.is_valid(v))
    }
}
