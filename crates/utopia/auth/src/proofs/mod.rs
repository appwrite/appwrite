//! Secrets a user proves knowledge of (`Utopia\Auth\Proof` and `Utopia\Auth\Proofs\*`).

mod code;
mod password;
mod phrase;
mod token;

pub use code::Code;
pub use password::{Password, set_options};
pub use phrase::Phrase;
pub use token::Token;

use crate::{Error, Hash};

/// A kind of secret: how it is generated, and the [`Hash`] that stores it
/// (`Utopia\Auth\Proof`). Argon2 unless set otherwise.
pub trait Proof {
    /// `generate()`: a new secret.
    fn generate(&self) -> Result<Vec<u8>, Error>;

    /// `getHash()`.
    fn hasher(&self) -> &dyn Hash;

    /// `getHash()`, to change its options.
    fn hasher_mut(&mut self) -> &mut dyn Hash;

    /// `setHash()`.
    fn set_hash(&mut self, hash: Box<dyn Hash>);

    /// `hash($proof)`.
    fn hash(&self, proof: &[u8]) -> Result<Vec<u8>, Error> {
        self.hasher().hash(proof)
    }

    /// `verify($proof, $hash)`.
    fn verify(&self, proof: &[u8], hash: &[u8]) -> Result<bool, Error> {
        self.hasher().verify(proof, hash)
    }
}

/// Implements the hash accessors of [`Proof`] for a proof holding `hash: Box<dyn Hash>`.
macro_rules! proof_hash {
    () => {
        fn hasher(&self) -> &dyn $crate::Hash {
            self.hash.as_ref()
        }

        fn hasher_mut(&mut self) -> &mut dyn $crate::Hash {
            self.hash.as_mut()
        }

        fn set_hash(&mut self, hash: Box<dyn $crate::Hash>) {
            self.hash = hash;
        }
    };
}
pub(crate) use proof_hash;
