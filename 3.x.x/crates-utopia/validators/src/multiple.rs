use crate::{Validator, ValueType};
use serde_json::Value;
use std::fmt;
use std::sync::Arc;

/// Validate that every element of an array passes the inner validator.
#[derive(Clone)]
pub struct Multiple {
    inner: Arc<dyn Validator>,
}

impl fmt::Debug for Multiple {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Multiple")
            .field("inner", &"Arc<dyn Validator>")
            .finish()
    }
}

impl Multiple {
    pub fn new(inner: impl Validator + 'static) -> Self {
        Self {
            inner: Arc::new(inner),
        }
    }
}

impl Validator for Multiple {
    fn description(&self) -> String {
        format!("Every value must be {}", self.inner.description())
    }

    fn is_array(&self) -> bool {
        true
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::Array(items) => items.iter().all(|v| self.inner.is_valid(v)),
            other => self.inner.is_valid(other),
        }
    }
}
