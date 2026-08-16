//! PHP `Utopia\Database\Validator\Query\Limit`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Numeric, Validator, ValueType};

use crate::query::{Query, TYPE_LIMIT};
use crate::validator::query::base::{QueryMethodValidator, METHOD_TYPE_LIMIT};

/// PHP `Utopia\Database\Validator\Query\Limit`.
#[derive(Debug)]
pub struct Limit {
    max_limit: i64,
    message: Mutex<String>,
}

impl Clone for Limit {
    fn clone(&self) -> Self {
        Self {
            max_limit: self.max_limit,
            message: Mutex::new(self.message.lock().clone()),
        }
    }
}

impl Limit {
    #[must_use]
    pub fn new(max_limit: i64) -> Self {
        Self {
            max_limit,
            message: Mutex::new("Invalid query".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }
}

impl Default for Limit {
    fn default() -> Self {
        Self::new(i64::MAX)
    }
}

impl QueryMethodValidator for Limit {
    fn method_type(&self) -> &'static str {
        METHOD_TYPE_LIMIT
    }

    fn is_valid_query(&self, query: &Query) -> bool {
        if query.get_method() != TYPE_LIMIT {
            self.set_message(format!("Invalid query method: {}", query.get_method()));
            return false;
        }
        let limit = query.get_value().to_json();
        if !Numeric.is_valid(&limit) {
            self.set_message(format!("Invalid limit: {}", Numeric.description()));
            return false;
        }
        let n = limit
            .as_i64()
            .or_else(|| limit.as_f64().map(|f| f as i64))
            .unwrap_or(0);
        if n < 1 || n > self.max_limit {
            self.set_message(format!(
                "Invalid limit: Value must be a valid range between 1 and {}",
                self.max_limit
            ));
            return false;
        }
        true
    }
}

impl Validator for Limit {
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
