//! RSA keys as PHP's OpenSSL functions read and write them (PEM).

use php_std::encoding::base64_decode;
use rsa::pkcs1::{DecodeRsaPrivateKey, DecodeRsaPublicKey};
use rsa::pkcs1v15::{Signature, SigningKey, VerifyingKey};
use rsa::pkcs8::{DecodePrivateKey, DecodePublicKey, EncodePrivateKey, EncodePublicKey, LineEnding};
use rsa::signature::{SignatureEncoding, Signer, Verifier as _};
use rsa::traits::PublicKeyParts;
use rsa::{RsaPrivateKey, RsaPublicKey};
use sha2::Sha256;

use crate::Error;

const RSA_OID: &str = "1.2.840.113549.1.1.1";

/// A PEM-encoded RSA key pair, as `Issuers\Asymmetric::generateKeyPair()` returns it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct KeyPair {
    /// PKCS#8 `PRIVATE KEY` PEM.
    pub private_key: String,
    /// SubjectPublicKeyInfo `PUBLIC KEY` PEM.
    pub public_key: String,
}

/// `Asymmetric::generateKeyPair($bits)`: a fresh RSA key pair.
pub fn generate_key_pair(bits: i64) -> Result<KeyPair, Error> {
    let failed = || Error::Exception("Unable to generate an RSA key pair".into());
    // OpenSSL refuses keys below 512 bits (PHP warns and returns false).
    let bits = usize::try_from(bits).ok().filter(|b| *b >= 512).ok_or_else(failed)?;
    let key = RsaPrivateKey::new(&mut rsa::rand_core::OsRng, bits).map_err(|_| failed())?;
    let private_key =
        key.to_pkcs8_pem(LineEnding::LF).map_err(|_| Error::Exception("Unable to export the private key".into()))?;
    let public_key = key
        .to_public_key()
        .to_public_key_pem(LineEnding::LF)
        .map_err(|_| Error::Exception("Unable to export the public key".into()))?;
    Ok(KeyPair { private_key: private_key.to_string(), public_key })
}

/// The DER body of the first PEM block labelled `label`, as OpenSSL's PEM reader finds it.
fn pem_block(pem: &[u8], label: &str) -> Option<Vec<u8>> {
    let begin = format!("-----BEGIN {label}-----");
    let end = format!("-----END {label}-----");
    let start = find(pem, begin.as_bytes())? + begin.len();
    let stop = start + find(&pem[start..], end.as_bytes())?;
    base64_decode(&pem[start..stop], true).filter(|d| !d.is_empty())
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w == needle)
}

/// A public key `openssl_pkey_get_public()` accepts.
pub(crate) enum Public {
    Rsa(RsaPublicKey),
    /// A key of another type (EC, Ed25519, ...).
    Other,
}

/// `openssl_pkey_get_public($pem)`: `None` where PHP returns `false`.
pub(crate) fn public_key(pem: &[u8]) -> Option<Public> {
    if let Some(der) = pem_block(pem, "PUBLIC KEY") {
        let spki = rsa::pkcs8::spki::SubjectPublicKeyInfoRef::try_from(der.as_slice()).ok()?;
        if spki.algorithm.oid.to_string() != RSA_OID {
            return Some(Public::Other);
        }
        return RsaPublicKey::from_public_key_der(&der).ok().map(Public::Rsa);
    }
    if let Some(der) = pem_block(pem, "RSA PUBLIC KEY") {
        return RsaPublicKey::from_pkcs1_der(&der).ok().map(Public::Rsa);
    }
    None
}

/// `openssl_pkey_get_private($pem)` for RSA keys.
pub(crate) fn private_key(pem: &[u8]) -> Option<RsaPrivateKey> {
    if let Some(der) = pem_block(pem, "PRIVATE KEY") {
        return RsaPrivateKey::from_pkcs8_der(&der).ok();
    }
    if let Some(der) = pem_block(pem, "RSA PRIVATE KEY") {
        return RsaPrivateKey::from_pkcs1_der(&der).ok();
    }
    None
}

/// The raw RSA parameters PHP's `openssl_pkey_get_details()['rsa']` reports.
pub struct Rsa;

impl Rsa {
    /// The big-endian modulus `n`, without leading zeros.
    pub fn modulus(key: &RsaPublicKey) -> Vec<u8> {
        key.n().to_bytes_be()
    }

    /// The big-endian public exponent `e`.
    pub fn exponent(key: &RsaPublicKey) -> Vec<u8> {
        key.e().to_bytes_be()
    }
}

/// `openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)`: RSASSA-PKCS1-v1_5 with SHA-256.
pub(crate) fn sign(key: RsaPrivateKey, input: &[u8]) -> Result<Vec<u8>, Error> {
    let signer = SigningKey::<Sha256>::new(key);
    signer.try_sign(input).map(|s| s.to_vec()).map_err(|_| Error::Exception("Unable to sign the token".into()))
}

/// `openssl_verify(...) === 1`.
pub(crate) fn verify(key: RsaPublicKey, input: &[u8], signature: &[u8]) -> bool {
    let Ok(signature) = Signature::try_from(signature) else {
        return false;
    };
    VerifyingKey::<Sha256>::new(key).verify(input, &signature).is_ok()
}
