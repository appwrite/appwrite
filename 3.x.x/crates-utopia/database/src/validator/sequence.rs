//! PHP `Utopia\Database\Validator\Sequence`.

use serde_json::Value;
use utopia_validators::{Range, Validator, ValueType};

use crate::constants::{MAX_BIG_INT, VAR_INTEGER, VAR_UUID7};

/// PHP `Utopia\Database\Validator\Sequence`.
#[derive(Debug, Clone)]
pub struct Sequence {
    id_attribute_type: String,
    primary: bool,
}

impl Sequence {
    #[must_use]
    pub fn new(id_attribute_type: impl Into<String>, primary: bool) -> Self {
        Self {
            id_attribute_type: id_attribute_type.into(),
            primary,
        }
    }
}

impl Validator for Sequence {
    fn description(&self) -> String {
        "Invalid sequence value".into()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        if self.primary && php_empty(value) {
            return false;
        }
        if value.is_null() {
            return true;
        }
        if !value.is_string() && !value.is_i64() && !value.is_u64() {
            return false;
        }
        if !self.primary {
            return true;
        }
        match self.id_attribute_type.as_str() {
            VAR_UUID7 => {
                let Some(s) = value.as_str() else {
                    return false;
                };
                let re = regex::Regex::new(
                    r"^[a-f0-9]{8}-[a-f0-9]{4}-7[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$",
                )
                .expect("uuid7 regex");
                re.is_match(&s.to_ascii_lowercase())
            }
            VAR_INTEGER => Range::integer(1, MAX_BIG_INT).is_valid(value),
            _ => false,
        }
    }
}

fn php_empty(value: &Value) -> bool {
    match value {
        Value::Null => true,
        Value::Bool(false) => true,
        Value::Number(n) => n.as_i64() == Some(0) || n.as_u64() == Some(0),
        Value::String(s) => s.is_empty() || s == "0",
        Value::Array(a) => a.is_empty(),
        _ => false,
    }
}
