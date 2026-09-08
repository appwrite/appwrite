use crate::{Validator, ValueType};
use serde_json::Value;
use std::fmt;
use std::sync::Arc;

#[derive(Clone)]
pub struct NoneOf {
    validators: Vec<Arc<dyn Validator>>,
}

impl fmt::Debug for NoneOf {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("NoneOf")
            .field("validators", &format_args!("[{}]", self.validators.len()))
            .finish()
    }
}

impl NoneOf {
    pub fn new(validators: Vec<Arc<dyn Validator>>) -> Self {
        Self { validators }
    }
}

impl Validator for NoneOf {
    fn description(&self) -> String {
        "Value must fail all nested validators".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Mixed
    }

    fn is_valid(&self, value: &Value) -> bool {
        self.validators.iter().all(|v| !v.is_valid(value))
    }
}
