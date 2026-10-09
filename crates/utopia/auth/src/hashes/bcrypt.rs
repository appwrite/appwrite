use super::{hash_plumbing, password};
use crate::{Error, Hash, Options};

/// Bcrypt through `password_hash()` (`Utopia\Auth\Hashes\Bcrypt`): `$2y$` hashes.
#[derive(Debug, Clone, PartialEq)]
pub struct Bcrypt {
    options: Options,
}

impl Default for Bcrypt {
    fn default() -> Self {
        Self::new()
    }
}

impl Bcrypt {
    /// `new Bcrypt()`: cost 8.
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("type", "bcrypt");
        options.set("cost", 8);
        Self { options }
    }

    /// `setCost()`: between 4 and 31.
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

    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        password::hash_bcrypt(value, &self.options)
    }

    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(password::verify(value, hash))
    }
}
