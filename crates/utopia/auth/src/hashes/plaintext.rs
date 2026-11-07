//! A hash that stores the password as is.

use super::hash_plumbing;
use crate::hash::hash_equals;
use crate::{Error, Hash, Options};

/// A hash that stores the password unchanged.
///
/// PHP: `Utopia\Auth\Hashes\Plaintext`.
#[derive(Debug, Clone, PartialEq)]
pub struct Plaintext {
    options: Options,
}

impl Default for Plaintext {
    /// The same as [`Plaintext::new`].
    fn default() -> Self {
        Self::new()
    }
}

impl Plaintext {
    /// The plaintext hash. It has no options besides `type`.
    ///
    /// PHP: `new Plaintext()`.
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("type", "plaintext");
        Self { options }
    }
}

impl Hash for Plaintext {
    hash_plumbing!("plaintext");

    /// The value itself.
    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        Ok(value.to_vec())
    }

    /// Whether `value` equals `hash`, compared in constant time.
    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, value))
    }
}
