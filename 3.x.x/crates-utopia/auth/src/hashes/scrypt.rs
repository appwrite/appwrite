//! Scrypt password hashing (legacy).

use std::collections::HashMap;

use rand::RngCore;
use scrypt::{scrypt, Params};
use serde_json::{json, Value};

use crate::error::AuthError;
use crate::hash::{Hash, HashMut, HashOptions};

/// Scrypt hasher compatible with the PHP `php-scrypt` extension output.
#[derive(Debug, Clone)]
pub struct Scrypt {
    inner: HashOptions,
}

impl Default for Scrypt {
    fn default() -> Self {
        Self::new()
    }
}

impl Scrypt {
    /// Create a scrypt hasher with PHP-compatible defaults.
    #[must_use]
    pub fn new() -> Self {
        let mut salt = [0u8; 16];
        rand::thread_rng().fill_bytes(&mut salt);

        let mut inner = HashOptions::new();
        inner.options_mut().insert("type".into(), json!("scrypt"));
        inner.options_mut().insert("costCpu".into(), json!(8));
        inner.options_mut().insert("costMemory".into(), json!(14));
        inner.options_mut().insert("costParallel".into(), json!(1));
        inner.options_mut().insert("length".into(), json!(64));
        inner
            .options_mut()
            .insert("salt".into(), json!(hex::encode(salt)));
        Self { inner }
    }

    /// Set CPU cost parameter `N`; it must be larger than 1 and a power of 2.
    pub fn set_cpu_cost(&mut self, cost: u32) -> Result<&mut Self, AuthError> {
        if cost <= 1 || !cost.is_power_of_two() {
            return Err(AuthError::InvalidInput(
                "CPU cost must be > 1 and a power of 2".into(),
            ));
        }

        self.inner
            .options_mut()
            .insert("costCpu".into(), json!(cost));
        Ok(self)
    }

    /// Set memory cost parameter `r`.
    pub fn set_memory_cost(&mut self, cost: u32) -> Result<&mut Self, AuthError> {
        if cost < 1 {
            return Err(AuthError::InvalidInput("Memory cost must be >= 1".into()));
        }

        self.inner
            .options_mut()
            .insert("costMemory".into(), json!(cost));
        Ok(self)
    }

    /// Set parallelization parameter `p`.
    pub fn set_parallel_cost(&mut self, cost: u32) -> Result<&mut Self, AuthError> {
        if cost < 1 {
            return Err(AuthError::InvalidInput("Parallel cost must be >= 1".into()));
        }

        self.inner
            .options_mut()
            .insert("costParallel".into(), json!(cost));
        Ok(self)
    }

    /// Set derived output length in bytes.
    pub fn set_length(&mut self, length: u32) -> Result<&mut Self, AuthError> {
        if length < 16 {
            return Err(AuthError::InvalidInput("Length must be >= 16 bytes".into()));
        }

        self.inner
            .options_mut()
            .insert("length".into(), json!(length));
        Ok(self)
    }

    /// Set salt value.
    pub fn set_salt(&mut self, salt: impl Into<String>) -> Result<&mut Self, AuthError> {
        let salt = salt.into();
        if salt.is_empty() || salt == "0" {
            return Err(AuthError::InvalidInput("Salt cannot be empty".into()));
        }

        self.inner.options_mut().insert("salt".into(), json!(salt));
        Ok(self)
    }

    fn params(&self) -> Result<Params, AuthError> {
        let cost_cpu = self.inner.require_u32("costCpu")?;
        if cost_cpu <= 1 || !cost_cpu.is_power_of_two() {
            return Err(AuthError::InvalidInput(
                "CPU cost must be > 1 and a power of 2".into(),
            ));
        }

        let log_n = u8::try_from(cost_cpu.trailing_zeros())
            .map_err(|e| AuthError::HashingFailed(e.to_string()))?;
        let r = self.inner.require_u32("costMemory")?;
        let p = self.inner.require_u32("costParallel")?;
        let length = self.inner.require_u32("length")? as usize;

        Params::new(log_n, r, p, length).map_err(|e| AuthError::HashingFailed(e.to_string()))
    }
}

impl Hash for Scrypt {
    fn hash(&self, value: &str) -> Result<String, AuthError> {
        let salt = self.inner.require_string("salt")?;
        let params = self.params()?;
        let length = self.inner.require_u32("length")? as usize;
        let mut output = vec![0u8; length];

        scrypt(value.as_bytes(), salt.as_bytes(), &params, &mut output)
            .map_err(|e| AuthError::HashingFailed(e.to_string()))?;

        Ok(hex::encode(output))
    }

    fn verify(&self, value: &str, hash: &str) -> bool {
        self.hash(value)
            .map(|computed| {
                subtle::ConstantTimeEq::ct_eq(computed.as_bytes(), hash.as_bytes()).into()
            })
            .unwrap_or(false)
    }

    fn name(&self) -> &'static str {
        "scrypt"
    }

    fn options(&self) -> &HashMap<String, Value> {
        self.inner.options()
    }
}

impl HashMut for Scrypt {
    fn options_mut(&mut self) -> &mut HashMap<String, Value> {
        self.inner.options_mut()
    }
}
