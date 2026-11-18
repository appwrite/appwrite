//! The scrypt password hash.

use php_std::encoding::bin2hex;

use super::hash_plumbing;
use crate::hash::{hash_equals, random_bytes};
use crate::scrypt::Failure;
use crate::{Error, Hash, Options};

/// The scrypt key derivation as a password hash, in lowercase hex.
///
/// Options: `type`, `costCpu` (N), `costMemory` (r), `costParallel` (p),
/// `length` (bytes) and `salt` (used as given, a random hex string by default).
///
/// PHP: `Utopia\Auth\Hashes\Scrypt`, through php-scrypt's `scrypt()`.
#[derive(Debug, Clone, PartialEq)]
pub struct Scrypt {
    options: Options,
}

impl Default for Scrypt {
    /// The same as [`Scrypt::new`].
    fn default() -> Self {
        Self::new()
    }
}

impl Scrypt {
    /// Scrypt with N 8, r 14, p 1, a 64-byte output and a random salt.
    ///
    /// PHP: `new Scrypt()`.
    pub fn new() -> Self {
        let mut salt = [0u8; 16];
        random_bytes(&mut salt);
        let mut options = Options::new();
        options.set("type", "scrypt");
        options.set("costCpu", 8);
        options.set("costMemory", 14);
        options.set("costParallel", 1);
        options.set("length", 64);
        options.set("salt", bin2hex(&salt));
        Self { options }
    }

    /// Sets the CPU cost N.
    ///
    /// Fails with [`Error::InvalidArgument`] unless it is a power of 2 above 1.
    ///
    /// PHP: `setCpuCost()`.
    pub fn set_cpu_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if cost <= 1 || (cost & (cost - 1)) != 0 {
            return Err(Error::InvalidArgument("CPU cost must be > 1 and a power of 2".into()));
        }
        self.options.set("costCpu", cost);
        Ok(self)
    }

    /// Sets the block size r.
    ///
    /// Fails with [`Error::InvalidArgument`] when it is below 1.
    ///
    /// PHP: `setMemoryCost()`.
    pub fn set_memory_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if cost < 1 {
            return Err(Error::InvalidArgument("Memory cost must be >= 1".into()));
        }
        self.options.set("costMemory", cost);
        Ok(self)
    }

    /// Sets the parallelism p.
    ///
    /// Fails with [`Error::InvalidArgument`] when it is below 1.
    ///
    /// PHP: `setParallelCost()`.
    pub fn set_parallel_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if cost < 1 {
            return Err(Error::InvalidArgument("Parallel cost must be >= 1".into()));
        }
        self.options.set("costParallel", cost);
        Ok(self)
    }

    /// Sets the length of the derived key, in bytes.
    ///
    /// Fails with [`Error::InvalidArgument`] when it is below 16.
    ///
    /// PHP: `setLength()`.
    pub fn set_length(&mut self, length: i64) -> Result<&mut Self, Error> {
        if length < 16 {
            return Err(Error::InvalidArgument("Length must be >= 16 bytes".into()));
        }
        self.options.set("length", length);
        Ok(self)
    }

    /// Sets the salt, used as given.
    ///
    /// Fails with [`Error::InvalidArgument`] when it is empty or `"0"`.
    ///
    /// PHP: `setSalt()`.
    pub fn set_salt(&mut self, salt: &[u8]) -> Result<&mut Self, Error> {
        if salt.is_empty() || salt == b"0" {
            return Err(Error::InvalidArgument("Salt cannot be empty".into()));
        }
        self.options.set("salt", salt.to_vec());
        Ok(self)
    }
}

/// php-scrypt's `scrypt($password, $salt, $N, $r, $p, $keyLength)`: hex output.
///
/// The derivation and its checks are [`crate::scrypt::scrypt`]'s: its `Error` is
/// [`Error::Engine`] with PHP's message; its warning and `false`, or memory that cannot be
/// allocated, are the `RuntimeException` `hash()` throws on `false`.
pub(crate) fn scrypt(password: &[u8], salt: &[u8], n: i64, r: i64, p: i64, length: i64) -> Result<Vec<u8>, Error> {
    match crate::scrypt::scrypt(password, salt, n, r, p, length) {
        Ok(key) => Ok(bin2hex(&key).into_bytes()),
        Err(Failure::Error(message)) => Err(Error::Engine(message)),
        Err(Failure::Warning(_) | Failure::False) => Err(Error::Runtime("Failed to hash using scrypt".into())),
    }
}

impl Hash for Scrypt {
    hash_plumbing!("scrypt");

    /// Derives a key from `value` and the salt, as lowercase hex.
    ///
    /// Fails when the salt is not a string, a cost or the length is not an
    /// integer, or scrypt rejects the parameters.
    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        let o = &self.options;
        let salt = o.get("salt").and(o.string("salt"));
        let Some(salt) = salt else {
            return Err(Error::InvalidArgument("Salt must be a string".into()));
        };
        let (Some(n), Some(r), Some(p), Some(length)) =
            (o.int("costCpu"), o.int("costMemory"), o.int("costParallel"), o.int("length"))
        else {
            return Err(Error::InvalidArgument("Scrypt cost and length options must be integers".into()));
        };
        scrypt(value, salt, n, r, p, length)
    }

    /// Whether hashing `value` gives `hash`, compared in constant time.
    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, &self.hash(value)?))
    }
}

#[cfg(test)]
mod tests {
    use super::scrypt;

    #[test]
    fn php_scrypt_vectors() {
        let hex = |n, r, p, l| String::from_utf8(scrypt(b"", b"", n, r, p, l).unwrap()).unwrap();
        // RFC 7914 §12, first vector.
        assert_eq!(
            hex(16, 1, 1, 64),
            "77d6576238657b203b19ca42c18a0497f16b4844e3074ae8dfdffa3fede21442fcd0069ded0948f8326a753a0fc81f17e8d3e0fb2e0d3628cf35e20c38d18906"
        );
        // From PHP: N = 2, and N = 2^16 with r = 1, which RFC 7914 implementations refuse.
        assert_eq!(hex(2, 1, 1, 16), "fa76e020d54d9e8aa24023c6baecdd46");
        assert_eq!(hex(65536, 1, 1, 16), "2f417181e071649663b17119437b3b64");
    }
}
