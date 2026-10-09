use super::{Proof, proof_hash};
use crate::hash::random_int;
use crate::hashes::Argon2;
use crate::{Error, Hash};

/// A numeric one-time code (`Utopia\Auth\Proofs\Code`), 6 digits by default.
#[derive(Debug, Clone)]
pub struct Code {
    length: i64,
    hash: Box<dyn Hash>,
}

fn check(length: i64) -> Result<i64, Error> {
    if length <= 0 {
        return Err(Error::Exception("Code length must be greater than 0".into()));
    }
    Ok(length)
}

impl Code {
    /// `new Code($length)`.
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

    /// `generate()`: `length` random decimal digits.
    pub fn code(&self) -> String {
        (0..self.length).map(|_| char::from(b'0' + random_int(9) as u8)).collect()
    }
}

impl Default for Code {
    fn default() -> Self {
        Self { length: 6, hash: Box::new(Argon2::new()) }
    }
}

impl Proof for Code {
    proof_hash!();

    fn generate(&self) -> Result<Vec<u8>, Error> {
        Ok(self.code().into_bytes())
    }
}
