//! [`Key`]: attribute, index and collection keys.
//!
//! PHP: `Utopia\Database\Validator\Key`.

use crate::database::Database;
use crate::value::Value;

/// A key: 1 to `max_length` characters among `a-z`, `A-Z`, `0-9`, `.`,
/// `-` and `_`, not starting with `.`, `-` or `_`. With `allow_internal`,
/// the internal keys `$id`, `$createdAt` and `$updatedAt` are keys too.
///
/// ```
/// use utopia_database::validator::key::Key;
/// use utopia_database::value::string;
///
/// let key = Key::default();
/// assert!(key.is_valid(&string("movie_1")));
/// assert!(!key.is_valid(&string("_movie")));
/// assert!(!key.is_valid(&string("$id")));
/// assert!(Key::new(true, 36).is_valid(&string("$id")));
/// ```
///
/// PHP: `Utopia\Database\Validator\Key` (`new Key($allowInternal, $maxLength)`).
#[derive(Clone, Debug)]
pub struct Key {
    allow_internal: bool,
    max_length: i64,
}

impl Default for Key {
    /// `new Key()`: no internal keys, at most 36 characters.
    fn default() -> Self {
        Self::new(false, Database::MAX_UID_DEFAULT_LENGTH)
    }
}

impl Key {
    /// `new Key($allowInternal, $maxLength)`.
    pub fn new(allow_internal: bool, max_length: i64) -> Self {
        Self { allow_internal, max_length }
    }

    /// The most characters a key may have.
    pub fn max_length(&self) -> i64 {
        self.max_length
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        format!(
            "Parameter must contain at most {} chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char",
            self.max_length
        )
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        let Value::Str(s) = value else { return false };
        self.accepts(s.as_bytes())
    }

    /// [`Key::is_valid`] on a string.
    pub fn accepts(&self, s: &[u8]) -> bool {
        let Some(&leading) = s.first() else { return false };
        if matches!(leading, b'_' | b'.' | b'-') {
            return false;
        }
        if leading == b'$' {
            return self.allow_internal && matches!(s, b"$id" | b"$createdAt" | b"$updatedAt");
        }
        if !s.iter().all(|c| c.is_ascii_alphanumeric() || matches!(c, b'_' | b'-' | b'.')) {
            return false;
        }
        // Only ASCII is left, so `mb_strlen` is the byte length.
        s.len() as i64 <= self.max_length
    }

    /// `getType()`: `string`.
    pub fn type_name(&self) -> &'static str {
        "string"
    }
}

super::validator!(Key, utopia_validators::Type::String, false);
