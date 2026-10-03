//! Random password proof generation.

use std::collections::HashMap;
use std::sync::Arc;

use rand::Rng;
use serde_json::Value;

use crate::error::AuthError;
use crate::hash::{Hash, HashMut};
use crate::proof::{Proof, ProofBase};

#[cfg(feature = "argon2")]
use crate::hashes::Argon2;
#[cfg(feature = "bcrypt")]
use crate::hashes::Bcrypt;
#[cfg(feature = "legacy")]
use crate::hashes::{Md5, PHPass, Scrypt, ScryptModified, Sha};

const DEFAULT_CHARSET: &str =
    "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?";

/// Random password proof generator.
#[derive(Clone)]
pub struct Password {
    base: ProofBase,
    hashes: HashMap<String, Arc<dyn Hash>>,
    active_hash: String,
    default_length: usize,
    default_charset: String,
}

impl std::fmt::Debug for Password {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Password")
            .field("base", &self.base)
            .field("hashes", &self.hashes.keys().collect::<Vec<_>>())
            .field("active_hash", &self.active_hash)
            .field("default_length", &self.default_length)
            .field("default_charset", &self.default_charset)
            .finish()
    }
}

impl Default for Password {
    fn default() -> Self {
        Self::new()
    }
}

impl Password {
    /// Argon2 hash type.
    pub const ARGON2: &'static str = "argon2";
    /// Bcrypt hash type.
    pub const BCRYPT: &'static str = "bcrypt";
    /// Scrypt hash type.
    pub const SCRYPT: &'static str = "scrypt";
    /// Modified scrypt hash type.
    pub const SCRYPT_MODIFIED: &'static str = "scryptMod";
    /// SHA hash type.
    pub const SHA: &'static str = "sha";
    /// MD5 hash type.
    pub const MD5: &'static str = "md5";
    /// `PHPass` hash type.
    pub const PHPASS: &'static str = "phpass";

    /// Create a password proof with the default hash registry.
    #[must_use]
    pub fn new() -> Self {
        let mut hashes: HashMap<String, Arc<dyn Hash>> = HashMap::new();

        #[cfg(feature = "argon2")]
        hashes.insert(Self::ARGON2.into(), Arc::new(Argon2::new()));
        #[cfg(feature = "bcrypt")]
        hashes.insert(Self::BCRYPT.into(), Arc::new(Bcrypt::new()));
        #[cfg(feature = "legacy")]
        {
            hashes.insert(Self::SCRYPT.into(), Arc::new(Scrypt::new()));
            hashes.insert(
                Self::SCRYPT_MODIFIED.into(),
                Arc::new(ScryptModified::new()),
            );
            hashes.insert(Self::SHA.into(), Arc::new(Sha::new()));
            hashes.insert(Self::MD5.into(), Arc::new(Md5::new()));
            hashes.insert(Self::PHPASS.into(), Arc::new(PHPass::new()));
        }

        let active_name = hashes
            .contains_key(Self::ARGON2)
            .then_some(Self::ARGON2)
            .or_else(|| hashes.keys().next().map(String::as_str))
            .expect("at least one hash is required")
            .to_owned();

        let active = hashes
            .get(&active_name)
            .cloned()
            .expect("active hash exists");

        Self {
            base: ProofBase::new(active),
            hashes,
            active_hash: active_name,
            default_length: 16,
            default_charset: DEFAULT_CHARSET.into(),
        }
    }

    /// Create a password proof with a custom hash registry.
    #[must_use]
    pub fn with_hashes(hashes: HashMap<String, Arc<dyn Hash>>) -> Self {
        let active_name = hashes
            .keys()
            .next()
            .cloned()
            .expect("at least one hash is required");
        let active = hashes
            .get(&active_name)
            .cloned()
            .expect("active hash exists");

        Self {
            base: ProofBase::new(active),
            hashes,
            active_hash: active_name,
            default_length: 16,
            default_charset: DEFAULT_CHARSET.into(),
        }
    }

    /// Register an additional hash algorithm.
    pub fn add_hash(&mut self, name: impl Into<String>, hasher: Arc<dyn Hash>) {
        self.hashes.insert(name.into(), hasher);
    }

    /// Remove a hash algorithm from the registry.
    pub fn remove_hash(&mut self, name: &str) -> Result<(), AuthError> {
        if !self.hashes.contains_key(name) {
            return Err(AuthError::InvalidInput(format!("hash '{name}' not found")));
        }
        if self.active_hash == name {
            return Err(AuthError::InvalidInput("cannot remove current hash".into()));
        }
        self.hashes.remove(name);
        Ok(())
    }

    /// Look up a registered hash by name.
    pub fn hash_by_name(&self, name: &str) -> Result<Arc<dyn Hash>, AuthError> {
        self.hashes
            .get(name)
            .cloned()
            .ok_or_else(|| AuthError::InvalidInput(format!("hash '{name}' not found")))
    }

    /// Set generated password length (minimum 8).
    pub fn set_length(&mut self, length: usize) -> Result<&mut Self, AuthError> {
        if length < 8 {
            return Err(AuthError::InvalidInput(
                "password length must be at least 8 characters".into(),
            ));
        }
        self.default_length = length;
        Ok(self)
    }

    /// Set the character set used for password generation (minimum 10 characters).
    pub fn set_charset(&mut self, charset: impl Into<String>) -> Result<&mut Self, AuthError> {
        let charset = charset.into();
        if charset.len() < 10 {
            return Err(AuthError::InvalidInput(
                "password charset must contain at least 10 characters".into(),
            ));
        }
        self.default_charset = charset;
        Ok(self)
    }

    /// Create a hash instance by PHP-compatible type name.
    pub fn create_hash(
        hash_type: &str,
        options: HashMap<String, Value>,
    ) -> Result<Arc<dyn Hash>, AuthError> {
        fn with_options<T>(mut hash: T, options: HashMap<String, Value>) -> Arc<dyn Hash>
        where
            T: HashMut + 'static,
        {
            hash.set_options(options);
            Arc::new(hash)
        }

        #[cfg(feature = "argon2")]
        if hash_type == Self::ARGON2 {
            return Ok(with_options(Argon2::new(), options));
        }

        #[cfg(feature = "bcrypt")]
        if hash_type == Self::BCRYPT {
            return Ok(with_options(Bcrypt::new(), options));
        }

        #[cfg(feature = "legacy")]
        {
            if hash_type == Self::SCRYPT {
                return Ok(with_options(Scrypt::new(), options));
            }
            if hash_type == Self::SCRYPT_MODIFIED {
                return Ok(with_options(ScryptModified::new(), options));
            }
            if hash_type == Self::SHA {
                return Ok(with_options(Sha::new(), options));
            }
            if hash_type == Self::MD5 {
                return Ok(with_options(Md5::new(), options));
            }
            if hash_type == Self::PHPASS {
                return Ok(with_options(PHPass::new(), options));
            }
        }

        Err(AuthError::InvalidInput(format!(
            "Unsupported hash type: {hash_type}"
        )))
    }
}

impl Proof for Password {
    fn generate(&self) -> Result<String, AuthError> {
        if self.default_charset.is_empty() {
            return Err(AuthError::InvalidInput("password charset is empty".into()));
        }

        let mut rng = rand::thread_rng();
        let chars: Vec<char> = self.default_charset.chars().collect();
        let max = chars.len() - 1;

        let password = (0..self.default_length)
            .map(|_| chars[rng.gen_range(0..=max)])
            .collect();

        Ok(password)
    }

    fn hash(&self, proof: &str) -> Result<String, AuthError> {
        self.base.hash_proof(proof)
    }

    fn verify(&self, proof: &str, hash: &str) -> bool {
        self.base.verify_proof(proof, hash)
    }

    fn hasher(&self) -> &dyn Hash {
        self.base.hasher()
    }

    fn set_hasher(&mut self, hasher: Arc<dyn Hash>) {
        if let Some(name) = self
            .hashes
            .iter()
            .find(|(_, value)| Arc::ptr_eq(value, &hasher))
            .map(|(key, _)| key.clone())
        {
            self.active_hash = name;
        }
        self.base.set_hasher(hasher);
    }
}
