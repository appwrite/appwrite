//! COSE public keys (RFC 9052/9053) as web-auth/cose-lib reads them from a
//! normalized CBOR map, and the signature algorithms the ceremony accepts
//! (ES256, RS256), with cose-lib's checks and messages.

use php_std::zval::{Array, Key, Zval};
use rsa::pkcs1v15::{Signature as RsaSignature, VerifyingKey};
use rsa::{BigUint, RsaPublicKey};
use sha2::Sha256;

use super::cbor::{self, Stream};

/// COSE algorithm identifiers the ceremony offers (`pubKeyCredParams`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Algorithm {
    /// ECDSA P-256 with SHA-256 (-7).
    Es256,
    /// RSASSA-PKCS1-v1_5 with SHA-256 (-257).
    Rs256,
}

impl Algorithm {
    pub fn id(self) -> i64 {
        match self {
            Algorithm::Es256 => -7,
            Algorithm::Rs256 => -257,
        }
    }
}

const TYPE: i64 = 1;
const ALG: i64 = 3;
const CURVE: i64 = -1;
const X: i64 = -2;
const Y: i64 = -3;
const D: i64 = -4;
const N: i64 = -1;
const E: i64 = -2;

/// A credential public key: its normalized COSE_Key map (`Cose\Key\Key`).
#[derive(Debug, Clone, PartialEq)]
pub struct PublicKey {
    data: Array,
}

/// `/^-?\d+$/`.
fn integer_string(s: &[u8]) -> Option<i64> {
    let digits = s.strip_prefix(b"-").unwrap_or(s);
    if digits.is_empty() || !digits.iter().all(u8::is_ascii_digit) {
        return None;
    }
    std::str::from_utf8(s).ok()?.parse().ok()
}

/// `Key::normalizeIntegerEntries()`: numeric strings at `keys` become integers.
fn normalize_integers(data: &Array, keys: &[i64]) -> Array {
    data.iter()
        .map(|(k, v)| {
            let v = match (k, v) {
                (Key::Int(i), Zval::String(s)) if keys.contains(i) => integer_string(s).map_or(v.clone(), Zval::Int),
                _ => v.clone(),
            };
            (k.clone(), v)
        })
        .collect()
}

/// `FILTER_VALIDATE_INT`.
fn filter_int(s: &[u8]) -> Option<i64> {
    match php_std::filter::filter_var(
        &php_std::filter::Value::Str(s.to_vec()),
        php_std::filter::FILTER_VALIDATE_INT,
        &php_std::filter::Options::Flags(0),
    ) {
        Ok(f) => match f.value {
            php_std::filter::Value::Int(i) => Some(i),
            _ => None,
        },
        Err(_) => None,
    }
}

impl PublicKey {
    /// webauthn-lib's `getCoseKey()`: decode the stored COSE_Key (no trailing
    /// bytes), normalize it and build a `Cose\Key\Key` (which needs a `kty`).
    pub fn from_cbor(data: &[u8]) -> Result<Self, String> {
        let mut stream = Stream::new(data);
        let item = cbor::decode(&mut stream)?;
        if !stream.is_eof() {
            return Err("Invalid key. Presence of extra bytes.".into());
        }
        if !item.is_normalizable() {
            return Err("Invalid attestation object. Unexpected object.".into());
        }
        let Zval::Array(data) = item.normalize()? else {
            return Err("Invalid attestation object. Unexpected object.".into());
        };
        if data.get(&Key::Int(TYPE)).is_none() {
            return Err("Invalid key: the type is not defined".into());
        }
        Ok(Self { data: normalize_integers(&data, &[TYPE]) })
    }

    fn get(&self, key: i64) -> Option<&Zval> {
        self.data.get(&Key::Int(key))
    }

    /// `Key::alg()`.
    pub fn alg(&self) -> Result<i64, String> {
        match self.get(ALG) {
            None => Err(format!("The key has no data at index {ALG}")),
            Some(Zval::Int(i)) => Ok(*i),
            Some(Zval::String(s)) if filter_int(s).is_some() => Ok(filter_int(s).unwrap_or_default()),
            Some(_) => Err("Invalid key: the \"alg\" parameter must be an integer algorithm identifier".into()),
        }
    }

    /// The algorithm's `verify()` (`Manager::get()` first: ES256 and RS256 only).
    pub fn verify(&self, data: &[u8], signature: &[u8]) -> Result<bool, String> {
        match self.alg()? {
            -7 => self.verify_es256(data, signature),
            -257 => self.verify_rs256(data, signature),
            _ => Err("Unsupported algorithm".into()),
        }
    }

    /// `Ec2Key::create()` then ES256's checks.
    fn verify_es256(&self, data: &[u8], signature: &[u8]) -> Result<bool, String> {
        // CoseSignatureFixer: DER signatures become R || S.
        let signature = if signature.len() == 64 { signature.to_vec() } else { from_asn1(signature, 32)? };
        let key = normalize_integers(&self.data, &[CURVE, TYPE]);
        let at = |k: i64| key.get(&Key::Int(k));
        if !matches!(at(TYPE), Some(Zval::Int(2))) && at(TYPE) != Some(&Zval::String(b"EC".to_vec())) {
            return Err("Invalid EC2 key. The key type does not correspond to an EC2 key".into());
        }
        let set = |k: i64| at(k).is_some_and(|v| *v != Zval::Null);
        if !set(CURVE) || !set(X) || !set(Y) {
            return Err("Invalid EC2 key. The curve or the \"x/y\" coordinates are missing".into());
        }
        let curve = match at(CURVE) {
            Some(Zval::Int(c)) if [1, 8, 2, 3, 256, 257, 258, 259].contains(c) => *c,
            Some(Zval::String(name)) => match name.as_slice() {
                b"P-256" => 1,
                b"secp256k1" | b"P-256K" => 8,
                b"P-384" => 2,
                b"P-521" => 3,
                b"brainpoolP256r1" => 256,
                b"brainpoolP320r1" => 257,
                b"brainpoolP384r1" => 258,
                b"brainpoolP512r1" => 259,
                _ => return Err("The curve is not supported".into()),
            },
            _ => return Err("The curve is not supported".into()),
        };
        let length = match curve {
            1 | 8 | 256 => 32,
            2 | 258 => 48,
            3 => 66,
            257 => 40,
            _ => 64,
        };
        let mut coordinates = Vec::new();
        for (index, name) in [(X, "x"), (Y, "y")] {
            let Some(Zval::String(c)) = at(index) else {
                return Err(format!("Invalid type for {name} coordinate"));
            };
            if c.len() != length {
                return Err(format!("Invalid length for {name} coordinate"));
            }
            coordinates.push(c.clone());
        }
        if let Some(d) = at(D)
            && !matches!(d, Zval::String(s) if s.len() == length)
        {
            return Err("Invalid length for d".into());
        }
        if curve != 1 {
            return Err("This key cannot be used with this algorithm".into());
        }
        let point = p256::EncodedPoint::from_affine_coordinates(
            p256::FieldBytes::from_slice(&coordinates[0]),
            p256::FieldBytes::from_slice(&coordinates[1]),
            false,
        );
        let Ok(key) = p256::ecdsa::VerifyingKey::from_encoded_point(&point) else {
            return Ok(false);
        };
        let Ok(signature) = p256::ecdsa::Signature::from_slice(&signature) else {
            return Ok(false);
        };
        use p256::ecdsa::signature::Verifier as _;
        Ok(key.verify(data, &signature).is_ok())
    }

    /// `RsaKey::create()` then RS256's checks (an unusable key is an invalid signature).
    fn verify_rs256(&self, data: &[u8], signature: &[u8]) -> Result<bool, String> {
        let key = normalize_integers(&self.data, &[TYPE]);
        let at = |k: i64| key.get(&Key::Int(k));
        if !matches!(at(TYPE), Some(Zval::Int(3))) && at(TYPE) != Some(&Zval::String(b"RSA".to_vec())) {
            return Err("Invalid RSA key. The key type does not correspond to a RSA key".into());
        }
        let set = |k: i64| at(k).is_some_and(|v| *v != Zval::Null);
        if !set(N) || !set(E) {
            return Err("Invalid RSA key. The modulus or the exponent is missing".into());
        }
        let (Some(Zval::String(n)), Some(Zval::String(e))) = (at(N), at(E)) else {
            return Err("Invalid RSA key. The modulus and the exponent shall be non-empty byte strings".into());
        };
        if n.is_empty() || e.is_empty() {
            return Err("Invalid RSA key. The modulus and the exponent shall be non-empty byte strings".into());
        }
        if n.iter().all(|b| *b == 0) {
            return Err("Invalid RSA key. The modulus shall not be zero".into());
        }
        let Ok(key) = RsaPublicKey::new(BigUint::from_bytes_be(n), BigUint::from_bytes_be(e)) else {
            return Ok(false);
        };
        let Ok(signature) = RsaSignature::try_from(signature) else {
            return Ok(false);
        };
        use rsa::signature::Verifier as _;
        Ok(VerifyingKey::<Sha256>::new(key).verify(data, &signature).is_ok())
    }
}

/// A forward-only reader over the DER signature (`readAsn1Content()`).
struct Der<'a> {
    data: &'a [u8],
    at: usize,
}

impl<'a> Der<'a> {
    fn read(&mut self, n: usize) -> &'a [u8] {
        let start = self.at.min(self.data.len());
        let end = (self.at + n).min(self.data.len());
        self.at += n;
        &self.data[start..end]
    }

    fn remaining(&self) -> isize {
        self.data.len() as isize - self.at as isize
    }

    /// `readAsn1Length()`.
    fn length(&mut self) -> Result<usize, String> {
        let Some(&first) = self.read(1).first() else {
            return Err("Invalid data. Truncated length.".into());
        };
        if first < 0x80 {
            return Ok(usize::from(first));
        }
        if first == 0x81 {
            let Some(&second) = self.read(1).first() else {
                return Err("Invalid data. Truncated length.".into());
            };
            if second < 0x80 {
                return Err("Invalid data. Non-minimal length encoding.".into());
            }
            return Ok(usize::from(second));
        }
        Err("Invalid data. Unsupported length encoding.".into())
    }

    /// `readAsn1Integer()`, padded to `length` bytes.
    fn integer(&mut self, length: usize) -> Result<Vec<u8>, String> {
        if self.read(1) != [0x02] {
            return Err("Invalid data. Should contain an integer.".into());
        }
        let n = self.length()?;
        if n == 0 {
            return Err("Invalid data. Empty integer.".into());
        }
        let mut content = self.read(n).to_vec();
        if content.len() != n {
            return Err("Invalid data. Truncated integer.".into());
        }
        if content[0] >= 0x80 {
            return Err("Invalid data. Negative integer.".into());
        }
        if content[0] == 0 {
            if n == 1 {
                return Err("Invalid signature. R and S must be positive integers.".into());
            }
            if content[1] < 0x80 {
                return Err("Invalid data. Non-minimal integer.".into());
            }
            content.remove(0);
        }
        if content.len() > length {
            return Err("Invalid data. The integer is too large for the curve.".into());
        }
        let mut padded = vec![0u8; length - content.len()];
        padded.extend_from_slice(&content);
        Ok(padded)
    }
}

/// `ECSignature::fromAsn1($signature, $length)` for a curve of `length` bytes.
fn from_asn1(signature: &[u8], length: usize) -> Result<Vec<u8>, String> {
    let mut der = Der { data: signature, at: 0 };
    if der.read(1) != [0x30] {
        return Err("Invalid data. Should start with a sequence.".into());
    }
    let sequence = der.length()?;
    if sequence as isize != der.remaining() {
        return Err("Invalid data. Sequence length mismatch.".into());
    }
    let mut out = der.integer(length)?;
    out.extend_from_slice(&der.integer(length)?);
    Ok(out)
}
