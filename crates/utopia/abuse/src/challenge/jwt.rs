//! [`Jwt`]: the HS256 JSON Web Tokens an [`super::Issuer`] signs.
//!
//! Both runtimes read each other's tokens, so this is the encoding PHP's
//! `Ahc\Jwt\JWT` (adhocore/jwt 1.1) writes and the checks it makes on decode,
//! for the one algorithm the issuer uses.

use hmac::{Hmac, Mac};
use php_std::json::{self, Flags};
use php_std::value::Number;
use php_std::{Array, ArrayKey, Str, Value};
use sha2::Sha256;

use crate::{Error, Result};

/// The algorithms `Ahc\Jwt\JWT` knows: a decoded header must name one.
const ALGORITHMS: [&[u8]; 6] = [b"HS256", b"HS384", b"HS512", b"RS256", b"RS384", b"RS512"];

/// A JSON Web Token codec signing with HMAC SHA-256 under one key.
///
/// PHP: `new \Ahc\Jwt\JWT($key, 'HS256', $maxAge, 0)` (no leeway, no key ids).
///
/// ```
/// use php_std::{Array, ArrayKey, Value};
/// use utopia_abuse::challenge::Jwt;
///
/// let jwt = Jwt::new(b"key", 60)?;
/// let mut payload = Array::new();
/// payload.set(ArrayKey::Str("a".into()), Value::Str("b".into()));
/// let token = jwt.encode(&payload)?;
/// assert_eq!(jwt.decode(token.as_bytes())?.get_str("a"), Some(&Value::Str("b".into())));
/// # Ok::<(), utopia_abuse::Error>(())
/// ```
///
/// ```php
/// use Ahc\Jwt\JWT;
///
/// $jwt = new JWT('key', 'HS256', 60, 0);
/// $token = $jwt->encode(['a' => 'b']);
/// echo $jwt->decode($token)['a'], "\n"; // b
/// ```
#[derive(Clone)]
pub struct Jwt {
    key: Vec<u8>,
    max_age: i64,
}

impl std::fmt::Debug for Jwt {
    /// Writes the max age, never the key.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Jwt").field("max_age", &self.max_age).finish_non_exhaustive()
    }
}

impl Jwt {
    /// A codec signing with `key`. `max_age` is how long after its `iat` a
    /// token stays valid, and the lifetime given to a token with neither `iat`
    /// nor `exp`.
    ///
    /// Fails with [`Error::Jwt`] for an empty key (`Signing key cannot be
    /// empty`) or a max age under 1 (`Invalid maxAge: Should be greater than
    /// 0`).
    ///
    /// PHP: `new JWT($key, 'HS256', $maxAge, 0)`.
    pub fn new(key: &[u8], max_age: i64) -> Result<Self> {
        // `empty($key)`: "" and "0" are empty.
        if key.is_empty() || key == b"0" {
            return Err(Error::Jwt("Signing key cannot be empty".into()));
        }
        if max_age < 1 {
            return Err(Error::Jwt("Invalid maxAge: Should be greater than 0".into()));
        }
        Ok(Jwt { key: key.to_vec(), max_age })
    }

    /// Signs `payload`, adding `exp` (now plus the max age) when it has
    /// neither `iat` nor `exp`.
    ///
    /// Fails with [`Error::Jwt`] when `json_encode` cannot encode the payload
    /// (`JSON failed: Malformed UTF-8 characters, possibly incorrectly
    /// encoded`).
    ///
    /// PHP: `encode($payload)`.
    pub fn encode(&self, payload: &Array) -> Result<String> {
        let mut payload = payload.clone();
        if !isset(&payload, "iat") && !isset(&payload, "exp") {
            payload.set(key("exp"), Value::Int(php_std::datetime::time().saturating_add(self.max_age)));
        }
        let header = url_safe_encode(br#"{"typ":"JWT","alg":"HS256"}"#);
        let body = json::encode(&Value::Array(payload), Flags::UNESCAPED_SLASHES, json::DEFAULT_DEPTH)
            .map_err(|e| Error::Jwt(format!("JSON failed: {}", e.message())))?;
        let input = format!("{header}.{}", url_safe_encode(body.as_bytes()));
        let signature = url_safe_encode(&self.sign(input.as_bytes()));
        Ok(format!("{input}.{signature}"))
    }

    /// The payload of a token this codec signed whose `exp`, `iat` and `nbf`
    /// claims, when present, allow it now.
    ///
    /// Fails with [`Error::Jwt`] and the library's message: `Invalid token:
    /// Incomplete segments`, `Invalid token: Missing header algo`, `Invalid
    /// token: Unsupported header algo`, `Invalid token: Unknown key ID`,
    /// `Invalid token: Signature failed`, `Invalid token: Expired`, `Invalid
    /// token: Not now`, or `JSON failed: ...`. A time claim that is not a
    /// number fails with [`Error::Type`], as PHP's arithmetic on it does.
    ///
    /// PHP: `decode($token)`.
    pub fn decode(&self, token: &[u8]) -> Result<Array> {
        if token.iter().filter(|b| **b == b'.').count() < 2 {
            return Err(Error::Jwt("Invalid token: Incomplete segments".into()));
        }
        let mut parts = token.splitn(3, |b| *b == b'.');
        let (head, body, signature) =
            (parts.next().unwrap_or(b""), parts.next().unwrap_or(b""), parts.next().unwrap_or(b""));

        let header = cast_array(url_safe_decode(head)?);
        let alg = header.get_str("alg");
        if alg.is_none_or(|a| !a.truthy()) {
            return Err(Error::Jwt("Invalid token: Missing header algo".into()));
        }
        if !matches!(alg, Some(Value::Str(a)) if ALGORITHMS.contains(&a.as_bytes())) {
            return Err(Error::Jwt("Invalid token: Unsupported header algo".into()));
        }
        if isset(&header, "kid") {
            return Err(Error::Jwt("Invalid token: Unknown key ID".into()));
        }

        let input = &token[..head.len() + 1 + body.len()];
        let expected = url_safe_encode(&self.sign(input));
        if !utopia_auth::hash_equals(expected.as_bytes(), signature) {
            return Err(Error::Jwt("Invalid token: Signature failed".into()));
        }

        let payload = cast_array(url_safe_decode(body)?);
        self.check_times(&payload)?;
        Ok(payload)
    }

    /// `validateTimestamps()`: `exp` must be in the future, `iat` younger
    /// than the max age, and `nbf` in the past.
    fn check_times(&self, payload: &Array) -> Result<()> {
        let now = php_std::datetime::time();
        for (claim, offset, error) in [("exp", 0, "Expired"), ("iat", self.max_age, "Expired"), ("nbf", 0, "Not now")] {
            let Some(value) = payload.get_str(claim).filter(|v| !matches!(v, Value::Null)) else {
                continue;
            };
            let order = match number(value)? {
                Number::Int(at) => Some(i128::from(now).cmp(&(i128::from(at) + i128::from(offset)))),
                // NAN compares false both ways.
                Number::Float(at) => (now as f64).partial_cmp(&(at + offset as f64)),
            };
            let fail = order.is_some_and(|o| if claim == "nbf" { o.is_le() } else { o.is_ge() });
            if fail {
                return Err(Error::Jwt(format!("Invalid token: {error}")));
            }
        }
        Ok(())
    }

    fn sign(&self, input: &[u8]) -> Vec<u8> {
        let mut mac = Hmac::<Sha256>::new_from_slice(&self.key).expect("HMAC takes a key of any length");
        mac.update(input);
        mac.finalize().into_bytes().to_vec()
    }
}

/// `hash_hmac('sha256', $data, $key)`: the hex digest.
pub(crate) fn hmac_hex(key: &[u8], data: &[u8]) -> String {
    let mut mac = Hmac::<Sha256>::new_from_slice(key).expect("HMAC takes a key of any length");
    mac.update(data);
    hex::encode(mac.finalize().into_bytes())
}

fn key(name: &str) -> ArrayKey {
    ArrayKey::normalize(Str::from(name))
}

/// `isset($array[$name])`: present and not null.
fn isset(array: &Array, name: &str) -> bool {
    array.get_str(name).is_some_and(|v| !matches!(v, Value::Null))
}

/// `rtrim(strtr(base64_encode($data), '+/', '-_'), '=')`.
fn url_safe_encode(data: &[u8]) -> String {
    php_std::encoding::base64_encode(data).trim_end_matches('=').replace('+', "-").replace('/', "_")
}

/// `json_decode(base64_decode(strtr($data, '-_', '+/')))`, objects as
/// `stdClass`, failing as `validateLastJson()` does.
fn url_safe_decode(data: &[u8]) -> Result<Value> {
    let swapped: Vec<u8> = data
        .iter()
        .map(|b| match b {
            b'-' => b'+',
            b'_' => b'/',
            other => *other,
        })
        .collect();
    let decoded = php_std::encoding::base64_decode(&swapped, false).unwrap_or_default();
    json::decode(&decoded, None, json::DEFAULT_DEPTH, Flags::NONE)
        .map_err(|e| Error::Jwt(format!("JSON failed: {}", e.message())))
}

/// `(array) $value`: an object's properties (numeric names become integer
/// keys), an array as it is, `[]` for null, and `[$value]` for a scalar.
fn cast_array(value: Value) -> Array {
    match value {
        Value::Array(a) => a,
        Value::Object(o) => Array::from_entries(o.iter().map(|(k, v)| (normalized(k.to_owned()), v.clone())), None),
        Value::Null => Array::new(),
        other => Array::from_list(vec![other]),
    }
}

fn normalized(key: ArrayKey) -> ArrayKey {
    match key {
        ArrayKey::Str(s) => ArrayKey::normalize(s),
        int => int,
    }
}

/// `$value + 0` for a time claim: integers, floats, booleans and numeric
/// strings are numbers; anything else is the `TypeError` PHP's arithmetic
/// raises.
///
/// PHP: a leading-numeric string (`"12abc"`) is used up to its digits, with a
/// warning; here it fails too.
fn number(value: &Value) -> Result<Number> {
    match value {
        Value::Int(i) => Ok(Number::Int(*i)),
        Value::Float(f) => Ok(Number::Float(*f)),
        Value::Bool(b) => Ok(Number::Int(i64::from(*b))),
        Value::Str(s) => php_std::value::numeric_bytes_ex(s.as_bytes())
            .map(|(n, _)| n)
            .ok_or_else(|| Error::Type(format!("Unsupported operand types: int + {}", value.type_name()))),
        other => Err(Error::Type(format!("Unsupported operand types: int + {}", other.type_name()))),
    }
}
