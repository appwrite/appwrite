//! Webhook signatures.

use hmac::{Hmac, Mac};
use sha2::Sha256;

/// `hash_hmac('sha256', $payload, $key)`: the lowercase hex digest.
pub(crate) fn hmac_sha256_hex(payload: &[u8], key: &[u8]) -> String {
    let mut mac = Hmac::<Sha256>::new_from_slice(key).expect("HMAC takes keys of any length");
    mac.update(payload);
    hex(&mac.finalize().into_bytes())
}

/// Lowercase hex of bytes.
pub(crate) fn hex(bytes: &[u8]) -> String {
    php_std::encoding::bin2hex(bytes)
}

/// `hash_equals($known, $user)`: equal lengths and bytes, compared in time
/// independent of where they differ.
pub(crate) fn hash_equals(known: &[u8], user: &[u8]) -> bool {
    if known.len() != user.len() {
        return false;
    }
    known.iter().zip(user).fold(0u8, |acc, (a, b)| acc | (a ^ b)) == 0
}

/// Whether `signature` is `prefix` followed by the hex HMAC-SHA256 of
/// `payload` with `key`.
pub(crate) fn hmac_matches(prefix: &[u8], payload: &[u8], signature: &[u8], key: &[u8]) -> bool {
    let mut expected = prefix.to_vec();
    expected.extend_from_slice(hmac_sha256_hex(payload, key).as_bytes());
    hash_equals(&expected, signature)
}
