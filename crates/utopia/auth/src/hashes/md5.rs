use md5::{Digest, Md5 as Md5Digest};

use super::hash_plumbing;
use crate::hash::hash_equals;
use crate::{Error, Hash, Options};

/// Unsalted MD5, lowercase hex (`Utopia\Auth\Hashes\MD5`).
#[derive(Debug, Clone, PartialEq)]
pub struct Md5 {
    options: Options,
}

impl Default for Md5 {
    fn default() -> Self {
        Self::new()
    }
}

impl Md5 {
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("type", "md5");
        Self { options }
    }
}

impl Hash for Md5 {
    hash_plumbing!("md5");

    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        Ok(hex::encode(Md5Digest::digest(value)).into_bytes())
    }

    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, &self.hash(value)?))
    }
}
