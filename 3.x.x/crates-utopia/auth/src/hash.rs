//! Password hashing trait and shared option handling.

use std::collections::HashMap;

use serde_json::Value;

use crate::error::AuthError;

/// Password hashing algorithm.
pub trait Hash: Send + Sync {
    /// Hash a plaintext value.
    fn hash(&self, value: &str) -> Result<String, AuthError>;

    /// Verify a plaintext value against a stored hash.
    fn verify(&self, value: &str, hash: &str) -> bool;

    /// Algorithm name (e.g. `"argon2"`, `"bcrypt"`).
    fn name(&self) -> &'static str;

    /// Hash-specific configuration options.
    fn options(&self) -> &HashMap<String, Value>;
}

/// Shared option storage for hash implementations.
#[derive(Debug, Clone, Default)]
pub struct HashOptions {
    options: HashMap<String, Value>,
}

impl HashOptions {
    /// Create an empty option map.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Read-only access to stored options.
    #[must_use]
    pub fn options(&self) -> &HashMap<String, Value> {
        &self.options
    }

    /// Mutable access to stored options.
    pub fn options_mut(&mut self) -> &mut HashMap<String, Value> {
        &mut self.options
    }

    /// Set a single option.
    pub fn set_option(&mut self, key: impl Into<String>, value: Value) {
        self.options.insert(key.into(), value);
    }

    /// Set multiple options at once.
    pub fn set_options(&mut self, options: HashMap<String, Value>) {
        for (key, value) in options {
            self.set_option(key, value);
        }
    }

    /// Get a single option, or `None` when unset.
    #[must_use]
    pub fn get_option(&self, key: &str) -> Option<&Value> {
        self.options.get(key)
    }

    /// Read a string option or return an error when missing or not a string.
    pub fn require_string(&self, key: &str) -> Result<&str, AuthError> {
        self.options
            .get(key)
            .and_then(Value::as_str)
            .ok_or_else(|| AuthError::InvalidInput(format!("option '{key}' must be a string")))
    }

    /// Read an integer option or return an error when missing or not an integer.
    pub fn require_u32(&self, key: &str) -> Result<u32, AuthError> {
        self.options
            .get(key)
            .and_then(Value::as_u64)
            .and_then(|v| u32::try_from(v).ok())
            .ok_or_else(|| {
                AuthError::InvalidInput(format!("option '{key}' must be a positive integer"))
            })
    }
}

/// Mutable hash configuration helpers for concrete implementations.
pub trait HashMut: Hash {
    /// Mutable access to the options map.
    fn options_mut(&mut self) -> &mut HashMap<String, Value>;

    /// Set a single option.
    fn set_option(&mut self, key: impl Into<String>, value: Value) {
        self.options_mut().insert(key.into(), value);
    }

    /// Set multiple options at once.
    fn set_options(&mut self, options: HashMap<String, Value>) {
        for (key, value) in options {
            self.set_option(key, value);
        }
    }

    /// Get a single option, or `None` when unset.
    fn get_option(&self, key: &str) -> Option<&Value> {
        self.options().get(key)
    }
}
