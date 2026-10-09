//! Secret generation and token hashing (`Utopia\Auth\Proofs`).

use sha2::{Digest, Sha256};

use crate::random_bytes;

/// `Proofs\Token::generate()`: `length` lowercase hex characters.
pub fn token(length: usize) -> String {
    let bytes = length.div_ceil(2).max(1);
    let mut buf = vec![0u8; bytes];
    random_bytes(&mut buf);
    let mut out = hex::encode(buf);
    out.truncate(length);
    out
}

/// `Proofs\Token` hash: lowercase hex SHA-256.
pub fn sha256(value: &str) -> String {
    hex::encode(Sha256::digest(value.as_bytes()))
}

/// `Proofs\Password::generate()`: 16 chars from a symbol-rich alphabet.
pub fn password() -> String {
    const CHARSET: &[u8] = b"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|;:,.<>?";
    let mut buf = [0u8; 16];
    random_bytes(&mut buf);
    buf.iter().map(|b| CHARSET[*b as usize % CHARSET.len()] as char).collect()
}

/// `Type::generateBackupCodes()`: six 10-character hex codes.
pub fn backup_codes() -> Vec<String> {
    (0..6).map(|_| token(10)).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn tokens() {
        assert_eq!(token(256).len(), 256);
        assert_eq!(token(15).len(), 15);
        assert!(token(6).bytes().all(|b| b.is_ascii_hexdigit()));
        assert_eq!(sha256("abc"), "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad");
        assert_eq!(backup_codes().len(), 6);
    }
}
