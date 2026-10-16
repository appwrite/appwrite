//! `Database::addFilter` registrations. Rust port of
//! `app/init/database/filters.php`'s `encrypt` filter -- the one PHP filter
//! Users needs bit-compatibility with, since `users.password` and
//! `keys.secret` both declare `filters: ['encrypt']` in
//! `app/config/collections/common.php` / `platform.php`. Every other PHP
//! filter Users touches (`json`, `datetime`, the `subQuery*` family) is
//! either already a `utopia-database` builtin or -- for `subQuery*`, which
//! need a live `Database` + `Document` the filter-fn signature here can't
//! carry -- handled by hand in `appwrite-platform` (see
//! `crates-appwrite/platform/src/state.rs`'s project/key loading).
//!
//! PHP's filter is `openssl_encrypt($value, 'aes-128-gcm', $key, 0, $iv,
//! $tag)`: `$options = 0` base64-encodes the ciphertext (tag kept out of
//! band via the by-ref `$tag` param), and `openssl_encrypt`/`_decrypt`
//! silently zero-pad or truncate `$key` to the cipher's key length (16
//! bytes for `aes-128-*`) rather than erroring on the wrong length --
//! `pad_key` (private -- see its doc comment) replicates that so a plain
//! `_APP_OPENSSL_KEY_V1` string (e.g. the `.env` default `your-secret-key`,
//! 15 bytes) matches.

use std::sync::Arc;

use aes_gcm::aead::{Aead, KeyInit, Payload};
use aes_gcm::{Aes128Gcm, Key, Nonce};
use base64::engine::general_purpose::STANDARD as BASE64;
use base64::Engine;
use rand::RngCore;
use serde_json::{json, Value};
use utopia_database::adapter::Memory;
use utopia_database::value::AttrValue;
use utopia_database::Database;

/// PHP `Appwrite\OpenSSL\OpenSSL::CIPHER_AES_128_GCM`.
const CIPHER_AES_128_GCM: &str = "aes-128-gcm";
/// PHP `openssl_cipher_iv_length('aes-128-gcm')`.
const IV_LEN: usize = 12;
/// AES-128 key length; PHP pads/truncates `$key` to this many bytes.
const KEY_LEN: usize = 16;

/// Register every filter this module knows about. Idempotent (re-inserts
/// the same entries into `utopia_database`'s process-wide filter registry),
/// safe to call from every `AppwriteState` constructor.
pub fn register() {
    // `Database::addFilter` is a `Database<A>` associated function, not a
    // method -- it never reads/writes the adapter, so any concrete `A`
    // works as the call-site type parameter.
    Database::<Memory>::add_filter("encrypt", Arc::new(encode), Arc::new(decode));
}

/// PHP `System::getEnv('_APP_OPENSSL_KEY_V' . $version)`, zero-padded or
/// truncated to `KEY_LEN` bytes the way `openssl_encrypt`/`openssl_decrypt`
/// silently do for a too-short/too-long key. `None` when unset (PHP would
/// pass an empty string, which still round-trips via the same padding, but
/// callers here prefer to skip encryption entirely over using an all-zero
/// key).
fn openssl_key(version: u32) -> Option<Key<Aes128Gcm>> {
    let raw = std::env::var(format!("_APP_OPENSSL_KEY_V{version}")).ok()?;
    pad_key(&raw)
}

/// [`openssl_key`]'s padding rule, factored out so tests can exercise it
/// without touching process env vars.
fn pad_key(raw: &str) -> Option<Key<Aes128Gcm>> {
    if raw.is_empty() {
        return None;
    }
    let mut bytes = [0u8; KEY_LEN];
    let take = raw.len().min(KEY_LEN);
    bytes[..take].copy_from_slice(&raw.as_bytes()[..take]);
    Some(bytes.into())
}

/// PHP `encrypt` filter's encode half.
fn encode(value: &AttrValue) -> AttrValue {
    let Some(plaintext) = value.as_str() else {
        return value.clone();
    };
    let Some(key) = openssl_key(1) else {
        // No `_APP_OPENSSL_KEY_V1` configured: leave the value untouched
        // rather than silently persisting garbage ciphertext.
        return value.clone();
    };
    encode_with_key(plaintext, &key).unwrap_or_else(|| value.clone())
}

/// PHP `encrypt` filter's decode half.
fn decode(value: &AttrValue) -> AttrValue {
    let Some(raw) = value.as_str() else {
        return value.clone();
    };
    decode_envelope(raw).unwrap_or_else(|| value.clone())
}

fn encode_with_key(plaintext: &str, key: &Key<Aes128Gcm>) -> Option<AttrValue> {
    let mut iv = [0u8; IV_LEN];
    rand::thread_rng().fill_bytes(&mut iv);
    let cipher = Aes128Gcm::new(key);
    let sealed = cipher
        .encrypt(Nonce::from_slice(&iv), Payload::from(plaintext.as_bytes()))
        .ok()?;
    // `aes-gcm`'s `encrypt` appends the 16-byte tag to the ciphertext; PHP's
    // `openssl_encrypt($value, ..., $iv, $tag)` keeps them separate.
    let tag_offset = sealed.len().saturating_sub(16);
    let (ciphertext, tag) = sealed.split_at(tag_offset);

    Some(AttrValue::from(
        json!({
            "data": BASE64.encode(ciphertext),
            "method": CIPHER_AES_128_GCM,
            "iv": hex::encode(iv),
            "tag": hex::encode(tag),
            "version": "1",
        })
        .to_string(),
    ))
}

fn decode_envelope(raw: &str) -> Option<AttrValue> {
    let (data, iv_hex, tag_hex, version) = parse_envelope(raw)?;
    let key = openssl_key(version)?;
    decode_with_key(&data, &iv_hex, &tag_hex, &key)
}

/// Split out so tests can supply a key directly instead of round-tripping
/// through `_APP_OPENSSL_KEY_V{version}`.
fn parse_envelope(raw: &str) -> Option<(String, String, String, u32)> {
    let Value::Object(envelope) = serde_json::from_str::<Value>(raw).ok()? else {
        return None;
    };
    let data = envelope.get("data").and_then(Value::as_str)?.to_owned();
    let iv_hex = envelope.get("iv").and_then(Value::as_str)?.to_owned();
    let tag_hex = envelope.get("tag").and_then(Value::as_str)?.to_owned();
    let version = envelope
        .get("version")
        .and_then(Value::as_str)?
        .parse()
        .ok()?;
    Some((data, iv_hex, tag_hex, version))
}

fn decode_with_key(
    data: &str,
    iv_hex: &str,
    tag_hex: &str,
    key: &Key<Aes128Gcm>,
) -> Option<AttrValue> {
    let ciphertext = BASE64.decode(data).ok()?;
    let iv = hex::decode(iv_hex).ok()?;
    let tag = hex::decode(tag_hex).ok()?;
    if iv.len() != IV_LEN {
        return None;
    }

    let mut sealed = ciphertext;
    sealed.extend_from_slice(&tag);
    let cipher = Aes128Gcm::new(key);
    let plaintext = cipher
        .decrypt(Nonce::from_slice(&iv), Payload::from(sealed.as_slice()))
        .ok()?;
    String::from_utf8(plaintext).ok().map(AttrValue::from)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn decode_str(raw: &str, key: &Key<Aes128Gcm>) -> Option<AttrValue> {
        let (data, iv_hex, tag_hex, _version) = parse_envelope(raw)?;
        decode_with_key(&data, &iv_hex, &tag_hex, key)
    }

    #[test]
    fn round_trips_short_key_like_php_env_default() {
        let key = pad_key("your-secret-key").expect("non-empty key");
        let plaintext = "s3cr3t-password-hash";
        let encoded = encode_with_key(plaintext, &key).expect("encode");
        assert_ne!(encoded.as_str(), Some(plaintext));
        let raw = encoded.as_str().unwrap();
        let decoded = decode_str(raw, &key).expect("decode");
        assert_eq!(decoded.as_str(), Some(plaintext));
    }

    #[test]
    fn decode_is_noop_for_plain_values() {
        assert!(parse_envelope("not-an-envelope").is_none());
        let value = AttrValue::from("not-an-envelope");
        assert_eq!(decode(&value).as_str(), value.as_str());
    }

    #[test]
    fn different_ivs_produce_different_ciphertext() {
        let key = pad_key("your-secret-key").expect("non-empty key");
        let a = encode_with_key("same-password", &key).unwrap();
        let b = encode_with_key("same-password", &key).unwrap();
        assert_ne!(a.as_str(), b.as_str());
        assert_eq!(
            decode_str(a.as_str().unwrap(), &key).unwrap().as_str(),
            decode_str(b.as_str().unwrap(), &key).unwrap().as_str()
        );
    }

    #[test]
    fn pad_key_matches_php_openssl_truncation_and_padding() {
        // 15-byte key (`.env` default) zero-pads to 16.
        let short = pad_key("your-secret-key").unwrap();
        assert_eq!(short.len(), KEY_LEN);
        assert_eq!(short.as_slice()[KEY_LEN - 1], 0);
        // Longer keys truncate to the first 16 bytes.
        let long = pad_key("0123456789abcdefextra-bytes-here").unwrap();
        assert_eq!(long.as_slice(), b"0123456789abcdef");
        assert!(pad_key("").is_none());
    }
}
