//! [`Clearance`]: what a client earns by passing a challenge.

use php_std::types::KeyRef;
use php_std::{Array, Value};

use super::Issuer;
use super::issuer::{key, merge, reserved};
use crate::Result;

/// The credential a client gets after passing a challenge, so later requests
/// can skip it. It records the difficulty that was passed and when, and is
/// bound to whatever the caller chooses (client identity, resource).
/// Verifying it is pure crypto, so the hot path touches no store.
///
/// The binding is subtractive: it can only make a copied clearance fail
/// elsewhere, never let through a client that holds none.
///
/// PHP: `Utopia\Abuse\Challenge\Clearance`.
///
/// ```
/// use utopia_abuse::challenge::{Clearance, params};
///
/// let clearance = Clearance::new("secret", Clearance::DEFAULT_TTL, "clearance", "clearance")?
///     .with_params(&params([("ip", "203.0.113.0/24"), ("ua", "ua-hash")]));
/// let pass = clearance.issue(18, 0, &params::<&str, &str>([]))?;
///
/// assert!(clearance.verify(pass.as_bytes(), 18, 0));
/// assert!(!clearance.verify(pass.as_bytes(), 20, 0));
/// assert!(!clearance.with_param("ua", "other").verify(pass.as_bytes(), 0, 0));
/// # Ok::<(), utopia_abuse::Error>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Challenge\Clearance;
///
/// $clearance = new Clearance('secret')->withParams(['ip' => '203.0.113.0/24', 'ua' => 'ua-hash']);
/// $pass = $clearance->issue(18);
///
/// var_dump($clearance->verify($pass, 18));                          // bool(true)
/// var_dump($clearance->verify($pass, 20));                          // bool(false)
/// var_dump($clearance->withParam('ua', 'other')->verify($pass));    // bool(false)
/// ```
#[derive(Debug, Clone)]
pub struct Clearance {
    issuer: Issuer,
    params: Array,
}

impl Clearance {
    /// The default lifetime, in seconds (30 minutes). Short on purpose: it
    /// tolerates IP drift.
    ///
    /// PHP: `Clearance::DEFAULT_TTL`.
    pub const DEFAULT_TTL: i64 = 1800;

    const DIFFICULTY_CLAIM: &'static str = "difficulty";

    const ISSUED_AT_CLAIM: &'static str = "issuedAt";

    /// Clearances signed with a key derived from `secret` and `label`, for
    /// `purpose`, living `ttl` seconds by default.
    ///
    /// Fails as [`Issuer::new`] does.
    ///
    /// PHP: `new Clearance($secret, $ttl = 1800, $label = 'clearance',
    /// $purpose = 'clearance')`.
    pub fn new(secret: &str, ttl: i64, label: &str, purpose: &str) -> Result<Self> {
        Ok(Clearance { issuer: Issuer::new(secret, label, purpose, ttl)?, params: Array::new() })
    }

    /// A copy with `params` added: the fields clearances are bound to, such
    /// as the client's IP prefix, User-Agent hash or the resource it was
    /// earned on. Clearances verify only with exactly the same params.
    ///
    /// PHP: `withParams($params)`.
    pub fn with_params(&self, params: &Array) -> Self {
        Clearance { issuer: self.issuer.clone(), params: merge(&self.params, params) }
    }

    /// A copy with one param added.
    ///
    /// PHP: `withParam($name, $value)`.
    pub fn with_param(&self, name: &str, value: &str) -> Self {
        self.with_params(&super::params([(name, value)]))
    }

    /// A clearance for passing a challenge of `difficulty`, living `ttl`
    /// seconds (the default when 0 or less), carrying `claims` unchecked.
    ///
    /// Fails with [`Error::InvalidArgument`](crate::Error::InvalidArgument)
    /// when a param or claim is named `difficulty` or `issuedAt` (`claim
    /// 'difficulty' is reserved`), and as [`Issuer::issue`] does.
    ///
    /// PHP: `issue($difficulty = 0, $ttl = 0, $claims = [])`.
    pub fn issue(&self, difficulty: i64, ttl: i64, claims: &Array) -> Result<String> {
        for name in self.params.keys().chain(claims.keys()) {
            if let KeyRef::Str(n) = name
                && (n == Self::DIFFICULTY_CLAIM.as_bytes() || n == Self::ISSUED_AT_CLAIM.as_bytes())
            {
                return Err(reserved(name));
            }
        }
        let mut own = Array::new();
        own.set(key(Self::DIFFICULTY_CLAIM), Value::Int(difficulty));
        // Not `iat`: the JWT library would expire the token at iat + its fixed max age.
        own.set(key(Self::ISSUED_AT_CLAIM), Value::Int(php_std::datetime::time()));
        self.issuer.issue(&merge(claims, &own), ttl, &self.params)
    }

    /// Whether `token` is a valid, unexpired clearance bound to exactly these
    /// params, earned at no less than `difficulty`, and younger than `max_age`
    /// seconds (no age limit when 0 or less). A mismatch is `false`, so the
    /// caller challenges again rather than blocking.
    ///
    /// PHP: `verify($token, $difficulty = 0, $maxAge = 0)`.
    pub fn verify(&self, token: &[u8], difficulty: i64, max_age: i64) -> bool {
        let Some(claims) = self.issuer.verify(token) else {
            return false;
        };
        if !Issuer::params_match(&claims, &self.params) {
            return false;
        }
        let (Some(Value::Int(passed)), Some(Value::Int(issued_at))) =
            (claims.get_str(Self::DIFFICULTY_CLAIM), claims.get_str(Self::ISSUED_AT_CLAIM))
        else {
            return false;
        };
        *passed >= difficulty
            && (max_age <= 0 || i128::from(*issued_at) + i128::from(max_age) > i128::from(php_std::datetime::time()))
    }
}
