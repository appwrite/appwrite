//! Randomness drawn as PHP draws it, for the hashes the utopia PHP extension serves:
//! straight from the operating system on every call.
//!
//! The hashes and proofs of this crate draw from [`crate::random_bytes`] instead, the
//! thread's CSPRNG keyed from the same source.

/// The operating system could not provide random bytes.
///
/// PHP: `Random\RandomException`, "Could not gather sufficient random data".
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct RandomError;

impl RandomError {
    /// PHP's message for it.
    pub const MESSAGE: &'static str = "Could not gather sufficient random data";
}

impl std::fmt::Display for RandomError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(Self::MESSAGE)
    }
}

impl std::error::Error for RandomError {}

/// PHP `random_bytes($length)`: bytes from the operating system's CSPRNG, or
/// [`RandomError`] where PHP throws, so a caller across FFI can raise it instead of
/// unwinding.
pub fn random_bytes(length: usize) -> Result<Vec<u8>, RandomError> {
    let mut bytes = vec![0u8; length];
    getrandom::fill(&mut bytes).map_err(|_| RandomError)?;
    Ok(bytes)
}

/// `php_password_make_salt($length)`, the salt `password_hash()` draws: `$length * 3 / 4 + 1`
/// random bytes in base64, `+` as `.`, cut to `$length` characters (22 for bcrypt, 16 for
/// Argon2, which hashes these characters as its salt).
pub fn password_salt(length: usize) -> Result<Vec<u8>, RandomError> {
    let encoded = php_std::encoding::base64_encode(&random_bytes(length * 3 / 4 + 1)?);
    Ok(encoded.bytes().take(length).map(|b| if b == b'+' { b'.' } else { b }).collect())
}

#[cfg(test)]
mod tests {
    #[test]
    fn password_salts_are_php_salts() {
        for length in [16, 22] {
            let salt = super::password_salt(length).unwrap();
            assert_eq!(salt.len(), length);
            assert!(salt.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'.' || *b == b'/'));
        }
    }
}
