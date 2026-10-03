use crate::{Validator, ValueType};
use serde_json::Value;
use std::fmt;
use std::iter::FromIterator;
use std::sync::Arc;

#[derive(Clone)]
pub struct AnyOf {
    validators: Vec<Arc<dyn Validator>>,
}

impl fmt::Debug for AnyOf {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("AnyOf")
            .field("validators", &format_args!("[{}]", self.validators.len()))
            .finish()
    }
}

impl AnyOf {
    pub fn new(validators: Vec<Arc<dyn Validator>>) -> Self {
        Self { validators }
    }
}

impl<V> FromIterator<V> for AnyOf
where
    V: Validator + 'static,
{
    fn from_iter<I: IntoIterator<Item = V>>(iter: I) -> Self {
        Self {
            validators: iter
                .into_iter()
                .map(|v| {
                    let arc: Arc<dyn Validator> = Arc::new(v);
                    arc
                })
                .collect(),
        }
    }
}

impl Validator for AnyOf {
    fn description(&self) -> String {
        "Value must pass at least one nested validator".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Mixed
    }

    fn is_valid(&self, value: &Value) -> bool {
        self.validators.iter().any(|v| v.is_valid(value))
    }
}
