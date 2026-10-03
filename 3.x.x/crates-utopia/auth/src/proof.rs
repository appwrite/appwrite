//! Authentication proof base trait.

use std::sync::Arc;

use crate::error::AuthError;
use crate::hash::Hash;

#[cfg(feature = "argon2")]
use crate::hashes::Argon2;

/// Authentication proof generator and verifier.
pub trait Proof: Send + Sync {
    /// Generate a new proof value.
    fn generate(&self) -> Result<String, AuthError>;

    /// Hash a proof value using the configured hasher.
    fn hash(&self, proof: &str) -> Result<String, AuthError>;

    /// Verify a proof against a stored hash.
    fn verify(&self, proof: &str, hash: &str) -> bool;

    /// Access the active hash implementation.
    fn hasher(&self) -> &dyn Hash;

    /// Replace the active hash implementation.
    fn set_hasher(&mut self, hasher: Arc<dyn Hash>);
}

/// Shared proof helpers backed by a [`Hash`] implementation.
#[derive(Clone)]
pub struct ProofBase {
    hasher: Arc<dyn Hash>,
}

impl std::fmt::Debug for ProofBase {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ProofBase")
            .field("hasher", &self.hasher.name())
            .finish()
    }
}

impl ProofBase {
    /// Create a proof base with the given hasher.
    #[must_use]
    pub fn new(hasher: Arc<dyn Hash>) -> Self {
        Self { hasher }
    }

    /// Create a proof base with Argon2 as the default hasher.
    #[cfg(feature = "argon2")]
    #[must_use]
    pub fn default_hasher() -> Self {
        Self::new(Arc::new(Argon2::new()))
    }

    /// Hash a proof value.
    pub fn hash_proof(&self, proof: &str) -> Result<String, AuthError> {
        self.hasher.hash(proof)
    }

    /// Verify a proof value.
    pub fn verify_proof(&self, proof: &str, hash: &str) -> bool {
        self.hasher.verify(proof, hash)
    }

    /// Access the active hasher.
    pub fn hasher(&self) -> &dyn Hash {
        self.hasher.as_ref()
    }

    /// Replace the active hasher.
    pub fn set_hasher(&mut self, hasher: Arc<dyn Hash>) {
        self.hasher = hasher;
    }
}

impl Default for ProofBase {
    fn default() -> Self {
        #[cfg(feature = "argon2")]
        {
            Self::default_hasher()
        }
        #[cfg(not(feature = "argon2"))]
        {
            panic!("no default hasher available without the `argon2` feature")
        }
    }
}
