//! PHP `Utopia\Database\Validator\IndexDependency`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::document::Document;
use crate::value::AttrValue;

/// PHP `Utopia\Database\Validator\IndexDependency`.
#[derive(Debug)]
pub struct IndexDependency {
    indexes: Vec<Document>,
    cast_index_support: bool,
    message: Mutex<String>,
}

impl IndexDependency {
    #[must_use]
    pub fn new(indexes: Vec<Document>, cast_index_support: bool) -> Self {
        Self {
            indexes,
            cast_index_support,
            message: Mutex::new(
                "Attribute can't be deleted or renamed because it is used in an index".into(),
            ),
        }
    }

    pub fn is_valid_document(&self, value: &Document) -> bool {
        if !self.cast_index_support {
            return true;
        }
        if !value.get_attribute("array").as_bool().unwrap_or(false) {
            return true;
        }
        let key = match value.get_attribute("key") {
            AttrValue::String(s) if !s.is_empty() => s.to_ascii_lowercase(),
            _ => value.get_id().to_ascii_lowercase(),
        };
        for index in &self.indexes {
            if let AttrValue::Array(attrs) = index.get_attribute("attributes") {
                for attribute in attrs.values() {
                    if attribute
                        .as_str()
                        .is_some_and(|a| a.eq_ignore_ascii_case(&key))
                    {
                        return false;
                    }
                }
            }
        }
        true
    }
}

impl Validator for IndexDependency {
    fn description(&self) -> String {
        self.message.lock().clone()
    }
    fn value_type(&self) -> ValueType {
        ValueType::Object
    }
    fn is_valid(&self, value: &Value) -> bool {
        Document::try_from_json(value.clone())
            .map(|d| self.is_valid_document(&d))
            .unwrap_or(false)
    }
}
