//! [`Vector`]: values of vector attributes.
//!
//! PHP: `Utopia\Database\Validator\Vector`.

use crate::value::Value;

/// A vector: a list (or a JSON string of a list) of exactly `size`
/// integers or floats.
///
/// ```
/// use utopia_database::validator::vector::Vector;
/// use utopia_database::value::{list, string, Value};
///
/// let v = Vector::new(2);
/// assert!(v.is_valid(&list([Value::Float(1.0), Value::Int(2)])));
/// assert!(v.is_valid(&string("[1, 2.5]")));
/// assert!(!v.is_valid(&list([Value::Int(1)])));
/// ```
///
/// PHP: `Utopia\Database\Validator\Vector` (`new Vector($size)`).
#[derive(Clone, Debug)]
pub struct Vector {
    size: i64,
}

impl Vector {
    /// `new Vector($size)`.
    pub fn new(size: i64) -> Self {
        Self { size }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        format!("Value must be an array of {} numeric values", self.size)
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        let decoded;
        let value = match value {
            Value::Str(s) => {
                match php_std::json::decode(
                    s.as_bytes(),
                    Some(true),
                    php_std::json::DEFAULT_DEPTH,
                    php_std::json::Flags::NONE,
                ) {
                    Ok(v @ php_std::Value::Array(_)) => {
                        decoded = crate::value::from_plain(v);
                        &decoded
                    }
                    _ => return false,
                }
            }
            other => other,
        };
        let Value::Array(a) = value else { return false };
        a.is_list() && a.len() as i64 == self.size && a.values().all(|c| matches!(c, Value::Int(_) | Value::Float(_)))
    }

    /// `getType()`: `array`.
    pub fn type_name(&self) -> &'static str {
        "array"
    }
}

super::validator!(Vector, utopia_validators::Type::Array, false);
