use php_std::encoding::bin2hex;

use super::hash_plumbing;
use crate::hash::{hash_equals, random_bytes};
use crate::{Error, Hash, Options};

/// Scrypt through php-scrypt's `scrypt()`, lowercase hex
/// (`Utopia\Auth\Hashes\Scrypt`).
///
/// Options: `type`, `costCpu` (N), `costMemory` (r), `costParallel` (p),
/// `length` (bytes) and `salt` (used as given, a random hex string by default).
#[derive(Debug, Clone, PartialEq)]
pub struct Scrypt {
    options: Options,
}

impl Default for Scrypt {
    fn default() -> Self {
        Self::new()
    }
}

impl Scrypt {
    /// `new Scrypt()`: N 8, r 14, p 1, 64 bytes, a random salt.
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

    /// `setCpuCost()`: N, a power of 2 above 1.
    pub fn set_cpu_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if cost <= 1 || (cost & (cost - 1)) != 0 {
            return Err(Error::InvalidArgument("CPU cost must be > 1 and a power of 2".into()));
        }
        self.options.set("costCpu", cost);
        Ok(self)
    }

    /// `setMemoryCost()`: r, at least 1.
    pub fn set_memory_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if cost < 1 {
            return Err(Error::InvalidArgument("Memory cost must be >= 1".into()));
        }
        self.options.set("costMemory", cost);
        Ok(self)
    }

    /// `setParallelCost()`: p, at least 1.
    pub fn set_parallel_cost(&mut self, cost: i64) -> Result<&mut Self, Error> {
        if cost < 1 {
            return Err(Error::InvalidArgument("Parallel cost must be >= 1".into()));
        }
        self.options.set("costParallel", cost);
        Ok(self)
    }

    /// `setLength()`: at least 16 bytes.
    pub fn set_length(&mut self, length: i64) -> Result<&mut Self, Error> {
        if length < 16 {
            return Err(Error::InvalidArgument("Length must be >= 16 bytes".into()));
        }
        self.options.set("length", length);
        Ok(self)
    }

    /// `setSalt()`: neither `""` nor `"0"`.
    pub fn set_salt(&mut self, salt: &[u8]) -> Result<&mut Self, Error> {
        if salt.is_empty() || salt == b"0" {
            return Err(Error::InvalidArgument("Salt cannot be empty".into()));
        }
        self.options.set("salt", salt.to_vec());
        Ok(self)
    }
}

/// php-scrypt's `scrypt($password, $salt, $N, $r, $p, $keyLength)`: hex output.
pub(crate) fn scrypt(password: &[u8], salt: &[u8], n: i64, r: i64, p: i64, length: i64) -> Result<Vec<u8>, Error> {
    if n < 2 {
        return Err(Error::Engine("scrypt(): Argument #3 ($N) must be greater than 1".into()));
    }
    if n & (n - 1) != 0 {
        return Err(Error::Engine("scrypt(): Argument #3 ($N) must be a power of 2".into()));
    }
    if r < 1 {
        return Err(Error::Engine("scrypt(): Argument #4 ($r) must be greater than 0".into()));
    }
    if p < 1 {
        return Err(Error::Engine("scrypt(): Argument #5 ($p) must be greater than 0".into()));
    }
    if length < 16 {
        return Err(Error::Engine("scrypt(): Argument #6 ($key_length) must be greater than or equal to 16".into()));
    }
    let failed = || Error::Runtime("Failed to hash using scrypt".into());
    let (r, p) = (u32::try_from(r).map_err(|_| failed())?, u32::try_from(p).map_err(|_| failed())?);
    let length = usize::try_from(length).map_err(|_| failed())?;
    // The derived length is checked by `scrypt::scrypt`; the params' own
    // length bound only applies to PHC strings.
    let params = ::scrypt::Params::new(n.trailing_zeros() as u8, r, p, 64).map_err(|_| failed())?;
    let mut out = vec![0u8; length];
    ::scrypt::scrypt(password, salt, &params, &mut out).map_err(|_| failed())?;
    Ok(bin2hex(&out).into_bytes())
}

impl Hash for Scrypt {
    hash_plumbing!("scrypt");

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

    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(hash_equals(hash, &self.hash(value)?))
    }
}
