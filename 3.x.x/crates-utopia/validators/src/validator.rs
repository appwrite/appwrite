use crate::types::ValueType;
use serde_json::Value;

/// Core validator trait.
pub trait Validator: Send + Sync {
    fn description(&self) -> String;
    fn is_array(&self) -> bool {
        false
    }
    fn value_type(&self) -> ValueType;
    fn is_valid(&self, value: &Value) -> bool;
}

impl<T: Validator + ?Sized> Validator for Box<T> {
    fn description(&self) -> String {
        (**self).description()
    }
    fn is_array(&self) -> bool {
        (**self).is_array()
    }
    fn value_type(&self) -> ValueType {
        (**self).value_type()
    }
    fn is_valid(&self, value: &Value) -> bool {
        (**self).is_valid(value)
    }
}
