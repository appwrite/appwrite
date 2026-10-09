//! RSA keys as PHP's OpenSSL functions read and write them (PEM), on OpenSSL
//! itself: same key limits as PHP, and constant-time private key operations.

use openssl::hash::MessageDigest;
use openssl::pkey::{HasPublic, Id, PKey};
use openssl::rsa::RsaRef;
use openssl::sign::{Signer, Verifier};
use p256::ecdsa::signature::Signer as _;
use p256::pkcs8::DecodePrivateKey;
use php_std::encoding::base64_decode;

use crate::Error;

/// An RSA public key.
pub(crate) type RsaPublicKey = openssl::rsa::Rsa<openssl::pkey::Public>;
/// An RSA private key.
type RsaPrivateKey = openssl::rsa::Rsa<openssl::pkey::Private>;

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
    let bits = u32::try_from(bits).ok().filter(|b| *b >= 512).ok_or_else(failed)?;
    let key = RsaPrivateKey::generate(bits).and_then(PKey::from_rsa).map_err(|_| failed())?;
    let pem = |pem: Result<Vec<u8>, _>, kind: &str| {
        pem.ok()
            .and_then(|p| String::from_utf8(p).ok())
            .ok_or_else(|| Error::Exception(format!("Unable to export the {kind} key")))
    };
    Ok(KeyPair {
        private_key: pem(key.private_key_to_pem_pkcs8(), "private")?,
        public_key: pem(key.public_key_to_pem(), "public")?,
    })
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
    let key = PKey::public_key_from_der(der).ok()?;
    if key.id() != Id::RSA {
        return Some(Public::Other);
    }
    key.rsa().ok().map(Public::Rsa)
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
            (b"RSA PUBLIC KEY", Some(der)) => {
                return RsaPublicKey::public_key_from_der_pkcs1(der).ok().map(Public::Rsa);
            }
            (b"PUBLIC KEY" | b"RSA PUBLIC KEY", None) => return None,
            _ => {}
        }
    }
    None
}

/// A private key `openssl_pkey_get_private()` accepts and this port can sign with.
pub(crate) enum Private {
    Rsa(RsaPrivateKey),
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
            b"PRIVATE KEY" => match PKey::private_key_from_pkcs8(&der) {
                Ok(key) if key.id() == Id::RSA => key.rsa().ok().map(Private::Rsa),
                _ => p256::ecdsa::SigningKey::from_pkcs8_der(&der).ok().map(Private::P256),
            },
            b"RSA PRIVATE KEY" => RsaPrivateKey::private_key_from_der(&der).ok().map(Private::Rsa),
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
    pub fn modulus<T: HasPublic>(key: &RsaRef<T>) -> Vec<u8> {
        key.n().to_vec()
    }

    /// The big-endian public exponent `e`.
    pub fn exponent<T: HasPublic>(key: &RsaRef<T>) -> Vec<u8> {
        key.e().to_vec()
    }
}

/// `openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)`: RSASSA-PKCS1-v1_5
/// with SHA-256 for RSA keys, DER ECDSA with SHA-256 for EC keys.
pub(crate) fn sign(key: Private, input: &[u8]) -> Result<Vec<u8>, Error> {
    let failed = || Error::Exception("Unable to sign the token".into());
    match key {
        Private::Rsa(key) => {
            let key = PKey::from_rsa(key).map_err(|_| failed())?;
            Signer::new(MessageDigest::sha256(), &key)
                .and_then(|mut signer| signer.sign_oneshot_to_vec(input))
                .map_err(|_| failed())
        }
        Private::P256(key) => {
            let signature: p256::ecdsa::Signature = key.try_sign(input).map_err(|_| failed())?;
            Ok(signature.to_der().as_bytes().to_vec())
        }
    }
}

/// `openssl_verify(...) === 1`.
pub(crate) fn verify(key: RsaPublicKey, input: &[u8], signature: &[u8]) -> bool {
    PKey::from_rsa(key)
        .and_then(|key| Verifier::new(MessageDigest::sha256(), &key)?.verify_oneshot(signature, input))
        .unwrap_or(false)
}
