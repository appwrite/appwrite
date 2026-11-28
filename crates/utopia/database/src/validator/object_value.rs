//! [`ObjectValue`]: values of object attributes.
//!
//! PHP: `Utopia\Database\Validator\ObjectValue`.

use crate::value::Value;

/// An object value: a `stdClass`, an associative (non-list) or empty array,
/// or a JSON string decoding to one of those.
///
/// ```
/// use utopia_database::validator::object_value::ObjectValue;
/// use utopia_database::value::{list, string, Value};
///
/// assert!(ObjectValue.is_valid(&string("{\"a\":1}")));
/// assert!(!ObjectValue.is_valid(&string("1")));
/// assert!(!ObjectValue.is_valid(&list([Value::Int(1)])));
/// assert!(!ObjectValue.is_valid(&Value::Null));
/// ```
///
/// PHP: `Utopia\Database\Validator\ObjectValue` (7.x's `ObjectValidator`).
#[derive(Clone, Copy, Debug, Default)]
pub struct ObjectValue;

impl ObjectValue {
    /// `getDescription()`.
    pub fn description(&self) -> String {
        "Value must be a valid object".to_owned()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        let decoded;
        let value = match value {
            Value::Str(s) => {
                match php_std::json::decode(
                    s.as_bytes(),
                    Some(false),
                    php_std::json::DEFAULT_DEPTH,
                    php_std::json::Flags::NONE,
                ) {
                    Ok(v) => {
                        decoded = crate::value::from_plain(v);
                        &decoded
                    }
                    Err(_) => return false,
                }
            }
            other => other,
        };
        match value {
            Value::Object(_) => true,
            Value::Array(a) => a.is_empty() || !a.is_list(),
            _ => false,
        }
    }

    /// `getType()`: `object`.
    pub fn type_name(&self) -> &'static str {
        "object"
    }
}

super::validator!(ObjectValue, utopia_validators::Type::Object, false);
