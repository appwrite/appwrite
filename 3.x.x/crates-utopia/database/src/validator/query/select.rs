//! PHP `Utopia\Database\Validator\Query\Select`.

use indexmap::IndexMap;
use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::internal_attributes;
use crate::document::Document;
use crate::query::{Query, TYPE_SELECT};
use crate::validator::query::base::{QueryMethodValidator, METHOD_TYPE_SELECT};

/// PHP `Utopia\Database\Validator\Query\Select`.
#[derive(Debug)]
pub struct Select {
    schema: IndexMap<String, Value>,
    support_for_attributes: bool,
    message: Mutex<String>,
}

impl Clone for Select {
    fn clone(&self) -> Self {
        Self {
            schema: self.schema.clone(),
            support_for_attributes: self.support_for_attributes,
            message: Mutex::new(self.message.lock().clone()),
        }
    }
}

impl Select {
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
}

impl QueryMethodValidator for Select {
    fn method_type(&self) -> &'static str {
        METHOD_TYPE_SELECT
    }

    fn is_valid_query(&self, query: &Query) -> bool {
        if query.get_method() != TYPE_SELECT {
            return false;
        }
        let internal_keys: Vec<String> = internal_attributes()
            .iter()
            .filter_map(|a| a.get("$id").and_then(Value::as_str).map(str::to_owned))
            .collect();
        if query.get_values().is_empty() {
            self.set_message("No attributes selected");
            return false;
        }
        let mut seen = Vec::new();
        for attribute in query.get_values() {
            let Some(s) = attribute.as_str() else {
                self.set_message(format!(
                    "Attribute selection must be a string, got {}",
                    crate::value::php_gettype_attr(attribute)
                ));
                return false;
            };
            if seen.contains(&s) {
                self.set_message("Duplicate attributes selected");
                return false;
            }
            seen.push(s);
        }
        for attribute in query.get_values() {
            let Some(mut attr) = attribute.as_str() else {
                continue;
            };
            if attr.contains('.') {
                if self.schema.contains_key(attr) {
                    continue;
                }
                attr = attr.split('.').next().unwrap_or(attr);
            }
            if internal_keys.iter().any(|k| k == attr) {
                continue;
            }
            if self.support_for_attributes && !self.schema.contains_key(attr) && attr != "*" {
                self.set_message(format!("Attribute not found in schema: {attr}"));
                return false;
            }
        }
        true
    }
}

impl Validator for Select {
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
