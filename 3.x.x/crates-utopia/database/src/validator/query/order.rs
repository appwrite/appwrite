//! PHP `Utopia\Database\Validator\Query\Order`.

use indexmap::IndexMap;
use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::document::Document;
use crate::query::{Query, TYPE_ORDER_ASC, TYPE_ORDER_DESC, TYPE_ORDER_RANDOM};
use crate::validator::query::base::{QueryMethodValidator, METHOD_TYPE_ORDER};

/// PHP `Utopia\Database\Validator\Query\Order`.
#[derive(Debug)]
pub struct Order {
    schema: IndexMap<String, Value>,
    support_for_attributes: bool,
    message: Mutex<String>,
}

impl Clone for Order {
    fn clone(&self) -> Self {
        Self {
            schema: self.schema.clone(),
            support_for_attributes: self.support_for_attributes,
            message: Mutex::new(self.message.lock().clone()),
        }
    }
}

impl Order {
    #[must_use]
    pub fn new(attributes: &[Document], support_for_attributes: bool) -> Self {
        let mut schema = IndexMap::new();
        for attribute in attributes {
            let key = match attribute.get_attribute("key") {
                crate::value::AttrValue::String(s) if !s.is_empty() => s.clone(),
                _ => attribute.get_id(),
            };
            schema.insert(key, Value::Object(attribute.get_array_copy_json(&[], &[])));
        }
        Self {
            schema,
            support_for_attributes,
            message: Mutex::new("Invalid query".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }

    fn is_valid_attribute(&self, attribute: &str) -> bool {
        if attribute.contains('.') {
            if self.schema.contains_key(attribute) {
                return true;
            }
            let top = attribute.split('.').next().unwrap_or(attribute);
            if self.schema.contains_key(top) {
                self.set_message(format!("Cannot order by nested attribute: {top}"));
                return false;
            }
        }
        if self.support_for_attributes && !self.schema.contains_key(attribute) {
            self.set_message(format!("Attribute not found in schema: {attribute}"));
            return false;
        }
        true
    }
}

impl QueryMethodValidator for Order {
    fn method_type(&self) -> &'static str {
        METHOD_TYPE_ORDER
    }

    fn is_valid_query(&self, query: &Query) -> bool {
        match query.get_method() {
            TYPE_ORDER_ASC | TYPE_ORDER_DESC => self.is_valid_attribute(query.get_attribute()),
            TYPE_ORDER_RANDOM => true,
            _ => false,
        }
    }
}

impl Validator for Order {
    fn description(&self) -> String {
        self.message.lock().clone()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Object
    }

    fn is_valid(&self, _value: &Value) -> bool {
        false
    }
}
