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

/// The PEM blocks of `input` as OpenSSL's PEM reader sees them: a
/// `-----BEGIN <label>-----` line, base64 lines, a `-----END ` line. A block
/// whose body is not plain base64 (encrypted keys carry headers) has no DER.
fn pem_blocks(input: &[u8]) -> Vec<(&[u8], Option<Vec<u8>>)> {
    let mut lines = input.split(|b| *b == b'\n').map(|l| l.strip_suffix(b"\r").unwrap_or(l));
    let mut blocks = Vec::new();
    while let Some(line) = lines.next() {
        let Some(label) = line.strip_prefix(b"-----BEGIN ").and_then(|l| l.strip_suffix(b"-----")) else {
            continue;
        };
        let mut body = Vec::new();
        let mut valid = true;
        let mut ended = false;
        for line in lines.by_ref() {
            if line.starts_with(b"-----END ") {
                ended = line == [b"-----END ", label, b"-----"].concat().as_slice();
                break;
            }
            if !line.iter().all(|c| c.is_ascii_alphanumeric() || matches!(c, b'+' | b'/' | b'=')) {
                valid = false;
            }
            body.extend_from_slice(line);
        }
        let der = (valid && ended).then(|| base64_decode(&body, true)).flatten().filter(|d| !d.is_empty());
        blocks.push((label, der));
    }
    blocks
}

/// One DER element: its tag, its contents and what follows it.
fn der_element(input: &[u8]) -> Option<(u8, &[u8], &[u8])> {
    let (&tag, rest) = input.split_first()?;
    let (&first, rest) = rest.split_first()?;
    let (len, rest) = if first & 0x80 == 0 {
        (usize::from(first), rest)
    } else {
        let n = usize::from(first & 0x7f);
        if n == 0 || n > 4 || rest.len() < n {
            return None;
        }
        (rest[..n].iter().fold(0usize, |acc, b| (acc << 8) | usize::from(*b)), &rest[n..])
    };
    (rest.len() >= len).then(|| (tag, &rest[..len], &rest[len..]))
}

/// The SubjectPublicKeyInfo (DER) of an X.509 certificate.
fn certificate_spki(der: &[u8]) -> Option<&[u8]> {
    let (0x30, certificate, _) = der_element(der)? else {
        return None;
    };
    let (0x30, tbs, _) = der_element(certificate)? else {
        return None;
    };
    let mut rest = tbs;
    // [0] version (optional), serial, signature algorithm, issuer, validity, subject.
    let (tag, _, after) = der_element(rest)?;
    if tag == 0xa0 {
        rest = after;
    }
    for _ in 0..5 {
        rest = der_element(rest)?.2;
    }
    let (0x30, _, after) = der_element(rest)? else {
        return None;
    };
    Some(&rest[..rest.len() - after.len()])
}

/// A public key `openssl_pkey_get_public()` accepts.
pub(crate) enum Public {
    Rsa(RsaPublicKey),
    /// A key of another type (EC, Ed25519, ...).
    Other,
}

fn spki_key(der: &[u8]) -> Option<Public> {
    let spki = rsa::pkcs8::spki::SubjectPublicKeyInfoRef::try_from(der).ok()?;
    if spki.algorithm.oid.to_string() != RSA_OID {
        return Some(Public::Other);
    }
    RsaPublicKey::from_public_key_der(der).ok().map(Public::Rsa)
}

/// `openssl_pkey_get_public($pem)`: the key of the first PEM certificate,
/// else of the first PEM public key (SubjectPublicKeyInfo or PKCS#1);
/// `None` where PHP returns `false`.
pub(crate) fn public_key(pem: &[u8]) -> Option<Public> {
    let blocks = pem_blocks(pem);
    for (label, der) in &blocks {
        if (*label == b"CERTIFICATE" || *label == b"X509 CERTIFICATE")
            && let Some(der) = der
            && let Some(key) = certificate_spki(der).and_then(spki_key)
        {
            return Some(key);
        }
    }
    for (label, der) in &blocks {
        match (*label, der) {
            (b"PUBLIC KEY", Some(der)) => return spki_key(der),
            (b"RSA PUBLIC KEY", Some(der)) => return RsaPublicKey::from_pkcs1_der(der).ok().map(Public::Rsa),
            (b"PUBLIC KEY" | b"RSA PUBLIC KEY", None) => return None,
            _ => {}
        }
    }
    None
}

/// A private key `openssl_pkey_get_private()` accepts and this port can sign with.
pub(crate) enum Private {
    Rsa(Box<RsaPrivateKey>),
    /// A NIST P-256 key: OpenSSL signs with ECDSA whatever `alg` the header claims.
    P256(p256::ecdsa::SigningKey),
}

/// `openssl_pkey_get_private($pem)`: PKCS#8, PKCS#1 RSA and SEC1 EC keys.
pub(crate) fn private_key(pem: &[u8]) -> Option<Private> {
    for (label, der) in pem_blocks(pem) {
        let Some(der) = der else {
            if matches!(label, b"PRIVATE KEY" | b"RSA PRIVATE KEY" | b"EC PRIVATE KEY" | b"ENCRYPTED PRIVATE KEY") {
                return None;
            }
            continue;
        };
        return match label {
            b"PRIVATE KEY" => RsaPrivateKey::from_pkcs8_der(&der)
                .map(|k| Private::Rsa(Box::new(k)))
                .ok()
                .or_else(|| p256::ecdsa::SigningKey::from_pkcs8_der(&der).ok().map(Private::P256)),
            b"RSA PRIVATE KEY" => RsaPrivateKey::from_pkcs1_der(&der).ok().map(|k| Private::Rsa(Box::new(k))),
            b"EC PRIVATE KEY" => {
                p256::SecretKey::from_sec1_der(&der).ok().map(|k| Private::P256(p256::ecdsa::SigningKey::from(k)))
            }
            _ => continue,
        };
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

/// `openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)`: RSASSA-PKCS1-v1_5
/// with SHA-256 for RSA keys, DER ECDSA with SHA-256 for EC keys.
pub(crate) fn sign(key: Private, input: &[u8]) -> Result<Vec<u8>, Error> {
    let failed = || Error::Exception("Unable to sign the token".into());
    match key {
        Private::Rsa(key) => SigningKey::<Sha256>::new(*key).try_sign(input).map(|s| s.to_vec()).map_err(|_| failed()),
        Private::P256(key) => {
            let signature: p256::ecdsa::Signature = key.try_sign(input).map_err(|_| failed())?;
            Ok(signature.to_der().as_bytes().to_vec())
        }
    }
}

/// `openssl_verify(...) === 1`.
pub(crate) fn verify(key: RsaPublicKey, input: &[u8], signature: &[u8]) -> bool {
    let Ok(signature) = Signature::try_from(signature) else {
        return false;
    };
    VerifyingKey::<Sha256>::new(key).verify(input, &signature).is_ok()
}
