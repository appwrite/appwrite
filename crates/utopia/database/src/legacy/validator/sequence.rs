//! [`Sequence`]: document sequence values (`$sequence` and `id` attributes).
//!
//! PHP: `Utopia\Database\Validator\Sequence`.

use php_std::value::Number;
use utopia_validators::{Range, Type};

use crate::database::Database;
use crate::validator::accepts;
use crate::value::Value;

/// A sequence value: for the primary sequence (`$sequence`), a positive
/// integer (integer id type) or a UUIDv7 string (`uuid7` id type); for
/// other `id` attributes, any string or integer. `null` is valid unless
/// primary.
///
/// ```
/// use utopia_database::validator::sequence::Sequence;
/// use utopia_database::value::{Value, string};
///
/// let primary = Sequence::new("integer", true);
/// assert!(primary.is_valid(&Value::Int(1)));
/// assert!(!primary.is_valid(&Value::Int(0)));
/// assert!(Sequence::new("uuid7", true).is_valid(&string("0190d3f8-7e7c-7c8a-9a3e-123456789abc")));
/// ```
///
/// PHP: `Utopia\Database\Validator\Sequence` (`new Sequence($idAttributeType, $primary)`).
#[derive(Clone, Debug)]
pub struct Sequence {
    id_attribute_type: String,
    primary: bool,
}

impl Sequence {
    /// `new Sequence($idAttributeType, $primary)`.
    pub fn new(id_attribute_type: impl Into<String>, primary: bool) -> Self {
        Self { id_attribute_type: id_attribute_type.into(), primary }
    }

    /// `getDescription()`: `Invalid sequence value`.
    pub fn description(&self) -> String {
        "Invalid sequence value".to_owned()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        if self.primary && !value.truthy() {
            return false;
        }
        if matches!(value, Value::Null) {
            return true;
        }
        if !matches!(value, Value::Str(_) | Value::Int(_)) {
            return false;
        }
        if !self.primary {
            return true;
        }
        match self.id_attribute_type.as_str() {
            Database::VAR_UUID7 => matches!(value, Value::Str(s) if is_uuid7(s.as_bytes())),
            Database::VAR_INTEGER => {
                accepts(&Range::with_format(Number::Int(1), Number::Int(Database::MAX_BIG_INT), Type::Integer), value)
            }
            _ => false,
        }
    }

    /// `getType()`: `string`.
    pub fn type_name(&self) -> &'static str {
        "string"
    }
}

/// `/^[a-f0-9]{8}-[a-f0-9]{4}-7[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i`
/// (the `$` also matches before a final newline).
fn is_uuid7(s: &[u8]) -> bool {
    let s = s.strip_suffix(b"\n").unwrap_or(s);
    s.len() == 36
        && s.iter().enumerate().all(|(i, c)| match i {
            8 | 13 | 18 | 23 => *c == b'-',
            14 => *c == b'7',
            19 => matches!(c.to_ascii_lowercase(), b'8' | b'9' | b'a' | b'b'),
            _ => c.is_ascii_hexdigit(),
        })
}

super::validator!(Sequence, utopia_validators::Type::String, false);
