//! PHP `Utopia\Database\Validator\Query\Offset`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Numeric, Validator, ValueType};

use crate::query::{Query, TYPE_OFFSET};
use crate::validator::query::base::{QueryMethodValidator, METHOD_TYPE_OFFSET};

/// PHP `Utopia\Database\Validator\Query\Offset`.
#[derive(Debug)]
pub struct Offset {
    max_offset: i64,
    message: Mutex<String>,
}

impl Clone for Offset {
    fn clone(&self) -> Self {
        Self {
            max_offset: self.max_offset,
            message: Mutex::new(self.message.lock().clone()),
        }
    }
}

impl Offset {
    #[must_use]
    pub fn new(max_offset: i64) -> Self {
        Self {
            max_offset,
            message: Mutex::new("Invalid query".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }
}

impl Default for Offset {
    fn default() -> Self {
        Self::new(i64::MAX)
    }
}

impl QueryMethodValidator for Offset {
    fn method_type(&self) -> &'static str {
        METHOD_TYPE_OFFSET
    }

    fn is_valid_query(&self, query: &Query) -> bool {
        if query.get_method() != TYPE_OFFSET {
            self.set_message(format!("Query method invalid: {}", query.get_method()));
            return false;
        }
        let offset = query.get_value().to_json();
        if !Numeric.is_valid(&offset) {
            self.set_message(format!("Invalid limit: {}", Numeric.description()));
            return false;
        }
        let n = offset
            .as_i64()
            .or_else(|| offset.as_f64().map(|f| f as i64))
            .unwrap_or(-1);
        if n < 0 || n > self.max_offset {
            self.set_message(format!(
                "Invalid offset: Value must be a valid range between 0 and {}",
                self.max_offset
            ));
            return false;
        }
        true
    }
}

impl Validator for Offset {
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
