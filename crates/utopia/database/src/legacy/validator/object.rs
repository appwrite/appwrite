//! [`ObjectValidator`]: values of object attributes.
//!
//! PHP: `Utopia\Database\Validator\ObjectValidator`.

use crate::value::Value;

/// An object value: a JSON string, a `stdClass`, an associative (non-list)
/// array, or an empty value (`null`, `false`, `0`, `[]`, ...).
///
/// ```
/// use utopia_database::validator::object::ObjectValidator;
/// use utopia_database::value::{list, string, Value};
///
/// assert!(ObjectValidator.is_valid(&string("{\"a\":1}")));
/// assert!(!ObjectValidator.is_valid(&list([Value::Int(1)])));
/// ```
///
/// PHP: `Utopia\Database\Validator\ObjectValidator`.
#[derive(Clone, Copy, Debug, Default)]
pub struct ObjectValidator;

impl ObjectValidator {
    /// `getDescription()`.
    pub fn description(&self) -> String {
        "Value must be a valid object".to_owned()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        match value {
            // Any valid JSON, as `json_decode` then `json_last_error()` sees it.
            Value::Str(s) => php_std::json::decode(
                s.as_bytes(),
                Some(false),
                php_std::json::DEFAULT_DEPTH,
                php_std::json::Flags::NONE,
            )
            .is_ok(),
            Value::Object(_) => true,
            Value::Array(a) => a.is_empty() || !a.is_list(),
            other => !other.truthy(),
        }
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

super::validator!(ObjectValidator, utopia_validators::Type::Object, false);
