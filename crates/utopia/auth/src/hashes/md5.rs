//! The unsalted MD5 digest, for imported legacy passwords.

use md5::{Digest, Md5 as Md5Digest};

use super::hash_plumbing;
use crate::hash::hash_equals;
use crate::{Error, Hash, Options};

/// The unsalted MD5 digest of a password, as lowercase hex.
///
/// Weak: use it only to verify passwords imported from another system.
///
/// PHP: `Utopia\Auth\Hashes\MD5`.
#[derive(Debug, Clone, PartialEq)]
pub struct Md5 {
    options: Options,
}

impl Default for Md5 {
    /// The same as [`Md5::new`].
    fn default() -> Self {
        Self::new()
    }
}

impl Md5 {
    /// The MD5 hash. It has no options besides `type`.
    ///
    /// PHP: `new MD5()`.
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("type", "md5");
        Self { options }
    }
}

impl Hash for Md5 {
    hash_plumbing!("md5");

    /// The MD5 digest of `value`, as 32 lowercase hex characters.
    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        Ok(hex::encode(Md5Digest::digest(value)).into_bytes())
    }

    /// Whether the digest of `value` equals `hash`, compared in constant time.
    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, &self.hash(value)?))
    }
}
