use hmac::{Hmac, Mac};
use php_std::json::{self, Flags};
use php_std::value::{Number, compare_numbers, numeric_str};
use php_std::zval::{Array, Key, Zval};
use sha2::{Digest, Sha256};

use super::key::{self, Public, Rsa};
use super::{Claim, Header, base64url_decode};
use crate::Error;
use crate::hash::hash_equals;
use crate::options::to_long;

/// An `aud` value: one recipient, or an array of them.
#[derive(Debug, Clone, PartialEq)]
pub enum Audience {
    One(Vec<u8>),
    Many(Array),
}

impl Audience {
    pub(crate) fn to_zval(&self) -> Zval {
        match self {
            Audience::One(s) => Zval::String(s.clone()),
            Audience::Many(a) => Zval::Array(a.clone()),
        }
    }
}

/// How a [`Verifier`] checks signatures: the JWS `alg` it expects and the
/// check itself. Implement it to verify another algorithm.
pub trait SignatureCheck {
    /// `getAlgorithm()`.
    fn algorithm(&self) -> &str;

    /// `verifySignature($signingInput, $signature)`.
    fn verify_signature(&self, input: &[u8], signature: &[u8]) -> Result<bool, Error>;
}

/// HS256 with a shared secret (`Utopia\Auth\Verifiers\Symmetric`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Hs256 {
    secret: Vec<u8>,
}

impl Hs256 {
    pub fn new(secret: &[u8]) -> Result<Self, Error> {
        if secret.is_empty() || secret == b"0" {
            return Err(Error::Exception("A signing secret is required".into()));
        }
        Ok(Self { secret: secret.to_vec() })
    }
}

impl SignatureCheck for Hs256 {
    fn algorithm(&self) -> &str {
        "HS256"
    }

    fn verify_signature(&self, input: &[u8], signature: &[u8]) -> Result<bool, Error> {
        let mut mac = Hmac::<Sha256>::new_from_slice(&self.secret).expect("HMAC takes any key length");
        mac.update(input);
        Ok(hash_equals(&mac.finalize().into_bytes(), signature))
    }
}

/// RS256 with a PEM RSA public key (`Utopia\Auth\Verifiers\Asymmetric`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Rs256 {
    public_key: Vec<u8>,
}

impl Rs256 {
    pub fn new(public_key: &[u8]) -> Result<Self, Error> {
        if public_key.is_empty() || public_key == b"0" {
            return Err(Error::Exception("A public key is required".into()));
        }
        Ok(Self { public_key: public_key.to_vec() })
    }

    /// `getKeyId()`: SHA-256 (hex) of the RSA modulus, as the issuer derives it.
    pub fn key_id(&self) -> Result<String, Error> {
        match key::public_key(&self.public_key) {
            None => Err(Error::Verification("Unable to parse the public key".into())),
            Some(Public::Other) => Err(Error::Verification("Public key is not an RSA key".into())),
            Some(Public::Rsa(k)) => Ok(hex::encode(Sha256::digest(Rsa::modulus(&k)))),
        }
    }
}

impl SignatureCheck for Rs256 {
    fn algorithm(&self) -> &str {
        "RS256"
    }

    fn verify_signature(&self, input: &[u8], signature: &[u8]) -> Result<bool, Error> {
        match key::public_key(&self.public_key) {
            None => Err(Error::Verification("Unable to parse the public key".into())),
            Some(Public::Other) => Err(Error::Verification("Public key is not an RSA key".into())),
            Some(Public::Rsa(k)) => Ok(key::verify(k, input, signature)),
        }
    }
}

/// Verifies compact JWS tokens and their registered claims (`Utopia\Auth\Verifier`).
///
/// The expectations are fixed at construction: `iss`, acceptable `aud`
/// values and `typ` are checked when given; `exp` is required unless
/// `allow_expired`; `nbf`/`iat` are always checked; `leeway` tolerates skew.
#[derive(Debug, Clone, PartialEq)]
pub struct Verifier<S: SignatureCheck> {
    check: S,
    issuer: Option<Vec<u8>>,
    audience: Option<Vec<Zval>>,
    kind: Option<Vec<u8>>,
    allow_expired: bool,
    leeway: i64,
}

fn verification(message: &str) -> Error {
    Error::Verification(message.to_owned())
}

/// `$a + $b` with PHP's overflow into float.
fn sum(a: i64, b: i64) -> Number {
    match a.checked_add(b) {
        Some(v) => Number::Int(v),
        None => Number::Float(a as f64 + b as f64),
    }
}

fn is_numeric(v: &Zval) -> bool {
    match v {
        Zval::Int(_) | Zval::Float(_) => true,
        Zval::String(s) => std::str::from_utf8(s).ok().and_then(numeric_str).is_some(),
        _ => false,
    }
}

impl<S: SignatureCheck> Verifier<S> {
    /// `new Verifier($issuer, $audience, $type, $allowExpired, $leeway)`.
    pub fn new(
        check: S,
        issuer: Option<&[u8]>,
        audience: Option<&Audience>,
        kind: Option<&[u8]>,
        allow_expired: bool,
        leeway: i64,
    ) -> Result<Self, Error> {
        if leeway < 0 {
            return Err(Error::InvalidArgument("Leeway cannot be negative".into()));
        }
        let audience = audience.map(|a| match a {
            Audience::One(s) => vec![Zval::String(s.clone())],
            Audience::Many(list) => list.iter().map(|(_, v)| v.clone()).collect(),
        });
        Ok(Self {
            check,
            issuer: issuer.map(<[u8]>::to_vec),
            audience,
            kind: kind.map(<[u8]>::to_vec),
            allow_expired,
            leeway,
        })
    }

    /// The signature check (to reach `Rs256::key_id`).
    pub fn check(&self) -> &S {
        &self.check
    }

    /// `verify($token)` at `now`: the claims of a valid token.
    pub fn verify(&self, token: &[u8], now: i64) -> Result<Array, Error> {
        let segments: Vec<&[u8]> = token.split(|b| *b == b'.').collect();
        let [encoded_header, encoded_claims, encoded_signature] = segments[..] else {
            return Err(verification("Token must have three segments"));
        };
        let header = decode_segment(encoded_header, "Header")?;
        let claims = decode_segment(encoded_claims, "Claims")?;
        let signature =
            base64url_decode(encoded_signature).ok_or_else(|| verification("Signature is not valid base64url"))?;
        let get = |a: &Array, k: &str| a.get(&Key::Str(k.as_bytes().to_vec())).cloned();
        if get(&header, Header::Algorithm.as_str()) != Some(Zval::String(self.check.algorithm().as_bytes().to_vec())) {
            return Err(verification("Unexpected token algorithm"));
        }
        if let Some(kind) = &self.kind
            && get(&header, Header::Type.as_str()) != Some(Zval::String(kind.clone()))
        {
            return Err(verification("Unexpected token type"));
        }
        let mut input = encoded_header.to_vec();
        input.push(b'.');
        input.extend_from_slice(encoded_claims);
        if !self.check.verify_signature(&input, &signature)? {
            return Err(verification("Signature verification failed"));
        }
        self.validate(&claims, now)?;
        Ok(claims)
    }

    /// `validateClaims()`.
    fn validate(&self, claims: &Array, now: i64) -> Result<(), Error> {
        let get = |c: Claim| claims.get(&Key::Str(c.as_str().as_bytes().to_vec())).filter(|v| **v != Zval::Null);
        let skewed = sum(now, self.leeway);
        if let Some(nbf) = get(Claim::NotBefore) {
            if !is_numeric(nbf) {
                return Err(verification("Invalid \"nbf\" claim"));
            }
            if compare_numbers(skewed, Number::Int(to_long(nbf))).is_lt() {
                return Err(verification("Token is not yet valid"));
            }
        }
        if let Some(iat) = get(Claim::IssuedAt) {
            if !is_numeric(iat) {
                return Err(verification("Invalid \"iat\" claim"));
            }
            if compare_numbers(skewed, Number::Int(to_long(iat))).is_lt() {
                return Err(verification("Token was issued in the future"));
            }
        }
        if !self.allow_expired {
            let Some(exp) = get(Claim::Expiration) else {
                return Err(verification("Token is missing the \"exp\" claim"));
            };
            if !is_numeric(exp) {
                return Err(verification("Invalid \"exp\" claim"));
            }
            if compare_numbers(Number::Int(now), sum(to_long(exp), self.leeway)).is_ge() {
                return Err(verification("Token has expired"));
            }
        }
        if let Some(issuer) = &self.issuer
            && get(Claim::Issuer) != Some(&Zval::String(issuer.clone()))
        {
            return Err(verification("Unexpected token issuer"));
        }
        if let Some(expected) = &self.audience {
            let aud = get(Claim::Audience).cloned().unwrap_or(Zval::Null);
            let audiences: Vec<Zval> = match aud {
                Zval::Array(a) => a.iter().map(|(_, v)| v.clone()).collect(),
                other => vec![other],
            };
            if !expected.iter().any(|e| audiences.iter().any(|a| identical(a, e))) {
                return Err(verification("Unexpected token audience"));
            }
        }
        Ok(())
    }
}

/// `===`.
fn identical(a: &Zval, b: &Zval) -> bool {
    match (a, b) {
        (Zval::Float(x), Zval::Float(y)) => x == y,
        _ => a == b,
    }
}

/// `decodeSegment()`: base64url JSON of an object (or `[]`).
fn decode_segment(segment: &[u8], label: &str) -> Result<Array, Error> {
    let decoded =
        base64url_decode(segment).ok_or_else(|| Error::Verification(format!("{label} is not valid base64url")))?;
    let data = json::decode(&decoded, Some(true), json::DEFAULT_DEPTH, Flags::THROW_ON_ERROR)
        .map_err(|_| Error::Verification(format!("{label} is not valid JSON")))?;
    match data {
        Zval::Array(a) if a.is_empty() || !a.is_list() => Ok(a),
        _ => Err(Error::Verification(format!("{label} must be a JSON object"))),
    }
}
