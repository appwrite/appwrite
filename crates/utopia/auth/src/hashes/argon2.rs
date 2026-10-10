use super::{hash_plumbing, password};
use crate::{Error, Hash, Options};

/// Argon2id through `password_hash()` (`Utopia\Auth\Hashes\Argon2`).
///
/// Options: `type`, `memory_cost` (KiB), `time_cost`, `threads`; every
/// option is passed to `password_hash()`, which reads the last three.
#[derive(Debug, Clone, PartialEq)]
pub struct Argon2 {
    options: Options,
}

impl Default for Argon2 {
    fn default() -> Self {
        Self::new()
    }
}

impl Argon2 {
    /// `new Argon2()`: memory 65536 KiB, time 4, threads 3.
    pub fn new() -> Self {
        let mut options = Options::new();
        options.set("type", "argon2");
        options.set("memory_cost", 65536);
        options.set("time_cost", 4);
        options.set("threads", 3);
        Self { options }
    }

    /// `setMemoryCost()`, in KiB. Not validated until hashing, as in PHP.
    pub fn set_memory_cost(&mut self, cost: i64) -> &mut Self {
        self.options.set("memory_cost", cost);
        self
    }

    /// `setTimeCost()`.
    pub fn set_time_cost(&mut self, cost: i64) -> &mut Self {
        self.options.set("time_cost", cost);
        self
    }

    /// `setThreads()`.
    pub fn set_threads(&mut self, threads: i64) -> &mut Self {
        self.options.set("threads", threads);
        self
    }
}

impl Hash for Argon2 {
    hash_plumbing!("argon2");

    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error> {
        password::hash_argon2id(value, &self.options)
    }

    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error> {
        Ok(password::verify(value, hash))
    }
}
