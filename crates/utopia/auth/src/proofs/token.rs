use super::{Proof, proof_hash};
use crate::hash::random_bytes;
use crate::hashes::Argon2;
use crate::{Error, Hash};

/// A random hex token (`Utopia\Auth\Proofs\Token`), 256 characters by default.
#[derive(Debug, Clone)]
pub struct Token {
    length: i64,
    hash: Box<dyn Hash>,
}

fn check(length: i64) -> Result<i64, Error> {
    if length <= 0 {
        return Err(Error::Exception("Token length must be greater than 0".into()));
    }
    Ok(length)
}

impl Token {
    /// `new Token($length)`.
    pub fn new(length: i64) -> Result<Self, Error> {
        Ok(Self { length: check(length)?, hash: Box::new(Argon2::new()) })
    }

    /// `getLength()`.
    pub fn length(&self) -> i64 {
        self.length
    }

    /// `setLength()`.
    pub fn set_length(&mut self, length: i64) -> Result<&mut Self, Error> {
        self.length = check(length)?;
        Ok(self)
    }

    /// `generate()`: `length` lowercase hex characters.
    pub fn token(&self) -> String {
        let length = usize::try_from(self.length).unwrap_or(0);
        let mut bytes = vec![0u8; length.div_ceil(2).max(1)];
        random_bytes(&mut bytes);
        let mut out = hex::encode(bytes);
        out.truncate(length);
        out
    }
}

impl Default for Token {
    fn default() -> Self {
        Self { length: 256, hash: Box::new(Argon2::new()) }
    }
}

impl Proof for Token {
    proof_hash!();

    fn generate(&self) -> Result<Vec<u8>, Error> {
        Ok(self.token().into_bytes())
    }
}
