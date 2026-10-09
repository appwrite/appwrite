//! The `encrypt` database filter: AES-128-GCM, OpenSSL compatible.
//!
//! Stored values are JSON documents
//! `{"data":<base64>,"method":"aes-128-gcm","iv":<hex>,"tag":<hex>,"version":"1"}`
//! keyed by `_APP_OPENSSL_KEY_V<version>`. PHP's OpenSSL uses the key bytes
//! truncated (or zero-padded) to 16 bytes and no additional data.

use aes_gcm::aead::{AeadInPlace, KeyInit};
use aes_gcm::{Aes128Gcm, Nonce, Tag};
use base64::Engine;
use serde::{Deserialize, Serialize};

fn key_bytes(raw: &str) -> [u8; 16] {
    let mut key = [0u8; 16];
    let bytes = raw.as_bytes();
    let n = bytes.len().min(16);
    key[..n].copy_from_slice(&bytes[..n]);
    key
}

#[derive(Serialize, Deserialize)]
struct Envelope {
    data: String,
    method: String,
    iv: String,
    tag: String,
    version: String,
}

/// Encrypts `plain` into the stored JSON document.
pub fn encrypt(key: &str, plain: &str) -> String {
    let cipher = Aes128Gcm::new(&key_bytes(key).into());
    let mut iv = [0u8; 12];
    utopia_auth::random_bytes(&mut iv);
    let mut buffer = plain.as_bytes().to_vec();
    let tag = cipher
        .encrypt_in_place_detached(Nonce::from_slice(&iv), b"", &mut buffer)
        .expect("AES-GCM encryption cannot fail for in-memory buffers");
    let envelope = Envelope {
        data: base64::engine::general_purpose::STANDARD.encode(&buffer),
        method: "aes-128-gcm".to_owned(),
        iv: hex::encode(iv),
        tag: hex::encode(tag),
        version: "1".to_owned(),
    };
    crate::json::to_string(&envelope)
}

/// Decrypts a stored JSON document. `keys` resolves `version` to key material.
pub fn decrypt(stored: &str, keys: impl Fn(&str) -> Option<String>) -> Option<String> {
    let envelope: Envelope = serde_json::from_str(stored).ok()?;
    if envelope.method != "aes-128-gcm" {
        return None;
    }
    let key = keys(&envelope.version)?;
    let cipher = Aes128Gcm::new(&key_bytes(&key).into());
    let iv = hex::decode(&envelope.iv).ok()?;
    let tag = hex::decode(&envelope.tag).ok()?;
    if iv.len() != 12 || tag.len() != 16 {
        return None;
    }
    let mut buffer = base64::engine::general_purpose::STANDARD.decode(envelope.data.as_bytes()).ok()?;
    cipher.decrypt_in_place_detached(Nonce::from_slice(&iv), b"", &mut buffer, Tag::from_slice(&tag)).ok()?;
    String::from_utf8(buffer).ok()
}

/// Key lookup for `_APP_OPENSSL_KEY_V<version>` (cached at first use).
pub fn env_key(version: &str) -> Option<String> {
    use std::collections::HashMap;
    use std::sync::OnceLock;
    static KEYS: OnceLock<HashMap<String, String>> = OnceLock::new();
    let keys = KEYS.get_or_init(|| {
        std::env::vars()
            .filter_map(|(k, v)| k.strip_prefix("_APP_OPENSSL_KEY_V").map(|ver| (ver.to_owned(), v)))
            .collect()
    });
    keys.get(version).cloned()
}

/// Decrypts with keys from the environment.
pub fn decrypt_env(stored: &str) -> Option<String> {
    decrypt(stored, env_key)
}

/// Encrypts with `_APP_OPENSSL_KEY_V1`.
pub fn encrypt_env(plain: &str) -> String {
    encrypt(&env_key("1").unwrap_or_default(), plain)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn round_trip() {
        let stored = encrypt("your-secret-key", "hello/world");
        assert!(stored.contains("\"method\":\"aes-128-gcm\""));
        assert_eq!(decrypt(&stored, |_| Some("your-secret-key".to_owned())).as_deref(), Some("hello/world"));
        assert!(decrypt(&stored, |_| Some("other-key".to_owned())).is_none());
    }
}
