//! [`Issuer`]: signed, expiring tokens for one purpose.

use php_std::sort::{SortFlags, sort};
use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str, Value};

use super::Jwt;
use super::jwt::hmac_hex;
use crate::{Error, Result};

/// Issues signed, expiring tokens for one purpose and checks the ones it
/// issued. The signing key is derived from the secret and a label, and the
/// purpose is stored in the token, so a token issued for one purpose never
/// verifies as another even when both share a secret.
///
/// PHP: `Utopia\Abuse\Challenge\Issuer`.
///
/// ```
/// use php_std::{Array, ArrayKey, Value};
/// use utopia_abuse::challenge::{Issuer, params};
///
/// let issuer = Issuer::new("token-secret", "label", "purpose", 60)?;
/// let mut claims = Array::new();
/// claims.set(ArrayKey::Str("user".into()), Value::Str("u1".into()));
/// let token = issuer.issue(&claims, 0, &params([("ip", "203.0.113.0/24")]))?;
///
/// let verified = issuer.verify(token.as_bytes()).expect("a valid token");
/// assert_eq!(verified.get_str("user"), Some(&Value::Str("u1".into())));
/// assert!(Issuer::params_match(&verified, &params([("ip", "203.0.113.0/24")])));
/// # Ok::<(), utopia_abuse::Error>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Challenge\Issuer;
///
/// $issuer = new Issuer('token-secret', 'label', 'purpose', 60);
/// $token = $issuer->issue(['user' => 'u1'], binding: ['ip' => '203.0.113.0/24']);
///
/// $claims = $issuer->verify($token);
/// echo $claims['user'], "\n"; // u1
/// var_dump(Issuer::paramsMatch($claims, ['ip' => '203.0.113.0/24'])); // bool(true)
/// ```
#[derive(Debug, Clone)]
pub struct Issuer {
    jwt: Jwt,
    purpose: String,
    ttl: i64,
}

impl Issuer {
    /// The claim holding the token's purpose.
    ///
    /// PHP: `Issuer::PURPOSE_CLAIM`.
    pub const PURPOSE_CLAIM: &'static str = "purpose";

    /// The claim listing the names of the claims that form the binding, so
    /// [`Issuer::params_match`] can require all of them.
    ///
    /// PHP: `Issuer::BINDING_CLAIM`.
    pub const BINDING_CLAIM: &'static str = "binding";

    const EXPIRY_CLAIM: &'static str = "exp";

    /// An issuer signing with a key derived from `secret` and `label`, for
    /// `purpose`, whose tokens live `ttl` seconds by default.
    ///
    /// Fails with [`Error::InvalidArgument`] for an empty secret (`secret
    /// must not be empty`), an empty label or purpose (`label and purpose must
    /// not be empty`) or a ttl under 1 (`ttl must be greater than 0`).
    ///
    /// PHP: `new Issuer($secret, $label, $purpose, $ttl)`.
    pub fn new(secret: &str, label: &str, purpose: &str, ttl: i64) -> Result<Self> {
        if secret.is_empty() {
            return Err(Error::InvalidArgument("secret must not be empty".into()));
        }
        if label.is_empty() || purpose.is_empty() {
            return Err(Error::InvalidArgument("label and purpose must not be empty".into()));
        }
        if ttl <= 0 {
            return Err(Error::InvalidArgument("ttl must be greater than 0".into()));
        }
        let jwt = Jwt::new(hmac_hex(secret.as_bytes(), label.as_bytes()).as_bytes(), ttl)?;
        Ok(Issuer { jwt, purpose: purpose.to_owned(), ttl })
    }

    /// The purpose stored in every token.
    ///
    /// PHP: `$purpose`.
    pub fn purpose(&self) -> &str {
        &self.purpose
    }

    /// The default lifetime, in seconds.
    ///
    /// PHP: `$ttl`.
    pub fn ttl(&self) -> i64 {
        self.ttl
    }

    /// Signs `claims` with this issuer's purpose and an expiry `ttl` seconds
    /// away (the default when 0 or less), bound to `binding`: its values are
    /// stored as claims and its names recorded, so [`Issuer::params_match`]
    /// can require the whole binding.
    ///
    /// Fails with [`Error::InvalidArgument`] when a name is reserved (`claim
    /// 'exp' is reserved`) or used by both the claims and the binding
    /// (`binding and claims must not share names`), and as [`Jwt::encode`]
    /// does.
    ///
    /// PHP: `issue($claims, $ttl, $binding)`.
    pub fn issue(&self, claims: &Array, ttl: i64, binding: &Array) -> Result<String> {
        for name in claims.keys().chain(binding.keys()) {
            if let KeyRef::Str(n) = name
                && [Self::PURPOSE_CLAIM, Self::EXPIRY_CLAIM, Self::BINDING_CLAIM, "iat"]
                    .iter()
                    .any(|r| r.as_bytes() == n)
            {
                return Err(reserved(name));
            }
        }
        if binding.keys().any(|k| claims.get_ref(k).is_some()) {
            return Err(Error::InvalidArgument("binding and claims must not share names".into()));
        }

        let mut names = Array::from_list(binding.keys().map(|k| key_value(k.to_owned())).collect());
        sort(&mut names, SortFlags::Regular).map_err(|e| Error::Type(e.to_string()))?;

        let lifetime = if ttl > 0 { ttl } else { self.ttl };
        let now = php_std::datetime::time();
        let expiry = match now.checked_add(lifetime) {
            Some(at) => Value::Int(at),
            None => Value::Float(now as f64 + lifetime as f64),
        };

        let mut payload = Array::new();
        payload.set(key(Self::PURPOSE_CLAIM), Value::Str(Str::from(self.purpose.as_str())));
        spread(&mut payload, claims);
        spread(&mut payload, binding);
        payload.set(key(Self::BINDING_CLAIM), Value::Array(names));
        payload.set(key(Self::EXPIRY_CLAIM), expiry);
        self.jwt.encode(&payload)
    }

    /// The claims of a token with a valid signature and an expiry not yet
    /// passed, issued for this purpose; `None` otherwise.
    ///
    /// PHP: `verify($token)`, `null` for `None`.
    pub fn verify(&self, token: &[u8]) -> Option<Array> {
        if token.is_empty() {
            return None;
        }
        let claims = self.jwt.decode(token).ok()?;
        // The library checks `exp` only when present; a token without one would never expire.
        let purpose = matches!(claims.get_str(Self::PURPOSE_CLAIM), Some(Value::Str(p)) if p.as_bytes() == self.purpose.as_bytes());
        let expires = matches!(claims.get_str(Self::EXPIRY_CLAIM), Some(Value::Int(_)));
        (purpose && expires).then_some(claims)
    }

    /// Whether verified `claims` are bound to exactly `binding`: the same
    /// names, each with the same value. Leaving a name out fails, so a caller
    /// cannot drop part of the binding by accident.
    ///
    /// PHP: `Issuer::paramsMatch($claims, $binding)`.
    pub fn params_match(claims: &Array, binding: &Array) -> bool {
        let Some(names @ Value::Array(_)) = claims.get_str(Self::BINDING_CLAIM) else {
            return false;
        };
        let mut expected = Array::from_list(binding.keys().map(|k| key_value(k.to_owned())).collect());
        if sort(&mut expected, SortFlags::Regular).is_err() || !names.strict_eq(&Value::Array(expected)) {
            return false;
        }
        binding.iter().all(|(name, value)| {
            matches!((claims.get_ref(name), value), (Some(Value::Str(c)), Value::Str(v)) if c.as_bytes() == v.as_bytes())
        })
    }
}

/// `claim '<name>' is reserved`.
pub(crate) fn reserved(name: KeyRef<'_>) -> Error {
    let name = match name {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    };
    Error::InvalidArgument(format!("claim '{name}' is reserved"))
}

/// The array key PHP uses for `name`.
pub(crate) fn key(name: &str) -> ArrayKey {
    ArrayKey::normalize(Str::from(name))
}

/// An array key as a value, as `array_keys()` returns it.
fn key_value(key: ArrayKey) -> Value {
    match key {
        ArrayKey::Int(i) => Value::Int(i),
        ArrayKey::Str(s) => Value::Str(s),
    }
}

/// `[...$a, ...$b]`.
pub(crate) fn merge(a: &Array, b: &Array) -> Array {
    let mut out = Array::new();
    spread(&mut out, a);
    spread(&mut out, b);
    out
}

/// `[...$into, ...$from]`: string keys are set (keeping an existing key's
/// position), integer keys are appended with the next free index.
pub(crate) fn spread(into: &mut Array, from: &Array) {
    for (k, v) in from.iter() {
        match k {
            KeyRef::Int(_) => {
                let _ = into.push(v.clone());
            }
            KeyRef::Str(_) => {
                into.set(k.to_owned(), v.clone());
            }
        }
    }
}
