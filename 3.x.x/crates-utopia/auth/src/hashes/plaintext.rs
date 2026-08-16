//! Plaintext "hashing" (legacy; for testing only).

use std::collections::HashMap;

use serde_json::{json, Value};

use crate::error::AuthError;
use crate::hash::{Hash, HashMut, HashOptions};

/// Plaintext pass-through hasher (legacy; never use in production).
#[derive(Debug, Clone)]
pub struct Plaintext {
    inner: HashOptions,
}

impl Default for Plaintext {
    fn default() -> Self {
        Self::new()
    }
}

impl Plaintext {
    /// Create a plaintext hasher.
    #[must_use]
    pub fn new() -> Self {
        let mut inner = HashOptions::new();
        inner
            .options_mut()
            .insert("type".into(), json!("plaintext"));
        Self { inner }
    }
}

impl Hash for Plaintext {
    fn hash(&self, value: &str) -> Result<String, AuthError> {
        Ok(value.to_owned())
    }

    fn verify(&self, value: &str, hash: &str) -> bool {
        subtle::ConstantTimeEq::ct_eq(value.as_bytes(), hash.as_bytes()).into()
    }

    fn name(&self) -> &'static str {
        "plaintext"
    }

    fn options(&self) -> &HashMap<String, Value> {
        self.inner.options()
    }
}

impl HashMut for Plaintext {
    fn options_mut(&mut self) -> &mut HashMap<String, Value> {
        self.inner.options_mut()
    }
}
