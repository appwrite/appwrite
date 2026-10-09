//! Authentication primitives compatible with `utopia-php/auth`.
//!
//! * [`Hash`]: every password hashing algorithm Appwrite stores (`argon2`,
//!   `bcrypt`, `md5`, `sha`, `phpass`, `scrypt`, `scryptMod`, `plaintext`)
//!   with the exact option documents PHP persists in `hashOptions`.
//! * [`proofs`]: random secret generation (`Token`, `Password`) and the
//!   `sha256` token hash.
//! * [`store`]: the base64 JSON session store (`{"id","secret"}`).
//!
//! Hashing is CPU bound; async callers should run it on a blocking thread.

mod hash;
mod phpass;
pub mod proofs;
pub mod store;

pub use hash::{Hash, HashError};

/// Fills `buf` with cryptographically secure random bytes.
pub fn random_bytes(buf: &mut [u8]) {
    getrandom::fill(buf).expect("system random source unavailable");
}

/// Constant-time string comparison (`hash_equals`).
pub fn hash_equals(known: &str, user: &str) -> bool {
    let a = known.as_bytes();
    let b = user.as_bytes();
    if a.len() != b.len() {
        return false;
    }
    let mut diff = 0u8;
    for (x, y) in a.iter().zip(b.iter()) {
        diff |= x ^ y;
    }
    diff == 0
}
