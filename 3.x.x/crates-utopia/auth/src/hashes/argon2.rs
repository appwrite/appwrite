//! Argon2id password hashing.

use std::collections::HashMap;

use argon2::{
    password_hash::{rand_core::OsRng, SaltString},
    Algorithm, Argon2 as Argon2Hasher, Params, PasswordHash, PasswordHasher, PasswordVerifier,
    Version,
};
use serde_json::{json, Value};

use crate::error::AuthError;
use crate::hash::{Hash, HashMut, HashOptions};

/// Argon2id password hasher.
#[derive(Debug, Clone)]
pub struct Argon2 {
    inner: HashOptions,
}

impl Default for Argon2 {
    fn default() -> Self {
        Self::new()
    }
}

impl Argon2 {
    /// Create an Argon2 hasher with PHP-compatible defaults.
    #[must_use]
    pub fn new() -> Self {
        let mut inner = HashOptions::new();
        inner.options_mut().insert("type".into(), json!("argon2"));
        inner
            .options_mut()
            .insert("memory_cost".into(), json!(65_536));
        inner.options_mut().insert("time_cost".into(), json!(4));
        inner.options_mut().insert("threads".into(), json!(3));
        Self { inner }
    }

    /// Set memory cost in KiB.
    pub fn set_memory_cost(&mut self, cost: u32) -> &mut Self {
        self.inner
            .options_mut()
            .insert("memory_cost".into(), json!(cost));
        self
    }

    /// Set time cost (iterations).
    pub fn set_time_cost(&mut self, cost: u32) -> &mut Self {
        self.inner
            .options_mut()
            .insert("time_cost".into(), json!(cost));
        self
    }

    /// Set parallelism (threads).
    pub fn set_threads(&mut self, threads: u32) -> &mut Self {
        self.inner
            .options_mut()
            .insert("threads".into(), json!(threads));
        self
    }

    fn hasher(&self) -> Result<Argon2Hasher<'static>, AuthError> {
        let memory_cost = self.inner.require_u32("memory_cost")?;
        let time_cost = self.inner.require_u32("time_cost")?;
        let threads = self.inner.require_u32("threads")?;

        let params = Params::new(memory_cost, time_cost, threads, None)
            .map_err(|e| AuthError::HashingFailed(e.to_string()))?;

        Ok(Argon2Hasher::new(
            Algorithm::Argon2id,
            Version::V0x13,
            params,
        ))
    }
}

impl Hash for Argon2 {
    fn hash(&self, value: &str) -> Result<String, AuthError> {
        let salt = SaltString::generate(&mut OsRng);
        self.hasher()?
            .hash_password(value.as_bytes(), &salt)
            .map(|hash| hash.to_string())
            .map_err(|e| AuthError::HashingFailed(e.to_string()))
    }

    fn verify(&self, value: &str, hash: &str) -> bool {
        PasswordHash::new(hash)
            .ok()
            .and_then(|parsed| {
                self.hasher()
                    .ok()?
                    .verify_password(value.as_bytes(), &parsed)
                    .ok()
            })
            .is_some()
    }

    fn name(&self) -> &'static str {
        "argon2"
    }

    fn options(&self) -> &HashMap<String, Value> {
        self.inner.options()
    }
}

impl HashMut for Argon2 {
    fn options_mut(&mut self) -> &mut HashMap<String, Value> {
        self.inner.options_mut()
    }
}
