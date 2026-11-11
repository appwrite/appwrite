//! Secrets a user proves they know: passwords, one-time codes, tokens and
//! passphrases, each generated and stored through a [`Hash`].
//!
//! PHP: `Utopia\Auth\Proof` and `Utopia\Auth\Proofs\*`.

mod code;
mod password;
mod phrase;
mod token;

pub use code::Code;
pub use password::{Password, set_options};
pub use phrase::Phrase;
pub use token::Token;

use crate::{Error, Hash};

/// A kind of secret: how it is generated, and the [`Hash`] that stores it.
///
/// Every proof hashes with Argon2 unless set otherwise.
///
/// PHP: `Utopia\Auth\Proof`.
pub trait Proof {
    /// A new random secret.
    ///
    /// PHP: `generate()`.
    fn generate(&self) -> Result<Vec<u8>, Error>;

    /// The hash that stores this secret.
    ///
    /// PHP: `getHash()`.
    fn hasher(&self) -> &dyn Hash;

    /// The hash that stores this secret, to change its options.
    ///
    /// PHP: `getHash()`.
    fn hasher_mut(&mut self) -> &mut dyn Hash;

    /// Replaces the hash that stores this secret.
    ///
    /// PHP: `setHash()`.
    fn set_hash(&mut self, hash: Box<dyn Hash>);

    /// Hashes `proof` with this proof's hash, returning the string to store.
    ///
    /// PHP: `hash($proof)`.
    fn hash(&self, proof: &[u8]) -> Result<Vec<u8>, Error> {
        self.hasher().hash(proof)
    }

    /// Whether `proof` matches a stored `hash`.
    ///
    /// PHP: `verify($proof, $hash)`.
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
