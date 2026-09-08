use crate::{Validator, ValueType};
use serde_json::Value;
use std::fmt;
use std::sync::Arc;

#[derive(Clone)]
pub struct Nullable {
    inner: Arc<dyn Validator>,
}

impl fmt::Debug for Nullable {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Nullable")
            .field("inner", &"Arc<dyn Validator>")
            .finish()
    }
}

impl Nullable {
    pub fn new(inner: impl Validator + 'static) -> Self {
        Self {
            inner: Arc::new(inner),
        }
    }
}

impl Validator for Nullable {
    fn description(&self) -> String {
        format!("Value can be null or {}", self.inner.description())
    }

    fn is_array(&self) -> bool {
        self.inner.is_array()
    }

    fn value_type(&self) -> ValueType {
        self.inner.value_type()
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.is_null() || self.inner.is_valid(value)
    }
}
