//! The password hash trait, and the constant-time comparison and secure
//! randomness the hashes and proofs share.

use std::fmt::Debug;

use crate::{Error, Options};

/// A password hashing algorithm, with its options.
///
/// The built-in algorithms live in [`crate::hashes`]; implement this trait
/// to register another one with [`Password::add_hash`](crate::proofs::Password::add_hash).
/// Hashing is CPU bound: async callers run it on the blocking pool.
///
/// PHP: `Utopia\Auth\Hash`.
pub trait Hash: Debug + Send + Sync {
    /// The algorithm's stored name (`argon2`, `bcrypt`, ...).
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The options document.
    ///
    /// PHP: `getOptions()`.
    fn options(&self) -> &Options;

    /// The options document, to change.
    ///
    /// PHP: `setOption()`, `setOptions()`.
    fn options_mut(&mut self) -> &mut Options;

    /// Hashes `value`, returning the string to store.
    ///
    /// Fails when an option is out of range or has the wrong type.
    fn hash(&self, value: &[u8]) -> Result<Vec<u8>, Error>;

    /// Whether `value` matches a stored `hash`.
    fn verify(&self, value: &[u8], hash: &[u8]) -> Result<bool, Error>;

    /// A copy of this hash with its options.
    fn boxed_clone(&self) -> Box<dyn Hash>;

    /// The concrete hash, to downcast to its type.
    ///
    /// PHP: reaches the typed setters, as `getHashByName('bcrypt')->setCost()` does.
    fn as_any(&self) -> &dyn std::any::Any;

    /// The concrete hash, to downcast to its type and call its setters.
    fn as_any_mut(&mut self) -> &mut dyn std::any::Any;
}

impl Clone for Box<dyn Hash> {
    /// A copy of the boxed hash with its options.
    fn clone(&self) -> Self {
        self.boxed_clone()
    }
}

/// Compares two byte strings in constant time, to check secrets without
/// leaking how much of them matched.
///
/// Strings of different lengths are unequal at once.
///
/// PHP: `hash_equals($known, $user)`.
pub fn hash_equals(known: &[u8], user: &[u8]) -> bool {
    if known.len() != user.len() {
        return false;
    }
    known.iter().zip(user).fold(0u8, |acc, (a, b)| acc | (a ^ b)) == 0
}

/// Fills `buf` with cryptographically secure random bytes.
///
/// Panics when the system random source is unavailable.
///
/// PHP: `random_bytes()`.
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
