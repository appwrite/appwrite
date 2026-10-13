//! JSON Web Tokens as the push adapters sign them: ES256 for APNs,
//! RS256 for FCM's OAuth2 assertion.
//!
//! PHP: `Utopia\Messaging\Helpers\JWT`.

use hmac::{Hmac, Mac};
use openssl::hash::MessageDigest;
use openssl::pkey::PKey;
use openssl::sign::Signer;
use php_std::Value;
use php_std::json::{self, Flags};

use crate::error::Error;

/// A signing algorithm.
#[derive(Clone, Copy)]
enum Algorithm {
    Openssl(fn() -> MessageDigest, Option<usize>),
    Hmac(fn(&[u8], &[u8]) -> Vec<u8>),
}

fn hmac256(key: &[u8], data: &[u8]) -> Vec<u8> {
    let mut mac = Hmac::<sha2::Sha256>::new_from_slice(key).expect("HMAC takes any key length");
    mac.update(data);
    mac.finalize().into_bytes().to_vec()
}

fn hmac384(key: &[u8], data: &[u8]) -> Vec<u8> {
    let mut mac = Hmac::<sha2::Sha384>::new_from_slice(key).expect("HMAC takes any key length");
    mac.update(data);
    mac.finalize().into_bytes().to_vec()
}

fn hmac512(key: &[u8], data: &[u8]) -> Vec<u8> {
    let mut mac = Hmac::<sha2::Sha512>::new_from_slice(key).expect("HMAC takes any key length");
    mac.update(data);
    mac.finalize().into_bytes().to_vec()
}

fn algorithm(name: &str) -> Option<Algorithm> {
    Some(match name {
        "ES384" => Algorithm::Openssl(MessageDigest::sha384, Some(384)),
        "ES256" | "ES256K" => Algorithm::Openssl(MessageDigest::sha256, Some(256)),
        "RS256" => Algorithm::Openssl(MessageDigest::sha256, None),
        "RS384" => Algorithm::Openssl(MessageDigest::sha384, None),
        "RS512" => Algorithm::Openssl(MessageDigest::sha512, None),
        "HS256" => Algorithm::Hmac(hmac256),
        "HS384" => Algorithm::Hmac(hmac384),
        "HS512" => Algorithm::Hmac(hmac512),
        _ => return None,
    })
}

/// A token of `payload` (a PHP array, encoded with `JSON_UNESCAPED_SLASHES`)
/// signed with `key` (a PEM private key, or the secret of an `HS*`
/// algorithm) by `algorithm`: `ES256`, `ES256K`, `ES384`, `RS256`, `RS384`,
/// `RS512`, `HS256`, `HS384` or `HS512`. `key_id` becomes the header's
/// `kid`.
///
/// Fails with [`Error::Exception`] for another algorithm (`Algorithm not
/// supported`) or a key OpenSSL cannot sign with (`OpenSSL sign failed for
/// JWT`).
///
/// PHP: `JWT::encode()`.
pub fn encode(payload: &Value, key: &[u8], algorithm_name: &str, key_id: Option<&str>) -> Result<String, Error> {
    let mut header = vec![("typ", Value::from("JWT")), ("alg", Value::from(algorithm_name))];
    if let Some(kid) = key_id {
        header.push(("kid", Value::from(kid)));
    }
    let header = crate::values::map(header);
    let encode = |v: &Value| json::encode(v, Flags::UNESCAPED_SLASHES, json::DEFAULT_DEPTH).unwrap_or_default();
    let mut token = format!("{}.{}", safe_base64(encode(&header).as_bytes()), safe_base64(encode(payload).as_bytes()));
    let signature = sign(token.as_bytes(), key, algorithm_name)?;
    token.push('.');
    token.push_str(&safe_base64(&signature));
    Ok(token)
}

fn sign(data: &[u8], key: &[u8], name: &str) -> Result<Vec<u8>, Error> {
    let unsupported = || Error::Exception("Algorithm not supported".into());
    match algorithm(name).ok_or_else(unsupported)? {
        Algorithm::Hmac(mac) => Ok(mac(key, data)),
        Algorithm::Openssl(digest, curve) => {
            let failed = || Error::Exception("OpenSSL sign failed for JWT".into());
            let key = private_key(key).ok_or_else(failed)?;
            let mut signer = Signer::new(digest(), &key).map_err(|_| failed())?;
            signer.update(data).map_err(|_| failed())?;
            let signature = signer.sign_to_vec().map_err(|_| failed())?;
            Ok(match curve {
                Some(bits) => signature_from_der(&signature, bits),
                None => signature,
            })
        }
    }
}

/// The private key PHP's `openssl_sign()` reads from a string: PEM, or a
/// `file://` path to one.
fn private_key(key: &[u8]) -> Option<PKey<openssl::pkey::Private>> {
    let pem = match key.strip_prefix(b"file://") {
        Some(path) => std::fs::read(String::from_utf8_lossy(path).as_ref()).ok()?,
        None => key.to_vec(),
    };
    PKey::private_key_from_pem(&pem).ok()
}

/// An ECDSA signature in DER as JOSE's fixed-width `r || s`.
fn signature_from_der(der: &[u8], key_size: usize) -> Vec<u8> {
    let (offset, _) = read_der(der, 0);
    let (offset, r) = read_der(der, offset);
    let (_, s) = read_der(der, offset);
    let width = key_size / 8;
    let pad = |v: Option<Vec<u8>>| {
        let v = v.unwrap_or_default();
        let trimmed: Vec<u8> = v.iter().copied().skip_while(|&b| b == 0).collect();
        let mut out = vec![0u8; width.saturating_sub(trimmed.len())];
        out.extend(trimmed);
        out
    };
    let mut out = pad(r);
    out.extend(pad(s));
    out
}

/// One DER object at `offset`: the offset after it, and its value (`None`
/// for a constructed one, whose contents follow).
fn read_der(der: &[u8], offset: usize) -> (usize, Option<Vec<u8>>) {
    let mut pos = offset;
    let at = |i: usize| der.get(i).copied().unwrap_or(0);
    let constructed = (at(pos) >> 5) & 0x01;
    let kind = at(pos) & 0x1F;
    pos += 1;
    let mut len = usize::from(at(pos));
    pos += 1;
    if len & 0x80 != 0 {
        let mut n = len & 0x1F;
        len = 0;
        while n > 0 && pos < der.len() {
            len = (len << 8) | usize::from(at(pos));
            pos += 1;
            n -= 1;
        }
    }
    let slice = |from: usize, length: usize| der.get(from..(from + length).min(der.len())).unwrap_or_default().to_vec();
    if kind == 0x03 {
        pos += 1;
        let data = slice(pos, len.saturating_sub(1));
        pos += len.saturating_sub(1);
        (pos, Some(data))
    } else if constructed == 0 {
        let data = slice(pos, len);
        pos += len;
        (pos, Some(data))
    } else {
        (pos, None)
    }
}

/// URL-safe base64 without padding.
fn safe_base64(input: &[u8]) -> String {
    php_std::encoding::base64_encode(input).replace('+', "-").replace('/', "_").replace('=', "")
}
