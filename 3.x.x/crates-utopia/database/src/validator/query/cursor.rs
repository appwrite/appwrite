//! PHP `Utopia\Database\Validator\Query\Cursor`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::MAX_UID_DEFAULT_LENGTH;
use crate::query::{Query, TYPE_CURSOR_AFTER, TYPE_CURSOR_BEFORE};
use crate::validator::query::base::{QueryMethodValidator, METHOD_TYPE_CURSOR};
use crate::validator::Uid;

/// PHP `Utopia\Database\Validator\Query\Cursor`.
#[derive(Debug)]
pub struct Cursor {
    max_length: i64,
    message: Mutex<String>,
}

impl Clone for Cursor {
    fn clone(&self) -> Self {
        Self {
            max_length: self.max_length,
            message: Mutex::new(self.message.lock().clone()),
        }
    }
}

impl Cursor {
    #[must_use]
    pub fn new(max_length: i64) -> Self {
        Self {
            max_length,
            message: Mutex::new("Invalid query".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }
}

impl Default for Cursor {
    fn default() -> Self {
        Self::new(MAX_UID_DEFAULT_LENGTH)
    }
}

impl QueryMethodValidator for Cursor {
    fn method_type(&self) -> &'static str {
        METHOD_TYPE_CURSOR
    }

    fn is_valid_query(&self, query: &Query) -> bool {
        if query.get_method() != TYPE_CURSOR_AFTER && query.get_method() != TYPE_CURSOR_BEFORE {
            return false;
        }
        let cursor = match query.get_value() {
            crate::value::AttrValue::Document(d) => Value::String(d.get_id()),
            other => other.to_json(),
        };
        let validator = Uid::new(self.max_length);
        if validator.is_valid(&cursor) {
            return true;
        }
        self.set_message(format!("Invalid cursor: {}", validator.description()));
        false
    }
}

impl Validator for Cursor {
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
