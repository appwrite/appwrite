//! PHP `Utopia\Database\Validator\Query\Base`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::query::Query;

pub const METHOD_TYPE_LIMIT: &str = "limit";
pub const METHOD_TYPE_OFFSET: &str = "offset";
pub const METHOD_TYPE_CURSOR: &str = "cursor";
pub const METHOD_TYPE_ORDER: &str = "order";
pub const METHOD_TYPE_FILTER: &str = "filter";
pub const METHOD_TYPE_SELECT: &str = "select";

/// Shared query-validator helpers.
pub trait QueryMethodValidator: Validator {
    fn method_type(&self) -> &'static str;
    fn is_valid_query(&self, query: &Query) -> bool;
}

/// PHP `Utopia\Database\Validator\Query\Base`.
#[derive(Debug)]
pub struct Base {
    message: Mutex<String>,
}

impl Default for Base {
    fn default() -> Self {
        Self {
            message: Mutex::new("Invalid query".into()),
        }
    }
}

impl Base {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    pub fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }

    pub fn message(&self) -> String {
        self.message.lock().clone()
    }
}

impl Validator for Base {
    fn description(&self) -> String {
        self.message()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Object
    }

    fn is_valid(&self, _value: &Value) -> bool {
        false
    }
}
