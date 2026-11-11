//! [`Label`]: user labels.
//!
//! PHP: `Utopia\Database\Validator\Label`.

use crate::database::Database;
use crate::validator::key::Key;
use crate::value::Value;

/// A label: a [`Key`] made only of `a-z`, `A-Z` and `0-9`.
///
/// ```
/// use utopia_database::validator::label::Label;
/// use utopia_database::value::string;
///
/// assert!(Label::default().is_valid(&string("vip2")));
/// assert!(!Label::default().is_valid(&string("v.i.p")));
/// ```
///
/// PHP: `Utopia\Database\Validator\Label` (`new Label($allowInternal, $maxLength)`).
#[derive(Clone, Debug)]
pub struct Label {
    key: Key,
}

impl Default for Label {
    /// `new Label()`: at most 36 characters.
    fn default() -> Self {
        Self::new(false, Database::MAX_UID_DEFAULT_LENGTH)
    }
}

impl Label {
    /// `new Label($allowInternal, $maxLength)`.
    pub fn new(allow_internal: bool, max_length: i64) -> Self {
        Self { key: Key::new(allow_internal, max_length) }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        format!(
            "Value must be a valid string between 1 and {} chars containing only alphanumeric chars",
            self.key.max_length()
        )
    }

    /// `isValid($value)`: a key, then only alphanumeric characters (so an
    /// allowed internal key such as `$id` is still refused).
    pub fn is_valid(&self, value: &Value) -> bool {
        let Value::Str(s) = value else { return false };
        self.key.accepts(s.as_bytes()) && s.as_bytes().iter().all(u8::is_ascii_alphanumeric)
    }

    /// `getType()`: `string`.
    pub fn type_name(&self) -> &'static str {
        "string"
    }
}

super::validator!(Label, utopia_validators::Type::String, false);
