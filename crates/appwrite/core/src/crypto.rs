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
    // `json_encode(..., JSON_UNESCAPED_SLASHES)`; every value is ASCII.
    serde_json::to_string(&envelope).expect("the envelope serializes")
}

/// Decrypts a stored JSON document. `keys` resolves `version` to key material.
pub fn decrypt(stored: &str, keys: impl Fn(&str) -> Option<String>) -> Option<String> {
    let envelope: Envelope = serde_json::from_str(stored).ok()?;
    if envelope.method != "aes-128-gcm" {
        return None;
    }
    let key = keys(&envelope.version)?;
    let cipher = cipher(&key);
    let iv = hex::decode(&envelope.iv).ok()?;
    let tag = hex::decode(&envelope.tag).ok()?;
    if iv.len() != 12 || tag.len() != 16 {
        return None;
    }
    let mut buffer = base64::engine::general_purpose::STANDARD.decode(envelope.data.as_bytes()).ok()?;
    cipher.decrypt_in_place_detached(Nonce::from_slice(&iv), b"", &mut buffer, Tag::from_slice(&tag)).ok()?;
    String::from_utf8(buffer).ok()
}

/// `openssl_encrypt($plain, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag)`
/// with a random IV: the raw ciphertext, the IV and the tag.
pub fn seal(key: &str, plain: &[u8]) -> (Vec<u8>, [u8; 12], [u8; 16]) {
    let mut iv = [0u8; 12];
    utopia_auth::random_bytes(&mut iv);
    let mut buffer = plain.to_vec();
    let tag = cipher(key)
        .encrypt_in_place_detached(Nonce::from_slice(&iv), b"", &mut buffer)
        .expect("AES-GCM encryption cannot fail for in-memory buffers");
    let mut tag_bytes = [0u8; 16];
    tag_bytes.copy_from_slice(&tag);
    (buffer, iv, tag_bytes)
}

/// `openssl_decrypt()` of [`seal`]'s output; `None` when it does not open.
pub fn open(key: &str, data: &[u8], iv: &[u8], tag: &[u8]) -> Option<Vec<u8>> {
    if iv.len() != 12 || tag.len() != 16 {
        return None;
    }
    let mut buffer = data.to_vec();
    cipher(key).decrypt_in_place_detached(Nonce::from_slice(iv), b"", &mut buffer, Tag::from_slice(tag)).ok()?;
    Some(buffer)
}

/// `openssl_encrypt($plain, 'aes-128-gcm', $key, 0, $iv, $tag)` with a
/// random IV: options `0` gives the ciphertext base64-encoded, as Storage
/// writes encrypted files (`Buckets/Files/Create.php`).
pub fn seal_encoded(key: &str, plain: &[u8]) -> (Vec<u8>, [u8; 12], [u8; 16]) {
    let (data, iv, tag) = seal(key, plain);
    (base64::engine::general_purpose::STANDARD.encode(data).into_bytes(), iv, tag)
}

/// `openssl_decrypt($data, 'aes-128-gcm', $key, 0, $iv, $tag)`: [`open`] of
/// base64-encoded ciphertext ([`seal_encoded`]'s output). OpenSSL's decoder
/// skips line breaks.
pub fn open_encoded(key: &str, data: &[u8], iv: &[u8], tag: &[u8]) -> Option<Vec<u8>> {
    let decoded = if data.iter().any(|b| matches!(b, b'\n' | b'\r')) {
        let compact: Vec<u8> = data.iter().copied().filter(|b| !matches!(b, b'\n' | b'\r')).collect();
        base64::engine::general_purpose::STANDARD.decode(compact)
    } else {
        base64::engine::general_purpose::STANDARD.decode(data)
    };
    open(key, &decoded.ok()?, iv, tag)
}

/// The cipher of `key`, kept: expanding a key costs more than decrypting
/// the short values stored with it (every listed user's password).
fn cipher(key: &str) -> Aes128Gcm {
    use std::collections::HashMap;
    use std::sync::{Mutex, OnceLock};
    static CIPHERS: OnceLock<Mutex<HashMap<[u8; 16], Aes128Gcm>>> = OnceLock::new();
    let bytes = key_bytes(key);
    let mut ciphers = CIPHERS.get_or_init(Default::default).lock().unwrap_or_else(|e| e.into_inner());
    if ciphers.len() > 16 {
        ciphers.clear();
    }
    ciphers.entry(bytes).or_insert_with(|| Aes128Gcm::new(&bytes.into())).clone()
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

    #[test]
    fn envelope_keeps_slashes_unescaped() {
        // PHP writes it with `JSON_UNESCAPED_SLASHES`; base64 data has `/` in
        // about one envelope in four for a short value, so try many.
        for _ in 0..200 {
            let stored = encrypt("your-secret-key", "a value long enough for several base64 groups");
            assert!(!stored.contains("\\/"), "{stored}");
            assert!(stored.starts_with("{\"data\":\""), "{stored}");
        }
    }

    /// `openssl_encrypt('hello appwrite storage', 'aes-128-gcm', 'your-secret-key', 0, '0123456789ab', $tag)`
    /// in PHP: the format of an encrypted Storage file.
    const PHP_FILE: &[u8] = b"yQlXnzXsDqUCvH1HemkVjNzVMip7lw==";
    const PHP_TAG: &str = "5685dd95b028fa4ebec062ae8073219e";

    #[test]
    fn opens_php_encoded_files() {
        let tag = hex::decode(PHP_TAG).unwrap();
        let plain = open_encoded("your-secret-key", PHP_FILE, b"0123456789ab", &tag);
        assert_eq!(plain.as_deref(), Some(&b"hello appwrite storage"[..]));
        // Raw ciphertext is not what PHP's options `0` reads.
        assert!(open("your-secret-key", PHP_FILE, b"0123456789ab", &tag).is_none());
    }

    #[test]
    fn seals_files_as_php_does() {
        let (data, iv, tag) = seal_encoded("your-secret-key", b"hello appwrite storage");
        assert!(data.iter().all(|b| b.is_ascii_alphanumeric() || matches!(b, b'+' | b'/' | b'=')));
        assert_eq!(open_encoded("your-secret-key", &data, &iv, &tag).as_deref(), Some(&b"hello appwrite storage"[..]));
        // Same IV, same output as PHP.
        let mut buffer = b"hello appwrite storage".to_vec();
        let sealed = cipher("your-secret-key")
            .encrypt_in_place_detached(Nonce::from_slice(b"0123456789ab"), b"", &mut buffer)
            .unwrap();
        assert_eq!(base64::engine::general_purpose::STANDARD.encode(&buffer).as_bytes(), PHP_FILE);
        assert_eq!(hex::encode(sealed), PHP_TAG);
    }
}
