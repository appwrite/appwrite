//! Custom ID validator and resolution. Rust port of
//! `Appwrite\Utopia\Database\Validator\CustomId`
//! (`src/Appwrite/Utopia/Database/Validator/CustomId.php`) plus the
//! `$userId == 'unique()' ? ID::unique() : ID::custom($userId)` resolution
//! pattern used throughout the Users API (`src/Appwrite/Platform/Modules/Users/Base.php`
//! and friends).

use serde_json::Value;
use utopia_database::{validator::Key, Id};
use utopia_validators::{Validator, ValueType};

/// PHP literal `'unique()'` sentinel accepted by [`CustomId`] and resolved
/// by [`resolve_id`] into a generated ID.
pub const UNIQUE_SENTINEL: &str = "unique()";

/// Rust port of `Appwrite\Utopia\Database\Validator\CustomId extends
/// Utopia\Database\Validator\Key`: accepts the literal `"unique()"` sentinel
/// in addition to every key-like ID `Key` already accepts (a-z, A-Z, 0-9,
/// `.`, `-`, `_`, max length, no leading special char).
#[derive(Debug, Clone)]
pub struct CustomId {
    inner: Key,
}

impl CustomId {
    /// PHP `new CustomId(bool $allowInternal = false, int $length = 36)`.
    #[must_use]
    pub fn new(allow_internal: bool, max_length: i64) -> Self {
        Self {
            inner: Key::new(allow_internal, max_length),
        }
    }

    /// PHP `new CustomId()` defaults (`allowInternal: false`,
    /// `length: Database::LENGTH_KEY` = 36).
    #[must_use]
    pub fn max_length(&self) -> i64 {
        self.inner.max_length()
    }
}

impl Default for CustomId {
    fn default() -> Self {
        Self::new(false, 36)
    }
}

impl Validator for CustomId {
    fn description(&self) -> String {
        self.inner.description()
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    /// PHP `CustomId::isValid($value)`: `$value == 'unique()' ||
    /// parent::isValid($value)`.
    fn is_valid(&self, value: &Value) -> bool {
        if value.as_str() == Some(UNIQUE_SENTINEL) {
            return true;
        }
        self.inner.is_valid(value)
    }
}

/// Rust port of the `$id == 'unique()' ? ID::unique() : ID::custom($id)`
/// pattern used across the Users API (user/target/session/token IDs) to
/// turn a [`CustomId`]-validated value into the ID actually stored.
pub fn resolve_id(id: &str) -> String {
    if id == UNIQUE_SENTINEL {
        Id::unique().unwrap_or_else(|_| Id::custom(id))
    } else {
        Id::custom(id)
    }
}
