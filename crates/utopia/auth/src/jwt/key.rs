//! Keys as PHP's OpenSSL functions read and write them (PEM, inline or
//! from a `file://` path), on OpenSSL itself: same key types, curves and
//! limits as PHP, and constant-time private key operations.

use std::borrow::Cow;

use openssl::hash::MessageDigest;
use openssl::pkey::{HasPublic, Id, PKey};
use openssl::rsa::RsaRef;
use openssl::sign::{Signer, Verifier};
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

/// The key material a key argument names: the contents of the file a
/// `file://` argument points to (`None` when it cannot be read, where PHP
/// returns `false`), else the argument itself. `function` and `parameter`
/// name PHP's argument for the error a path with a NUL byte raises.
fn key_source<'a>(key: &'a [u8], function: &str, parameter: &str) -> Result<Option<Cow<'a, [u8]>>, Error> {
    // PHP reads a file only when something follows the scheme.
    let Some(path) = key.strip_prefix(b"file://").filter(|p| !p.is_empty()) else {
        return Ok(Some(Cow::Borrowed(key)));
    };
    if path.contains(&0) {
        return Err(Error::Value(format!("{function}(): Argument #1 (${parameter}) must not contain any null bytes")));
    }
    Ok(php_std::path::file_get_contents(path).map(Cow::Owned))
}

/// `openssl_pkey_get_public($key)`: the key of the first PEM certificate,
/// else of the first PEM public key (SubjectPublicKeyInfo or PKCS#1), in
/// `key` or in the file a `file://` key names; `Ok(None)` where PHP returns
/// `false`, and `\ValueError` for a path with a NUL byte.
pub(crate) fn public_key(key: &[u8]) -> Result<Option<Public>, Error> {
    let Some(pem) = key_source(key, "openssl_pkey_get_public", "public_key")? else {
        return Ok(None);
    };
    Ok(public_pem(&pem))
}

fn public_pem(pem: &[u8]) -> Option<Public> {
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

/// A private key `openssl_pkey_get_private()` accepts: RSA, RSA-PSS, DSA,
/// EC on any curve OpenSSL knows, Ed25519, ... Whether it can sign RS256
/// input is decided when signing, as PHP's `openssl_sign()` does.
pub(crate) type Private = PKey<openssl::pkey::Private>;

/// `openssl_pkey_get_private($key)`: the first PKCS#8, PKCS#1 RSA, SEC1 EC
/// or DSA private key in `key` or in the file a `file://` key names;
/// `Ok(None)` where PHP returns `false` (encrypted keys need a passphrase),
/// and `\ValueError` for a path with a NUL byte.
pub(crate) fn private_key(key: &[u8]) -> Result<Option<Private>, Error> {
    let Some(pem) = key_source(key, "openssl_pkey_get_private", "private_key")? else {
        return Ok(None);
    };
    Ok(private_pem(&pem))
}

fn private_pem(pem: &[u8]) -> Option<Private> {
    for (label, der) in pem_blocks(pem) {
        let Some(der) = der else {
            if matches!(
                label,
                b"PRIVATE KEY" | b"RSA PRIVATE KEY" | b"EC PRIVATE KEY" | b"DSA PRIVATE KEY" | b"ENCRYPTED PRIVATE KEY"
            ) {
                return None;
            }
            continue;
        };
        return match label {
            b"PRIVATE KEY" => PKey::private_key_from_pkcs8(&der).ok(),
            b"RSA PRIVATE KEY" => RsaPrivateKey::private_key_from_der(&der).and_then(PKey::from_rsa).ok(),
            b"EC PRIVATE KEY" => openssl::ec::EcKey::private_key_from_der(&der).and_then(PKey::from_ec_key).ok(),
            b"DSA PRIVATE KEY" => PKey::private_key_from_der(&der).ok().filter(|k| k.id() == Id::DSA),
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

/// `openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)`: SHA-256
/// with the key's own scheme (RSASSA-PKCS1-v1_5 for RSA, DER ECDSA for EC and
/// DSA); keys that cannot sign a SHA-256 digest (Ed25519, X25519, DH, SM2)
/// fail like PHP's `false`.
pub(crate) fn sign(key: Private, input: &[u8]) -> Result<Vec<u8>, Error> {
    Signer::new(MessageDigest::sha256(), &key)
        .and_then(|mut signer| signer.sign_oneshot_to_vec(input))
        .map_err(|_| Error::Exception("Unable to sign the token".into()))
}

/// `openssl_verify(...) === 1`.
pub(crate) fn verify(key: RsaPublicKey, input: &[u8], signature: &[u8]) -> bool {
    PKey::from_rsa(key)
        .and_then(|key| Verifier::new(MessageDigest::sha256(), &key)?.verify_oneshot(signature, input))
        .unwrap_or(false)
}
