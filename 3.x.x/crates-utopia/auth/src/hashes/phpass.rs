//! `PHPass` password hashing (legacy).

use std::collections::HashMap;
use std::time::{SystemTime, UNIX_EPOCH};

use md5::{Digest, Md5};
use rand::RngCore;
use serde_json::{json, Value};

use crate::error::AuthError;
use crate::hash::{Hash, HashMut, HashOptions};

const ITOA64: &[u8; 64] = b"./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";

/// `PHPass` hasher with support for the portable `$P$`/`$H$` MD5 algorithm.
#[derive(Debug, Clone)]
pub struct PHPass {
    inner: HashOptions,
}

impl Default for PHPass {
    fn default() -> Self {
        Self::new()
    }
}

impl PHPass {
    /// Create a `PHPass` hasher with PHP-compatible defaults.
    #[must_use]
    pub fn new() -> Self {
        let random_state = SystemTime::now().duration_since(UNIX_EPOCH).map_or_else(
            |_| String::new(),
            |duration| duration.as_nanos().to_string(),
        );

        let mut inner = HashOptions::new();
        inner.options_mut().insert("type".into(), json!("phpass"));
        inner
            .options_mut()
            .insert("iteration_count_log2".into(), json!(8));
        inner
            .options_mut()
            .insert("portable_hashes".into(), json!(false));
        inner
            .options_mut()
            .insert("random_state".into(), json!(random_state));
        Self { inner }
    }

    /// Set iteration count log2 between 4 and 31.
    pub fn set_iteration_count(&mut self, count: u32) -> Result<&mut Self, AuthError> {
        if !(4..=31).contains(&count) {
            return Err(AuthError::InvalidInput(
                "Iteration count must be between 4 and 31".into(),
            ));
        }

        self.inner
            .options_mut()
            .insert("iteration_count_log2".into(), json!(count));
        Ok(self)
    }

    /// Set whether generated hashes must use the portable `PHPass` algorithm.
    pub fn set_portable_hashes(&mut self, portable: bool) -> &mut Self {
        self.inner
            .options_mut()
            .insert("portable_hashes".into(), json!(portable));
        self
    }

    fn portable_hashes(&self) -> bool {
        self.inner
            .get_option("portable_hashes")
            .and_then(Value::as_bool)
            .unwrap_or(false)
    }

    fn iteration_count_log2(&self) -> Result<u32, AuthError> {
        self.inner.require_u32("iteration_count_log2")
    }

    fn get_random_bytes(count: usize) -> Result<Vec<u8>, AuthError> {
        if count < 1 {
            return Err(AuthError::InvalidInput(
                "Argument count must be a positive integer".into(),
            ));
        }

        let mut output = vec![0u8; count];
        rand::thread_rng().fill_bytes(&mut output);
        Ok(output)
    }

    fn encode64(input: &[u8], count: usize) -> Result<String, AuthError> {
        if count < 1 {
            return Err(AuthError::InvalidInput(
                "Argument count must be a positive integer".into(),
            ));
        }

        let mut output = String::new();
        let mut i = 0;
        while i < count {
            let mut value = u32::from(input[i]);
            i += 1;
            output.push(ITOA64[(value & 0x3f) as usize] as char);

            if i < count {
                value |= u32::from(input[i]) << 8;
            }
            output.push(ITOA64[((value >> 6) & 0x3f) as usize] as char);

            if i >= count {
                break;
            }
            i += 1;

            if i < count {
                value |= u32::from(input[i]) << 16;
            }
            output.push(ITOA64[((value >> 12) & 0x3f) as usize] as char);

            if i >= count {
                break;
            }
            i += 1;

            output.push(ITOA64[((value >> 18) & 0x3f) as usize] as char);
        }

        Ok(output)
    }

    fn gensalt_private(&self, input: &[u8]) -> Result<String, AuthError> {
        let count = (self.iteration_count_log2()? + 5).min(30);
        Ok(format!(
            "$P${}{}",
            ITOA64[count as usize] as char,
            Self::encode64(input, 6)?
        ))
    }

    fn crypt_private(&self, password: &str, setting: &str) -> Result<String, AuthError> {
        let mut output = "*0".to_owned();
        if setting.starts_with(&output) {
            output.clear();
            output.push_str("*1");
        }

        if !(setting.starts_with("$P$") || setting.starts_with("$H$")) || setting.len() < 12 {
            return Ok(output);
        }

        let setting_bytes = setting.as_bytes();
        let Some(count_log2) = ITOA64.iter().position(|value| *value == setting_bytes[3]) else {
            return Ok(output);
        };
        if !(7..=30).contains(&count_log2) {
            return Ok(output);
        }

        let count = 1u64 << count_log2;
        let salt = &setting_bytes[4..12];
        let mut input = Vec::with_capacity(salt.len() + password.len());
        input.extend_from_slice(salt);
        input.extend_from_slice(password.as_bytes());
        let mut hash = Md5::digest(&input).to_vec();

        for _ in 0..count {
            let mut input = Vec::with_capacity(hash.len() + password.len());
            input.extend_from_slice(&hash);
            input.extend_from_slice(password.as_bytes());
            hash = Md5::digest(&input).to_vec();
        }

        Ok(format!("{}{}", &setting[..12], Self::encode64(&hash, 16)?))
    }
}

impl Hash for PHPass {
    fn hash(&self, value: &str) -> Result<String, AuthError> {
        #[cfg(feature = "bcrypt")]
        if !self.portable_hashes() {
            let cost = self.iteration_count_log2()?;
            if let Ok(hash) = bcrypt::hash(value, cost) {
                if hash.len() == 60 {
                    return Ok(hash);
                }
            }
        }

        let random = Self::get_random_bytes(6)?;
        let hash = self.crypt_private(value, &self.gensalt_private(&random)?)?;
        if hash.len() == 34 {
            return Ok(hash);
        }

        Ok("*".into())
    }

    fn verify(&self, value: &str, hash: &str) -> bool {
        let verification_hash = self
            .crypt_private(value, hash)
            .unwrap_or_else(|_| "*".into());
        let verification_hash = if verification_hash.starts_with('*') {
            #[cfg(feature = "bcrypt")]
            {
                return bcrypt::verify(value, hash).unwrap_or(false);
            }
            #[cfg(not(feature = "bcrypt"))]
            {
                verification_hash
            }
        } else {
            verification_hash
        };

        subtle::ConstantTimeEq::ct_eq(verification_hash.as_bytes(), hash.as_bytes()).into()
    }

    fn name(&self) -> &'static str {
        "phpass"
    }

    fn options(&self) -> &HashMap<String, Value> {
        self.inner.options()
    }
}

impl HashMut for PHPass {
    fn options_mut(&mut self) -> &mut HashMap<String, Value> {
        self.inner.options_mut()
    }
}
