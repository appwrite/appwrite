//! Cryptographically random token proofs.

use std::sync::Arc;

use rand::RngCore;

use crate::error::AuthError;
use crate::hash::Hash;
use crate::proof::{Proof, ProofBase};

/// Hex-encoded random token proof.
#[derive(Clone, Debug)]
pub struct Token {
    base: ProofBase,
    length: usize,
}

impl Token {
    /// Create a token proof with the given hex string length (default 256).
    pub fn new(length: usize) -> Result<Self, AuthError> {
        if length == 0 {
            return Err(AuthError::InvalidInput(
                "token length must be greater than 0".into(),
            ));
        }
        Ok(Self {
            base: ProofBase::default(),
            length,
        })
    }

    /// Create a token proof with the default length of 256.
    pub fn with_default_length() -> Result<Self, AuthError> {
        Self::new(256)
    }

    /// Current token length.
    #[must_use]
    pub fn length(&self) -> usize {
        self.length
    }

    /// Set token length.
    pub fn set_length(&mut self, length: usize) -> Result<&mut Self, AuthError> {
        if length == 0 {
            return Err(AuthError::InvalidInput(
                "token length must be greater than 0".into(),
            ));
        }
        self.length = length;
        Ok(self)
    }
}

impl Proof for Token {
    fn generate(&self) -> Result<String, AuthError> {
        let bytes_length = (self.length / 2).max(1);
        let mut bytes = vec![0u8; bytes_length];
        rand::thread_rng().fill_bytes(&mut bytes);
        let token = hex::encode(bytes);
        Ok(token[..self.length].to_owned())
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
