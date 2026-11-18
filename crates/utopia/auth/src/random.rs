//! Randomness drawn as PHP draws it, for the hashes the utopia PHP extension serves:
//! straight from the operating system on every call.
//!
//! The hashes and proofs of this crate draw from [`crate::random_bytes`] instead, the
//! thread's CSPRNG keyed from the same source.

/// PHP `random_bytes($length)`: bytes from the operating system's CSPRNG.
///
/// # Panics
///
/// When the operating system cannot provide random bytes, where PHP throws its `Exception`
/// ("Could not gather sufficient random data").
#[must_use]
pub fn random_bytes(length: usize) -> Vec<u8> {
    let mut bytes = vec![0u8; length];
    getrandom::fill(&mut bytes).expect("the operating system provides random bytes");
    bytes
}

/// `php_password_make_salt($length)`, the salt `password_hash()` draws: `$length * 3 / 4 + 1`
/// random bytes in base64, `+` as `.`, cut to `$length` characters (22 for bcrypt, 16 for
/// Argon2, which hashes these characters as its salt).
#[must_use]
pub fn password_salt(length: usize) -> Vec<u8> {
    let encoded = php_std::encoding::base64_encode(&random_bytes(length * 3 / 4 + 1));
    encoded.bytes().take(length).map(|b| if b == b'+' { b'.' } else { b }).collect()
}

#[cfg(test)]
mod tests {
    #[test]
    fn password_salts_are_php_salts() {
        for length in [16, 22] {
            let salt = super::password_salt(length);
            assert_eq!(salt.len(), length);
            assert!(salt.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'.' || *b == b'/'));
        }
    }
}
