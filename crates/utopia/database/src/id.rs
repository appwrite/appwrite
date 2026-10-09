//! Identifiers: `ID::unique()` and the `Key` / `UID` validators.

use std::time::{SystemTime, UNIX_EPOCH};

use serde_json::Value;
use utopia_validators::Validator;

/// `ID::unique()`: PHP `uniqid()` (8 hex seconds + 5 hex microseconds)
/// followed by 7 random hex characters, 20 characters in total.
pub fn unique() -> String {
    let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
    let mut bytes = [0u8; 4];
    getrandom::fill(&mut bytes).expect("system random source unavailable");
    let mut random = hex::encode(bytes);
    random.truncate(7);
    format!("{:08x}{:05x}{}", now.as_secs(), now.subsec_micros(), random)
}

/// `Utopia\Database\Validator\Key`.
#[derive(Debug, Clone, Copy)]
pub struct Key {
    pub max_length: usize,
    pub allow_internal: bool,
}

impl Key {
    pub const fn new(max_length: usize) -> Self {
        Self { max_length, allow_internal: false }
    }

    pub fn matches(&self, value: &str) -> bool {
        let Some(first) = value.chars().next() else {
            return false;
        };
        if first == '_' || first == '.' || first == '-' {
            return false;
        }
        if first == '$' {
            return self.allow_internal && matches!(value, "$id" | "$createdAt" | "$updatedAt");
        }
        if !value.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_' || b == b'-' || b == b'.') {
            return false;
        }
        value.chars().count() <= self.max_length
    }
}

impl Validator for Key {
    fn description(&self) -> String {
        format!(
            "Parameter must contain at most {} chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char",
            self.max_length
        )
    }

    fn is_valid(&self, value: &Value) -> bool {
        matches!(value, Value::String(s) if self.matches(s))
    }
}

/// `Utopia\Database\Validator\UID` (a `Key` with its own description).
#[derive(Debug, Clone, Copy)]
pub struct Uid {
    pub max_length: usize,
}

impl Uid {
    pub const fn new(max_length: usize) -> Self {
        Self { max_length }
    }

    pub fn matches(&self, value: &str) -> bool {
        Key::new(self.max_length).matches(value)
    }
}

impl Default for Uid {
    fn default() -> Self {
        Self { max_length: 36 }
    }
}

impl Validator for Uid {
    fn description(&self) -> String {
        format!(
            "UID must contain at most {} chars. Valid chars are a-z, A-Z, 0-9, and underscore. Can't start with a leading underscore",
            self.max_length
        )
    }

    fn is_valid(&self, value: &Value) -> bool {
        matches!(value, Value::String(s) if self.matches(s))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unique_ids() {
        let id = unique();
        assert_eq!(id.len(), 20);
        assert!(id.bytes().all(|b| b.is_ascii_hexdigit()));
        assert_ne!(unique(), unique());
    }

    #[test]
    fn keys() {
        let k = Key::new(36);
        assert!(k.matches("user1"));
        assert!(k.matches("a.b-c_d"));
        assert!(!k.matches("_user"));
        assert!(!k.matches("-user"));
        assert!(!k.matches("$id"));
        assert!(!k.matches("a b"));
        assert!(!k.matches(&"a".repeat(37)));
        assert!(!k.matches(""));
    }
}
