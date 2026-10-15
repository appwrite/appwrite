//! [`ObjectPath`]: paths into object attributes (`meta.address.city`).
//!
//! PHP: `Utopia\Database\Validator\ObjectPath`.

use crate::value::Value;

/// A path of dot-separated keys, each non-empty and made of `a-z`, `A-Z`,
/// `0-9`, `_` and `-`.
///
/// ```
/// use utopia_database::validator::object_path::ObjectPath;
/// use utopia_database::value::string;
///
/// assert!(ObjectPath.is_valid(&string("meta.address.city")));
/// assert!(!ObjectPath.is_valid(&string("meta..city")));
/// assert!(!ObjectPath.is_valid(&string("meta.$city")));
/// ```
///
/// PHP: `Utopia\Database\Validator\ObjectPath` (`KEY_PATTERN`
/// `/^[a-zA-Z0-9_\-]+$/D` per key).
#[derive(Clone, Copy, Debug, Default)]
pub struct ObjectPath;

impl ObjectPath {
    /// `getDescription()`.
    pub fn description(&self) -> String {
        "Object path keys must be non-empty and contain only a-z, A-Z, 0-9, underscore and hyphen".to_owned()
    }

    /// `isValid($value)`.
    pub fn is_valid(&self, value: &Value) -> bool {
        let Value::Str(s) = value else { return false };
        Self::accepts(s.as_bytes())
    }

    /// [`ObjectPath::is_valid`] on a path.
    pub fn accepts(path: &[u8]) -> bool {
        path.split(|b| *b == b'.').all(Self::is_key)
    }

    /// Whether one key of a path is valid (`KEY_PATTERN`).
    pub fn is_key(key: &[u8]) -> bool {
        !key.is_empty() && key.iter().all(|c| c.is_ascii_alphanumeric() || *c == b'_' || *c == b'-')
    }

    /// `getType()`: `string`.
    pub fn type_name(&self) -> &'static str {
        "string"
    }
}

super::validator!(ObjectPath, utopia_validators::Type::String, false, constant);
