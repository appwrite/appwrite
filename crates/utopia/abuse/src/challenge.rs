//! Challenges a client must answer before it gets through: a proof-of-work
//! puzzle ([`ProofOfWork`]) or a password ([`Password`]), and the
//! [`Clearance`] a client earns by passing one, so later requests can skip
//! it.
//!
//! A challenge issues a signed token bound to the client (its params, such
//! as an IP prefix and a User-Agent hash from [`Identity`]); the client sends
//! it back with its answer, and [`Challenge::verify`] says whether the answer
//! passed, as a [`Status`]. Tokens are JSON Web Tokens both runtimes read
//! ([`Jwt`], signed by an [`Issuer`]), so a puzzle PHP issued verifies in Rust
//! and the other way round.
//!
//! A new kind of challenge implements [`Challenge`] over [`Tokens`], which
//! signs its claims and checks the binding.
//!
//! ```
//! use utopia_abuse::challenge::{Challenge, Nonce, ProofOfWork, Status, nonce, params};
//!
//! # utopia_async::Builder::current_thread().build()?.block_on(async {
//! let pow = ProofOfWork::new("secret", Nonce::new("pow", nonce::None, &params::<&str, &str>([])), 4, 120, false, "challenge", "challenge")?
//!     .with_params(&params([("ip", "203.0.113.0/24")]));
//! let puzzle = pow.issue(&params::<&str, &str>([]), None)?;
//!
//! let answer = (0..).map(|n: u64| n.to_string()).find(|a| ProofOfWork::<nonce::None>::is_solution(puzzle.nonce.as_bytes(), a.as_bytes(), 4)).unwrap();
//! let outcome = pow.verify(puzzle.value.as_bytes(), answer.as_bytes()).await?;
//! assert_eq!(outcome.status, Status::Valid);
//! # Ok::<(), utopia_abuse::Error>(())
//! # })?;
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! ```php
//! use Utopia\Abuse\Challenge\Nonce\None;
//! use Utopia\Abuse\Challenge\ProofOfWork;
//!
//! $pow = new ProofOfWork('secret', new None('pow'), 4)->withParams(['ip' => '203.0.113.0/24']);
//! $puzzle = $pow->issue();
//!
//! $answer = 0;
//! while (!ProofOfWork::isSolution($puzzle->nonce, (string) $answer, 4)) {
//!     $answer++;
//! }
//! echo $pow->verify($puzzle->value, (string) $answer)->status->value, "\n"; // valid
//! ```
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Abuse\Challenge` | [`Challenge`], over [`Tokens`] (`__construct`, `sign()`, `claimsFrom()`, `keyParams()`) |
//! | `Challenge\Clearance` | [`Clearance`] |
//! | `Challenge\Identity` | [`Identity`] |
//! | `Challenge\Issuer` | [`Issuer`], signing with [`Jwt`] (PHP: `Ahc\Jwt\JWT`) |
//! | `Challenge\Nonce` | [`Nonce`] over a [`nonce::Store`] |
//! | `Challenge\Nonce\None`, `Redis`, `RedisCluster`, `RedisPool` | [`nonce::None`], [`nonce::Redis`], [`nonce::RedisCluster`], [`nonce::RedisPool`] |
//! | `Challenge\Password` | [`Password`] |
//! | `Challenge\ProofOfWork` | [`ProofOfWork`] |
//! | `Challenge\ProofOfWork\Result`, `ProofOfWork\Token` | [`proof_of_work::Outcome`], [`proof_of_work::Token`] |
//! | `Challenge\Result` | [`Outcome`] |
//! | `Challenge\Status` | [`Status`] |
//! | `Challenge\Token` | [`Token`] |

mod clearance;
mod identity;
mod issuer;
pub mod jwt;
pub mod nonce;
mod outcome;
mod password;
pub mod proof_of_work;
mod status;
mod token;

use std::future::Future;

use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str, Value};

pub use clearance::Clearance;
pub use identity::Identity;
pub use issuer::Issuer;
pub use jwt::Jwt;
pub use nonce::Nonce;
pub use outcome::Outcome;
pub use password::Password;
pub use proof_of_work::ProofOfWork;
pub use status::Status;
pub use token::Token;

use crate::Result;

/// A PHP array literal of string pairs, `['ip' => '203.0.113.0/24', ...]`:
/// the params a challenge, clearance or nonce store is bound to, or a
/// token's claims. Numeric names become integer keys, as in PHP, and a name
/// given twice keeps its first position and its last value.
pub fn params<K: AsRef<[u8]>, V: AsRef<[u8]>>(pairs: impl IntoIterator<Item = (K, V)>) -> Array {
    let mut array = Array::new();
    for (name, value) in pairs {
        array.set(ArrayKey::normalize(Str::from(name.as_ref())), Value::Str(Str::from(value.as_ref())));
    }
    array
}

/// Something a client must answer before it gets through. Each kind issues
/// its tokens and checks answers; what every kind shares (the signed token
/// and the params it is bound to) is its [`Tokens`].
///
/// PHP: `Utopia\Abuse\Challenge`, whose `issue()` and `verify()` each kind
/// implements.
pub trait Challenge: Sized {
    /// What [`Challenge::issue`] returns.
    type Token;
    /// What [`Challenge::verify`] returns.
    type Outcome;

    /// The kind's signed tokens and params.
    fn tokens(&self) -> &Tokens;

    /// A copy of this challenge over `tokens`.
    fn with_tokens(&self, tokens: Tokens) -> Self;

    /// Issues the signed token the client sends back with its answer, bound
    /// to the params. Claims are carried through and returned by
    /// [`Challenge::verify`].
    ///
    /// PHP: `issue($claims)`.
    fn issue(&self, claims: &Array) -> Result<Self::Token>;

    /// Checks the client's answer to a token issued with the same params.
    ///
    /// PHP: `verify($token, $answer)`.
    fn verify(&self, token: &[u8], answer: &[u8]) -> impl Future<Output = Result<Self::Outcome>> + Send;

    /// A copy with `params` added: the fields tokens are bound to, such as
    /// the client's IP prefix and User-Agent hash. A token verifies only with
    /// exactly the same params.
    ///
    /// PHP: `withParams($params)`.
    fn with_params(&self, params: &Array) -> Self {
        self.with_tokens(self.tokens().with_params(params))
    }

    /// A copy with one param added.
    ///
    /// PHP: `withParam($name, $value)`.
    fn with_param(&self, name: &str, value: &str) -> Self {
        self.with_params(&params([(name, value)]))
    }
}

/// What every [`Challenge`] shares: an [`Issuer`] for its purpose and the
/// params its tokens are bound to.
///
/// PHP: the state and protected methods of `Utopia\Abuse\Challenge`.
#[derive(Debug, Clone)]
pub struct Tokens {
    issuer: Issuer,
    params: Array,
}

impl Tokens {
    /// Tokens signed with a key derived from `secret` and `label`, for
    /// `purpose`, living `ttl` seconds, bound to no params yet.
    ///
    /// Fails as [`Issuer::new`] does.
    ///
    /// PHP: `parent::__construct($secret, $label, $purpose, $ttl)`.
    pub fn new(secret: &str, label: &str, purpose: &str, ttl: i64) -> Result<Self> {
        Ok(Tokens { issuer: Issuer::new(secret, label, purpose, ttl)?, params: Array::new() })
    }

    /// The issuer.
    pub fn issuer(&self) -> &Issuer {
        &self.issuer
    }

    /// The params tokens are bound to.
    pub fn params(&self) -> &Array {
        &self.params
    }

    /// A copy with `params` added (`[...$this->params, ...$params]`).
    ///
    /// PHP: `withParams($params)`.
    pub fn with_params(&self, params: &Array) -> Self {
        Tokens { issuer: self.issuer.clone(), params: issuer::merge(&self.params, params) }
    }

    /// Signs the kind's `own` claims plus the caller's `claims`, bound to the
    /// params, living `ttl` seconds (the default when 0 or less).
    ///
    /// Fails with [`Error::InvalidArgument`](crate::Error::InvalidArgument)
    /// when a param or caller claim reuses the name of one of the kind's own
    /// (`claim 'answer' is reserved`), and as [`Issuer::issue`] does.
    ///
    /// PHP: `sign($own, $claims, $ttl)`.
    pub fn sign(&self, own: &Array, claims: &Array, ttl: i64) -> Result<String> {
        for name in self.params.keys().chain(claims.keys()) {
            if own.get_ref(name).is_some() {
                return Err(issuer::reserved(name));
            }
        }
        self.issuer.issue(&issuer::merge(claims, own), ttl, &self.params)
    }

    /// The claims of a token this kind issued for these exact params, or the
    /// status to answer: [`Status::InvalidToken`] or
    /// [`Status::BindingMismatch`].
    ///
    /// PHP: `claimsFrom($token)`, which returns the failed `Result` instead.
    pub fn claims_from(&self, token: &[u8]) -> std::result::Result<Array, Status> {
        let claims = self.issuer.verify(token).ok_or(Status::InvalidToken)?;
        if !Issuer::params_match(&claims, &self.params) {
            return Err(Status::BindingMismatch);
        }
        Ok(claims)
    }

    /// Params that fill a store's key pattern, from a token's claims:
    /// `"{name}" => value` for each string or integer claim, so a key such as
    /// `pow:{project}` is scoped by what the token was issued for.
    ///
    /// PHP: `Challenge::keyParams($claims)`.
    pub fn key_params(claims: &Array) -> Array {
        let mut out = Array::new();
        for (name, value) in claims.iter() {
            let value = match value {
                Value::Str(s) => s.clone(),
                Value::Int(i) => Str::from(i.to_string()),
                _ => continue,
            };
            let mut key = b"{".to_vec();
            match name {
                KeyRef::Int(i) => key.extend_from_slice(i.to_string().as_bytes()),
                KeyRef::Str(s) => key.extend_from_slice(s),
            }
            key.push(b'}');
            out.set(ArrayKey::normalize(Str::from(key)), Value::Str(value));
        }
        out
    }
}
