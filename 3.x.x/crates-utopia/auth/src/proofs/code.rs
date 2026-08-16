//! Numeric one-time code proofs.

use std::sync::Arc;

use rand::Rng;

use crate::error::AuthError;
use crate::hash::Hash;
use crate::proof::{Proof, ProofBase};

/// Numeric one-time code proof (e.g. for 2FA).
#[derive(Clone, Debug)]
pub struct Code {
    base: ProofBase,
    length: usize,
}

impl Code {
    /// Create a code proof with the given digit length (default 6).
    pub fn new(length: usize) -> Result<Self, AuthError> {
        if length == 0 {
            return Err(AuthError::InvalidInput(
                "code length must be greater than 0".into(),
            ));
        }
        Ok(Self {
            base: ProofBase::default(),
            length,
        })
    }

    /// Create a code proof with the default length of 6.
    pub fn with_default_length() -> Result<Self, AuthError> {
        Self::new(6)
    }

    /// Current code length.
    #[must_use]
    pub fn length(&self) -> usize {
        self.length
    }

    /// Set code length.
    pub fn set_length(&mut self, length: usize) -> Result<&mut Self, AuthError> {
        if length == 0 {
            return Err(AuthError::InvalidInput(
                "code length must be greater than 0".into(),
            ));
        }
        self.length = length;
        Ok(self)
    }
}

impl Default for Code {
    fn default() -> Self {
        Self::with_default_length().expect("default code length is valid")
    }
}

impl Proof for Code {
    fn generate(&self) -> Result<String, AuthError> {
        let mut rng = rand::thread_rng();
        let code = (0..self.length)
            .map(|_| char::from(b'0' + rng.gen_range(0..10)))
            .collect();
        Ok(code)
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
        self.base.set_hasher(hasher);
    }
}
