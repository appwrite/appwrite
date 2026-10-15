//! [`Uid`]: document identifiers.
//!
//! PHP: `Utopia\Database\Validator\UID`.

use crate::database::Database;
use crate::validator::key::Key;
use crate::value::Value;

/// A document identifier: a [`Key`] without internal keys, with its own
/// description.
///
/// ```
/// use utopia_database::validator::uid::Uid;
/// use utopia_database::value::string;
///
/// assert!(Uid::default().is_valid(&string("doc-1")));
/// assert!(!Uid::new(3).is_valid(&string("doc-1")));
/// ```
///
/// PHP: `Utopia\Database\Validator\UID` (`new UID($maxLength)`).
#[derive(Clone, Debug)]
pub struct Uid {
    key: Key,
}

impl Default for Uid {
    /// `new UID()`: at most 36 characters.
    fn default() -> Self {
        Self::new(Database::MAX_UID_DEFAULT_LENGTH)
    }
}

impl Uid {
    /// `new UID($maxLength)`.
    pub fn new(max_length: i64) -> Self {
        Self { key: Key::new(false, max_length) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        format!(
            "UID must contain at most {} chars. Valid chars are a-z, A-Z, 0-9, and underscore. Can't start with a leading underscore",
            self.key.max_length()
        )
    }

    /// `isValid($value)` (`Key::isValid`).
    pub fn is_valid(&self, value: &Value) -> bool {
        self.key.is_valid(value)
    }

    /// `getType()`: `string`.
    pub fn type_name(&self) -> &'static str {
        "string"
    }
}

super::validator!(Uid, utopia_validators::Type::String, false);
