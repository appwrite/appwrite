//! Bcrypt password hashing.

use std::collections::HashMap;

use bcrypt::{hash, verify};
use serde_json::{json, Value};

use crate::error::AuthError;
use crate::hash::{Hash, HashMut, HashOptions};

/// Bcrypt password hasher.
#[derive(Debug, Clone)]
pub struct Bcrypt {
    inner: HashOptions,
}

impl Default for Bcrypt {
    fn default() -> Self {
        Self::new()
    }
}

impl Bcrypt {
    /// Create a bcrypt hasher with PHP-compatible defaults (`cost = 8`).
    #[must_use]
    pub fn new() -> Self {
        let mut inner = HashOptions::new();
        inner.options_mut().insert("type".into(), json!("bcrypt"));
        inner.options_mut().insert("cost".into(), json!(8));
        Self { inner }
    }

    /// Set the bcrypt cost parameter (4–31).
    pub fn set_cost(&mut self, cost: u32) -> Result<&mut Self, AuthError> {
        if !(4..=31).contains(&cost) {
            return Err(AuthError::InvalidInput(
                "cost must be between 4 and 31".into(),
            ));
        }
        self.inner.options_mut().insert("cost".into(), json!(cost));
        Ok(self)
    }

    fn cost(&self) -> Result<u32, AuthError> {
        self.inner.require_u32("cost")
    }
}

impl Hash for Bcrypt {
    fn hash(&self, value: &str) -> Result<String, AuthError> {
        let cost = self.cost()?;
        hash(value, cost).map_err(|e| AuthError::HashingFailed(e.to_string()))
    }

    fn verify(&self, value: &str, hash: &str) -> bool {
        verify(value, hash).unwrap_or(false)
    }

    fn name(&self) -> &'static str {
        "bcrypt"
    }

    fn options(&self) -> &HashMap<String, Value> {
        self.inner.options()
    }
}

impl HashMut for Bcrypt {
    fn options_mut(&mut self) -> &mut HashMap<String, Value> {
        self.inner.options_mut()
    }
}
