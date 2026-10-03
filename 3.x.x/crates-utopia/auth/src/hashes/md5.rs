//! MD5 digest hashing (legacy).

use std::collections::HashMap;

use md5::{Digest, Md5 as Md5Hasher};
use serde_json::{json, Value};

use crate::error::AuthError;
use crate::hash::{Hash, HashMut, HashOptions};

/// MD5 digest hasher (legacy; not suitable for password storage).
#[derive(Debug, Clone)]
pub struct Md5 {
    inner: HashOptions,
}

impl Default for Md5 {
    fn default() -> Self {
        Self::new()
    }
}

impl Md5 {
    /// Create an MD5 hasher.
    #[must_use]
    pub fn new() -> Self {
        let mut inner = HashOptions::new();
        inner.options_mut().insert("type".into(), json!("md5"));
        Self { inner }
    }
}

impl Hash for Md5 {
    fn hash(&self, value: &str) -> Result<String, AuthError> {
        let mut hasher = Md5Hasher::new();
        hasher.update(value.as_bytes());
        Ok(format!("{:x}", hasher.finalize()))
    }

    fn verify(&self, value: &str, hash: &str) -> bool {
        self.hash(value)
            .map(|computed| {
                subtle::ConstantTimeEq::ct_eq(computed.as_bytes(), hash.as_bytes()).into()
            })
            .unwrap_or(false)
    }

    fn name(&self) -> &'static str {
        "md5"
    }

    fn options(&self) -> &HashMap<String, Value> {
        self.inner.options()
    }
}

impl HashMut for Md5 {
    fn options_mut(&mut self) -> &mut HashMap<String, Value> {
        self.inner.options_mut()
    }
}
