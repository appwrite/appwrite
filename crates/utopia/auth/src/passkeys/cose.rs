//! COSE public keys (RFC 9052/9053) as WebAuthn credentials carry them, and
//! the signature algorithms the ceremony accepts (ES256, RS256).

use ciborium::Value;
use p256::ecdsa::signature::Verifier as _;
use rsa::pkcs1v15::{Signature as RsaSignature, VerifyingKey};
use rsa::{BigUint, RsaPublicKey};
use sha2::Sha256;

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

/// A credential public key, decoded from its COSE_Key CBOR map.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum PublicKey {
    /// `kty` 2: an elliptic curve point.
    Ec2 { alg: i64, crv: i64, x: Vec<u8>, y: Vec<u8> },
    /// `kty` 3: an RSA public key.
    Rsa { alg: i64, n: Vec<u8>, e: Vec<u8> },
    /// Another key type, kept for its algorithm.
    Other { alg: i64 },
}

fn int(v: &Value) -> Option<i64> {
    match v {
        Value::Integer(i) => i64::try_from(i128::from(*i)).ok(),
        _ => None,
    }
}

fn label(map: &[(Value, Value)], key: i64) -> Option<&Value> {
    map.iter().find(|(k, _)| int(k) == Some(key)).map(|(_, v)| v)
}

fn bytes(v: Option<&Value>) -> Option<Vec<u8>> {
    match v {
        Some(Value::Bytes(b)) => Some(b.clone()),
        _ => None,
    }
}

impl PublicKey {
    /// Decodes a COSE_Key, failing with webauthn-lib's messages.
    pub fn from_cbor(data: &[u8]) -> Result<Self, String> {
        let mut cursor = std::io::Cursor::new(data);
        let value: Value = ciborium::de::from_reader(&mut cursor).map_err(|_| "Invalid key.".to_owned())?;
        if cursor.position() as usize != data.len() {
            return Err("Invalid key. Presence of extra bytes.".into());
        }
        let Value::Map(map) = value else {
            return Err("Invalid attestation object. Unexpected object.".into());
        };
        let kty = label(&map, 1).and_then(int);
        let alg = label(&map, 3).and_then(int).ok_or_else(|| "The key has no algorithm.".to_owned())?;
        Ok(match kty {
            Some(2) => {
                let crv = label(&map, -1)
                    .and_then(int)
                    .ok_or_else(|| "Invalid EC2 key. The curve or the \"x/y\" coordinates are missing".to_owned())?;
                let (Some(x), Some(y)) = (bytes(label(&map, -2)), bytes(label(&map, -3))) else {
                    return Err("Invalid EC2 key. The curve or the \"x/y\" coordinates are missing".into());
                };
                PublicKey::Ec2 { alg, crv, x, y }
            }
            Some(3) => {
                let (Some(n), Some(e)) = (bytes(label(&map, -1)), bytes(label(&map, -2))) else {
                    return Err("Invalid RSA key. The modulus or the exponent is missing".into());
                };
                PublicKey::Rsa { alg, n, e }
            }
            _ => PublicKey::Other { alg },
        })
    }

    /// The key's `alg`.
    pub fn alg(&self) -> i64 {
        match self {
            PublicKey::Ec2 { alg, .. } | PublicKey::Rsa { alg, .. } | PublicKey::Other { alg } => *alg,
        }
    }

    /// Verifies `signature` over `data` with the key's algorithm (ES256 or RS256).
    pub fn verify(&self, data: &[u8], signature: &[u8]) -> Result<bool, String> {
        match (self.alg(), self) {
            (-7, PublicKey::Ec2 { crv: 1, x, y, .. }) => {
                if x.len() != 32 || y.len() != 32 {
                    return Ok(false);
                }
                let point = p256::EncodedPoint::from_affine_coordinates(
                    p256::FieldBytes::from_slice(x),
                    p256::FieldBytes::from_slice(y),
                    false,
                );
                let Ok(key) = p256::ecdsa::VerifyingKey::from_encoded_point(&point) else {
                    return Ok(false);
                };
                let signature = if signature.len() == 64 {
                    p256::ecdsa::Signature::from_slice(signature)
                } else {
                    p256::ecdsa::Signature::from_der(signature)
                };
                Ok(signature.is_ok_and(|s| key.verify(data, &s).is_ok()))
            }
            (-257, PublicKey::Rsa { n, e, .. }) => {
                let Ok(key) = RsaPublicKey::new(BigUint::from_bytes_be(n), BigUint::from_bytes_be(e)) else {
                    return Ok(false);
                };
                let Ok(signature) = RsaSignature::try_from(signature) else {
                    return Ok(false);
                };
                Ok(VerifyingKey::<Sha256>::new(key).verify(data, &signature).is_ok())
            }
            (-7 | -257, _) => Ok(false),
            (alg, _) => Err(format!("The algorithm with identifier {alg} is not supported.")),
        }
    }
}
