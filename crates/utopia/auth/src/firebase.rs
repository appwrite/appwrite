//! Firebase's modified scrypt, as `Utopia\Auth\Hashes\ScryptModified` computes it: scrypt
//! (N = 16384, r = 8, p = 1, 64 bytes) of the password under the salt and its separator, and
//! the signer key encrypted with AES-256-CTR under the first 32 bytes of that, from a zero IV.

use aes::Aes256;
use aes::cipher::{BlockEncrypt, KeyInit};
use zeroize::Zeroize;

use crate::scrypt::{Failure, scrypt};

/// `scrypt($password, $salt . $separator, 16384, 8, 1, 64)`, raw: `ScryptModified`'s
/// derived key. The password is what PHP passes, already through `mb_convert_encoding()`.
///
/// # Errors
///
/// [`Failure::False`] when scrypt's 16 MiB cannot be allocated.
pub fn derived_key(password: &[u8], salt: &[u8], separator: &[u8]) -> Result<Vec<u8>, Failure> {
    let mut joined = Vec::with_capacity(salt.len() + separator.len());
    joined.extend_from_slice(salt);
    joined.extend_from_slice(separator);
    scrypt(password, &joined, 16384, 8, 1, 64)
}

/// `openssl_encrypt($data, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, str_repeat("\0", 16))`:
/// `data` XORed with the AES-256 keystream of a big-endian 128-bit counter from zero.
#[must_use]
pub fn aes_256_ctr(key: &[u8; 32], data: &[u8]) -> Vec<u8> {
    let cipher = Aes256::new(key.into());
    let mut out = Vec::with_capacity(data.len());
    for (counter, chunk) in data.chunks(16).enumerate() {
        let mut block = (counter as u128).to_be_bytes().into();
        cipher.encrypt_block(&mut block);
        out.extend(chunk.iter().zip(block.iter()).map(|(d, k)| d ^ k));
        block.zeroize();
    }
    out
}

/// `ScryptModified::hash()`'s value before its `base64_encode()`: the signer key encrypted
/// under the derived key's first 32 bytes.
///
/// # Errors
///
/// As [`derived_key`].
pub fn hash(password: &[u8], salt: &[u8], separator: &[u8], signer_key: &[u8]) -> Result<Vec<u8>, Failure> {
    let mut derived = derived_key(password, salt, separator)?;
    let mut key = [0u8; 32];
    key.copy_from_slice(&derived[..32]);
    let encrypted = aes_256_ctr(&key, signer_key);
    key.zeroize();
    derived.zeroize();
    Ok(encrypted)
}

#[cfg(test)]
mod tests {
    use super::{aes_256_ctr, hash};

    fn b64(s: &str) -> Vec<u8> {
        php_std::encoding::base64_decode(s.as_bytes(), true).unwrap()
    }

    /// Firebase's published test vector for its scrypt variant
    /// (github.com/firebase/scrypt, `README.md`).
    #[test]
    fn firebase_vector() {
        let signer = b64("jxspr8Ki0RYycVU8zykbdLGjFQ3McFUH0uiiTvC8pVMXAn210wjLNmdZJzxUECKbm0QsEmYUSDzZvpjeJ9WmXA==");
        let separator = b64("Bw==");
        let salt = b64("42xEC+ixf3L2lw==");
        let hashed = hash(b"user1password", &salt, &separator, &signer).unwrap();
        assert_eq!(
            php_std::encoding::base64_encode(&hashed),
            "lSrfV15cpx95/sZS2W9c9Kp6i/LVgQNDNC/qzrCnh1SAyZvqmZqAjTdn3aoItz+VHjoZilo78198JAdRuid5lQ=="
        );
    }

    /// Empty input, a partial block, and CTR's symmetry.
    #[test]
    fn empty_and_partial_blocks() {
        assert!(aes_256_ctr(&[0u8; 32], b"").is_empty());
        assert_eq!(aes_256_ctr(&[7u8; 32], b"abc").len(), 3);
        // XOR twice returns the input.
        let once = aes_256_ctr(&[7u8; 32], b"0123456789abcdefXYZ");
        assert_eq!(aes_256_ctr(&[7u8; 32], &once), b"0123456789abcdefXYZ");
    }
}
