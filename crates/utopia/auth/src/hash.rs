use std::fmt::Debug;

use crate::{Error, Options};

/// A password hashing algorithm (`Utopia\Auth\Hash`).
///
/// The built-in algorithms live in [`crate::hashes`]; implement this trait
/// to register another one (`Password::add_hash`). Hashing is CPU bound:
/// async callers run it on the blocking pool.
pub trait Hash: Debug + Send + Sync {
    /// `getName()`: the algorithm's stored name (`argon2`, `bcrypt`, ...).
    fn name(&self) -> &str;

    /// `getOptions()`: the options document.
    fn options(&self) -> &Options;

    /// The options document, to change (`setOption()`, `setOptions()`).
    fn options_mut(&mut self) -> &mut Options;

    /// `hash($value)`.
    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error>;

    /// `verify($value, $hash)`.
    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error>;

    /// A copy of this hash with its options.
    fn boxed_clone(&self) -> Box<dyn Hash>;

    /// The concrete hash, to reach its typed setters (`getHashByName('bcrypt')->setCost()`).
    fn as_any(&self) -> &dyn std::any::Any;

    /// The concrete hash, to reach its typed setters.
    fn as_any_mut(&mut self) -> &mut dyn std::any::Any;
}

impl Clone for Box<dyn Hash> {
    fn clone(&self) -> Self {
        self.boxed_clone()
    }
}

/// `hash_equals($known, $user)`: constant-time byte comparison.
pub fn hash_equals(known: &[u8], user: &[u8]) -> bool {
    if known.len() != user.len() {
        return false;
    }
    known.iter().zip(user).fold(0u8, |acc, (a, b)| acc | (a ^ b)) == 0
}

/// Fills `buf` with cryptographically secure random bytes (`random_bytes`).
pub fn random_bytes(buf: &mut [u8]) {
    getrandom::fill(buf).expect("system random source unavailable");
}

/// `random_int(0, max)`: a uniform integer in `0..=max`.
pub(crate) fn random_int(max: u64) -> u64 {
    if max == 0 {
        return 0;
    }
    let range = max.wrapping_add(1);
    loop {
        let mut b = [0u8; 8];
        random_bytes(&mut b);
        let v = u64::from_le_bytes(b);
        if range == 0 {
            return v;
        }
        // Rejection sampling to avoid modulo bias.
        let zone = u64::MAX - (u64::MAX % range);
        if v < zone {
            return v % range;
        }
    }
}
