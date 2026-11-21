//! [`ByteLength`]: strings of bounded byte length.
//!
//! PHP: `Utopia\Database\Validator\ByteLength`.

use crate::value::Value;

/// A string of at most `max` bytes (0: no limit).
///
/// ```
/// use utopia_database::validator::byte_length::ByteLength;
/// use utopia_database::value::string;
///
/// assert!(ByteLength::new(3).is_valid(&string("abc")));
/// assert!(!ByteLength::new(3).is_valid(&string("héé")));
/// ```
///
/// PHP: `Utopia\Database\Validator\ByteLength` (`new ByteLength($max)`).
#[derive(Clone, Debug)]
pub struct ByteLength {
    max: i64,
}

impl ByteLength {
    /// `new ByteLength($max)`.
    pub fn new(max: i64) -> Self {
        Self { max }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        format!("Value must be a valid string no longer than {} bytes", self.max)
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        let Value::Str(s) = value else { return false };
        self.max == 0 || s.len() as i64 <= self.max
    }

    /// `getType()`: `string`.
    pub fn type_name(&self) -> &'static str {
        "string"
    }
}

super::validator!(ByteLength, utopia_validators::Type::String, false);
