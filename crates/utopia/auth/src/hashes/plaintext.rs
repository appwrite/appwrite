use super::hash_plumbing;
use crate::hash::hash_equals;
use crate::{Error, Hash, Options};

/// The value itself (`Utopia\Auth\Hashes\Plaintext`).
#[derive(Debug, Clone, PartialEq)]
pub struct Plaintext {
    options: Options,
}

impl Default for Plaintext {
    fn default() -> Self {
        Self::new()
    }
}

impl Plaintext {
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("type", "plaintext");
        Self { options }
    }
}

impl Hash for Plaintext {
    hash_plumbing!("plaintext");

    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        Ok(value.to_vec())
    }

    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, value))
    }
}
