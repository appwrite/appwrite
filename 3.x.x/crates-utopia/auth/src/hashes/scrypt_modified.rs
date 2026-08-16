//! Modified scrypt hasher used by legacy Appwrite password hashes.

use std::collections::HashMap;

use aes::Aes256;
use base64::engine::general_purpose::STANDARD;
use base64::Engine;
use ctr::cipher::{KeyIvInit, StreamCipher};
use rand::RngCore;
use scrypt::{scrypt, Params};
use serde_json::{json, Value};

use crate::error::AuthError;
use crate::hash::{Hash, HashMut, HashOptions};

type Aes256Ctr = ctr::Ctr128BE<Aes256>;

/// Appwrite's modified scrypt hasher (`scryptMod`).
#[derive(Debug, Clone)]
pub struct ScryptModified {
    inner: HashOptions,
}

impl Default for ScryptModified {
    fn default() -> Self {
        Self::new()
    }
}

impl ScryptModified {
    /// Create a modified scrypt hasher with random PHP-compatible options.
    #[must_use]
    pub fn new() -> Self {
        let mut salt = [0u8; 16];
        let mut salt_separator = [0u8; 16];
        let mut signer_key = [0u8; 32];
        let mut rng = rand::thread_rng();
        rng.fill_bytes(&mut salt);
        rng.fill_bytes(&mut salt_separator);
        rng.fill_bytes(&mut signer_key);

        let mut inner = HashOptions::new();
        inner
            .options_mut()
            .insert("type".into(), json!("scryptMod"));
        inner
            .options_mut()
            .insert("salt".into(), json!(STANDARD.encode(salt)));
        inner.options_mut().insert(
            "saltSeparator".into(),
            json!(STANDARD.encode(salt_separator)),
        );
        inner
            .options_mut()
            .insert("signerKey".into(), json!(STANDARD.encode(signer_key)));
        Self { inner }
    }

    /// Set base64-encoded salt.
    pub fn set_salt(&mut self, salt: impl Into<String>) -> Result<&mut Self, AuthError> {
        let salt = salt.into();
        if salt.is_empty() || salt == "0" {
            return Err(AuthError::InvalidInput("Salt cannot be empty".into()));
        }
        validate_base64(&salt, "Salt")?;
        self.inner.options_mut().insert("salt".into(), json!(salt));
        Ok(self)
    }

    /// Set base64-encoded salt separator.
    pub fn set_salt_separator(
        &mut self,
        separator: impl Into<String>,
    ) -> Result<&mut Self, AuthError> {
        let separator = separator.into();
        validate_base64(&separator, "Salt separator")?;
        self.inner
            .options_mut()
            .insert("saltSeparator".into(), json!(separator));
        Ok(self)
    }

    /// Set base64-encoded signer key.
    pub fn set_signer_key(&mut self, key: impl Into<String>) -> Result<&mut Self, AuthError> {
        let key = key.into();
        if key.is_empty() || key == "0" {
            return Err(AuthError::InvalidInput("Signer key cannot be empty".into()));
        }
        validate_base64(&key, "Signer key")?;
        self.inner
            .options_mut()
            .insert("signerKey".into(), json!(key));
        Ok(self)
    }

    fn generate_derived_key(&self, value: &str) -> Result<[u8; 64], AuthError> {
        let salt = STANDARD
            .decode(self.inner.require_string("salt")?)
            .map_err(|e| AuthError::InvalidInput(e.to_string()))?;
        let separator = STANDARD
            .decode(self.inner.require_string("saltSeparator")?)
            .map_err(|e| AuthError::InvalidInput(e.to_string()))?;

        let mut combined_salt = salt;
        combined_salt.extend(separator);

        let params =
            Params::new(14, 8, 1, 64).map_err(|e| AuthError::HashingFailed(e.to_string()))?;
        let mut output = [0u8; 64];
        scrypt(value.as_bytes(), &combined_salt, &params, &mut output)
            .map_err(|e| AuthError::HashingFailed(e.to_string()))?;
        Ok(output)
    }
}

impl Hash for ScryptModified {
    fn hash(&self, value: &str) -> Result<String, AuthError> {
        let signer_key = STANDARD
            .decode(self.inner.require_string("signerKey")?)
            .map_err(|e| AuthError::InvalidInput(e.to_string()))?;
        let derived_key = self.generate_derived_key(value)?;

        let key = &derived_key[..32];
        let iv = [0u8; 16];
        let mut result = signer_key;
        let mut cipher = Aes256Ctr::new_from_slices(key, &iv)
            .map_err(|e| AuthError::HashingFailed(e.to_string()))?;
        cipher.apply_keystream(&mut result);

        Ok(STANDARD.encode(result))
    }

    fn verify(&self, value: &str, hash: &str) -> bool {
        self.hash(value)
            .map(|computed| {
                subtle::ConstantTimeEq::ct_eq(computed.as_bytes(), hash.as_bytes()).into()
            })
            .unwrap_or(false)
    }

    fn name(&self) -> &'static str {
        "scryptMod"
    }

    fn options(&self) -> &HashMap<String, Value> {
        self.inner.options()
    }
}

impl HashMut for ScryptModified {
    fn options_mut(&mut self) -> &mut HashMap<String, Value> {
        self.inner.options_mut()
    }
}

fn validate_base64(value: &str, label: &str) -> Result<(), AuthError> {
    STANDARD
        .decode(value)
        .map(|_| ())
        .map_err(|_| AuthError::InvalidInput(format!("{label} must be base64 encoded")))
}
