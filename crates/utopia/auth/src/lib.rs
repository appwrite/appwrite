//! The password hash algorithms of `utopia-php/auth` (`packages/auth/src/Hashes`) that PHP
//! itself does not provide: the scrypt KDF of the PECL `scrypt` extension, Firebase's
//! modified scrypt, and phpass's portable hashes. The rest (`password_hash()`,
//! `password_verify()`, `crypt()`, `hash()`) are PHP's own functions, ported in `php-std`.
//!
//! Every function here returns what PHP returns for the same input, byte for byte, including
//! where PHP fails: [`scrypt::scrypt`] fails as `scrypt()` does, with its `Error`s, its
//! warnings and its `false`.

pub mod firebase;
pub mod phpass;
pub mod scrypt;

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

/// PHP `hash_equals($known, $user)`: false at once when the lengths differ (PHP leaks the
/// length too), otherwise a comparison whose time does not depend on where they differ.
#[must_use]
pub fn hash_equals(known: &[u8], user: &[u8]) -> bool {
    if known.len() != user.len() {
        return false;
    }
    let difference = known.iter().zip(user).fold(0u8, |acc, (a, b)| acc | (a ^ b));
    // `black_box` keeps the compiler from turning the fold into an early-exit comparison.
    std::hint::black_box(difference) == 0
}

#[cfg(test)]
mod tests {
    use super::hash_equals;

    #[test]
    fn password_salts_are_php_salts() {
        for length in [16, 22] {
            let salt = super::password_salt(length);
            assert_eq!(salt.len(), length);
            assert!(salt.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'.' || *b == b'/'));
        }
    }

    #[test]
    fn hash_equals_is_php_hash_equals() {
        assert!(hash_equals(b"", b""));
        assert!(hash_equals(b"abc", b"abc"));
        assert!(!hash_equals(b"abc", b"abd"));
        assert!(!hash_equals(b"abc", b"ab"));
        assert!(!hash_equals(b"ab", b"abc"));
    }
}
