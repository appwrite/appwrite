//! The Argon2id password hash.

use super::{hash_plumbing, password};
use crate::{Error, Hash, Options};

/// The Argon2id password hash, in the standard `$argon2id$` format.
///
/// Options: `type`, `memory_cost` (KiB), `time_cost` and `threads`.
///
/// PHP: `Utopia\Auth\Hashes\Argon2`, which passes every option to
/// `password_hash()`; it reads the last three.
///
/// ```
/// use utopia_auth::Hash;
/// use utopia_auth::hashes::Argon2;
///
/// let mut argon2 = Argon2::new();
/// argon2.set_memory_cost(7168).set_time_cost(5).set_threads(1);
/// let hash = argon2.hash(b"secret")?;
/// assert!(hash.starts_with(b"$argon2id$v=19$m=7168,t=5,p=1$"));
/// assert!(argon2.verify(b"secret", &hash)?);
/// # Ok::<(), utopia_auth::Error>(())
/// ```
///
/// ```php
/// use Utopia\Auth\Hashes\Argon2;
///
/// $argon2 = (new Argon2())->setMemoryCost(7168)->setTimeCost(5)->setThreads(1);
/// $hash = $argon2->hash('secret');
/// echo substr($hash, 0, 30), "\n";             // $argon2id$v=19$m=7168,t=5,p=1$
/// var_dump($argon2->verify('secret', $hash)); // bool(true)
/// ```
#[derive(Debug, Clone, PartialEq)]
pub struct Argon2 {
    options: Options,
}

impl Default for Argon2 {
    /// The same as [`Argon2::new`].
    fn default() -> Self {
        Self::new()
    }
}

impl Argon2 {
    /// Argon2id with 65536 KiB of memory, a time cost of 4 and 3 threads.
    ///
    /// PHP: `new Argon2()`.
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("type", "argon2");
        options.set("memory_cost", 65536);
        options.set("time_cost", 4);
        options.set("threads", 3);
        Self { options }
    }

    /// Sets the memory cost, in KiB. It is not checked until hashing.
    ///
    /// PHP: `setMemoryCost()`.
    pub fn set_memory_cost(&mut self, cost: i64) -> &mut Self {
        self.options.set("memory_cost", cost);
        self
    }

    /// Sets the time cost: the number of passes over memory. It is not
    /// checked until hashing.
    ///
    /// PHP: `setTimeCost()`.
    pub fn set_time_cost(&mut self, cost: i64) -> &mut Self {
        self.options.set("time_cost", cost);
        self
    }

    /// Sets the degree of parallelism. It is not checked until hashing.
    ///
    /// PHP: `setThreads()`.
    pub fn set_threads(&mut self, threads: i64) -> &mut Self {
        self.options.set("threads", threads);
        self
    }
}

impl Hash for Argon2 {
    hash_plumbing!("argon2");

    /// Hashes `value` with Argon2id and a random salt.
    ///
    /// Fails when a cost is out of the range Argon2 accepts.
    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        password::hash_argon2id(value, &self.options)
    }

    /// Whether `value` matches a stored password hash: Argon2i, Argon2id,
    /// bcrypt or another standard crypt format.
    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(password::verify(value, hash))
    }
}
