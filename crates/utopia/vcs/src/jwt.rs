//! The app JWTs GitHub (RS256) and Origin (EdDSA) authenticate with.

use openssl::hash::MessageDigest;
use openssl::pkey::{PKey, Private};
use openssl::sign::Signer;
use php_std::Value;

use crate::error::{Error, Result};

/// `rtrim(strtr(base64_encode($data), '+/', '-_'), '=')`.
pub(crate) fn base64url(data: &[u8]) -> String {
    php_std::encoding::base64_encode(data).replace('+', "-").replace('/', "_").trim_end_matches('=').to_owned()
}

/// `base64_decode(strtr($data, '-_', '+/'), true)` after padding to a
/// multiple of four: `""` where PHP's strict decode fails (Origin's
/// `base64UrlDecode()` casts its `false` to a string).
pub(crate) fn base64url_decode(data: &[u8]) -> Vec<u8> {
    let mut padded: Vec<u8> = data
        .iter()
        .map(|b| match b {
            b'-' => b'+',
            b'_' => b'/',
            other => *other,
        })
        .collect();
    let remainder = padded.len() % 4;
    if remainder > 0 {
        padded.extend(std::iter::repeat_n(b'=', 4 - remainder));
    }
    php_std::encoding::base64_decode(&padded, true).unwrap_or_default()
}

/// `openssl_pkey_get_private($key)`: a PEM private key, or the file a
/// `file://` path names. `None` where PHP returns `false`.
pub(crate) fn private_key(key: &[u8]) -> Option<PKey<Private>> {
    let pem = match key.strip_prefix(b"file://") {
        Some(path) => std::fs::read(String::from_utf8_lossy(path).as_ref()).ok()?,
        None => key.to_vec(),
    };
    PKey::private_key_from_pem_passphrase(&pem, b"").ok()
}

/// adhocore/jwt's `JWT::encode()` with an RS256 key: header
/// `{"typ":"JWT","alg":"RS256"}`, the claims as `json_encode()` with
/// `JSON_UNESCAPED_SLASHES`, signed by `openssl_sign()` with SHA-256.
pub(crate) fn rs256(key: &PKey<Private>, claims: &Value) -> Result<String> {
    let header = base64url(br#"{"typ":"JWT","alg":"RS256"}"#);
    let json = php_std::json::encode(claims, php_std::json::Flags::UNESCAPED_SLASHES, 512).map_err(|e| {
        Error::Engine(php_std::EngineError::new("Ahc\\Jwt\\JWTException", format!("JSON failed: {}", e.message())))
    })?;
    let input = format!("{header}.{}", base64url(json.as_bytes()));
    let signature = Signer::new(MessageDigest::sha256(), key)
        .and_then(|mut signer| {
            signer.update(input.as_bytes())?;
            signer.sign_to_vec()
        })
        .map_err(|e| Error::exception(format!("openssl_sign(): {e}"), 0))?;
    Ok(format!("{input}.{}", base64url(&signature)))
}

/// An Ed25519 key pair from its 32-byte seed.
pub(crate) fn ed25519_from_seed(seed: &[u8]) -> Option<ring::signature::Ed25519KeyPair> {
    ring::signature::Ed25519KeyPair::from_seed_unchecked(seed).ok()
}

/// `sodium_crypto_sign_detached($message, $secretKey)` with a 64-byte
/// libsodium secret key (seed then public key).
pub(crate) fn ed25519_sign(secret_key: &[u8], message: &[u8]) -> Option<Vec<u8>> {
    let pair = ring::signature::Ed25519KeyPair::from_seed_and_public_key(&secret_key[..32], &secret_key[32..]).ok()?;
    Some(pair.sign(message).as_ref().to_vec())
}

/// `sodium_crypto_sign_verify_detached($signature, $message, $publicKey)`.
pub(crate) fn ed25519_verify(public_key: &[u8], message: &[u8], signature: &[u8]) -> bool {
    ring::signature::UnparsedPublicKey::new(&ring::signature::ED25519, public_key).verify(message, signature).is_ok()
}

/// `sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed))`:
/// the seed followed by its public key.
pub(crate) fn ed25519_secret_key(seed: &[u8]) -> Option<Vec<u8>> {
    use ring::signature::KeyPair as _;
    let pair = ed25519_from_seed(seed)?;
    let mut secret = seed.to_vec();
    secret.extend_from_slice(pair.public_key().as_ref());
    Some(secret)
}
