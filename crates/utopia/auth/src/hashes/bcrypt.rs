//! The bcrypt password hash.

use super::{hash_plumbing, password};
use crate::{Error, Hash, Options};

/// The bcrypt password hash, in the `$2y$` format.
///
/// Options: `type` and `cost`.
///
/// PHP: `Utopia\Auth\Hashes\Bcrypt`, which hashes through `password_hash()`.
#[derive(Debug, Clone, PartialEq)]
pub struct Bcrypt {
    options: Options,
}

impl Default for Bcrypt {
    /// The same as [`Bcrypt::new`].
    fn default() -> Self {
        Self::new()
    }
}

impl Bcrypt {
    /// Bcrypt with a cost of 8.
    ///
    /// PHP: `new Bcrypt()`.
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("type", "bcrypt");
        options.set("cost", 8);
        Self { options }
    }

    /// Sets the cost: log2 of the number of rounds.
    ///
    /// Fails with [`Error::InvalidArgument`] unless it is between 4 and 31.
    ///
    /// PHP: `setCost()`.
    pub fn set_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if !(4..=31).contains(&cost) {
            return Err(Error::InvalidArgument("Cost must be between 4 and 31".into()));
        }
        self.options.set("cost", cost);
        Ok(self)
    }
}

impl Hash for Bcrypt {
    hash_plumbing!("bcrypt");

    /// Hashes `value` with bcrypt and a random salt.
    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        password::hash_bcrypt(value, &self.options)
    }

    /// Whether `value` matches a stored password hash: bcrypt, Argon2 or
    /// another standard crypt format.
    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(password::verify(value, hash))
    }
}
