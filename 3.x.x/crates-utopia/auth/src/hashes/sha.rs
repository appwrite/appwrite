//! SHA digest hashing (legacy).

use std::collections::HashMap;

use serde_json::{json, Value};
use sha1::Sha1;
use sha2::{Digest, Sha224, Sha256, Sha384, Sha512};

use crate::error::AuthError;
use crate::hash::{Hash, HashMut, HashOptions};

const VALID_VERSIONS: &[&str] = &["sha1", "sha224", "sha256", "sha384", "sha512"];

/// SHA family digest hasher (legacy; not suitable for password storage).
#[derive(Debug, Clone)]
pub struct Sha {
    inner: HashOptions,
}

impl Default for Sha {
    fn default() -> Self {
        Self::new()
    }
}

impl Sha {
    /// Create a SHA hasher defaulting to SHA-256.
    #[must_use]
    pub fn new() -> Self {
        let mut inner = HashOptions::new();
        inner
            .options_mut()
            .insert("version".into(), json!("sha256"));
        Self { inner }
    }

    /// Select the SHA algorithm version.
    pub fn set_version(&mut self, version: impl Into<String>) -> Result<&mut Self, AuthError> {
        let version = version.into();
        if !VALID_VERSIONS.contains(&version.as_str()) {
            return Err(AuthError::InvalidInput(format!(
                "invalid SHA version; valid versions are: {}",
                VALID_VERSIONS.join(", ")
            )));
        }
        self.inner
            .options_mut()
            .insert("version".into(), json!(version));
        Ok(self)
    }

    fn digest_hex(&self, value: &str) -> Result<String, AuthError> {
        let version = self.inner.require_string("version")?;
        let bytes = value.as_bytes();
        let hex = match version {
            "sha1" => {
                let mut hasher = Sha1::new();
                hasher.update(bytes);
                format!("{:x}", hasher.finalize())
            }
            "sha224" => {
                let mut hasher = Sha224::new();
                hasher.update(bytes);
                format!("{:x}", hasher.finalize())
            }
            "sha256" => {
                let mut hasher = Sha256::new();
                hasher.update(bytes);
                format!("{:x}", hasher.finalize())
            }
            "sha384" => {
                let mut hasher = Sha384::new();
                hasher.update(bytes);
                format!("{:x}", hasher.finalize())
            }
            "sha512" => {
                let mut hasher = Sha512::new();
                hasher.update(bytes);
                format!("{:x}", hasher.finalize())
            }
            other => {
                return Err(AuthError::InvalidInput(format!(
                    "unsupported SHA version: {other}"
                )));
            }
        };
        Ok(hex)
    }
}

impl Hash for Sha {
    fn hash(&self, value: &str) -> Result<String, AuthError> {
        self.digest_hex(value)
    }

    fn verify(&self, value: &str, hash: &str) -> bool {
        self.digest_hex(value)
            .map(|computed| {
                subtle::ConstantTimeEq::ct_eq(computed.as_bytes(), hash.as_bytes()).into()
            })
            .unwrap_or(false)
    }

    fn name(&self) -> &'static str {
        "sha"
    }

    fn options(&self) -> &HashMap<String, Value> {
        self.inner.options()
    }
}

impl HashMut for Sha {
    fn options_mut(&mut self) -> &mut HashMap<String, Value> {
        self.inner.options_mut()
    }
}
