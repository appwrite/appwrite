use crate::{Validator, ValueType};
use serde_json::Value;
use std::fmt;
use std::iter::FromIterator;
use std::sync::Arc;

#[derive(Clone)]
pub struct AllOf {
    validators: Vec<Arc<dyn Validator>>,
}

impl fmt::Debug for AllOf {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("AllOf")
            .field("validators", &format_args!("[{}]", self.validators.len()))
            .finish()
    }
}

impl AllOf {
    pub fn new(validators: Vec<Arc<dyn Validator>>) -> Self {
        Self { validators }
    }
}

impl<V> FromIterator<V> for AllOf
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

impl Validator for AllOf {
    fn description(&self) -> String {
        "Value must pass all nested validators".into()
    }

    fn value_type(&self) -> ValueType {
        self.validators
            .first()
            .map_or(ValueType::Mixed, |v| v.value_type())
    }

    fn is_valid(&self, value: &Value) -> bool {
        self.validators.iter().all(|v| v.is_valid(value))
    }
}
