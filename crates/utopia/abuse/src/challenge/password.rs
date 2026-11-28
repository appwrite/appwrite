//! [`Password`]: a password the caller set.

use std::sync::Arc;

use php_std::Array;
use sha2::{Digest, Sha256};
use utopia_auth::proofs::Proof;

use super::{Challenge, Outcome, Status, Token, Tokens};
use crate::{Error, Result};

/// Asks the client for a password the caller set, such as on a firewall rule.
/// Only a hash of it is kept, checked with a utopia-auth [`Proof`]. Create
/// one with `utopia_auth::proofs::Password::new().hash(...)` (Argon2, as
/// Appwrite stores user passwords). The default proof also checks Bcrypt;
/// for PHPass (`$P$`), Scrypt, SHA or MD5 hashes, pass a proof set to that
/// hash.
///
/// It does not limit guesses: rate-limit [`Challenge::verify`] per client
/// before calling it.
///
/// PHP: `Utopia\Abuse\Challenge\Password`.
///
/// ```
/// use utopia_abuse::challenge::{Challenge, Password, Status, params};
/// use utopia_auth::proofs::{Password as Proof, Proof as _};
///
/// # utopia_async::Builder::current_thread().build()?.block_on(async {
/// let hash = Proof::new().hash(b"open sesame")?;
/// let password = Password::new("secret", &hash, Proof::new(), Password::<Proof>::DEFAULT_TTL, "password", "password")?
///     .with_params(&params([("ip", "203.0.113.0/24")]));
/// let token = password.issue(&params::<&str, &str>([]))?;
///
/// assert_eq!(password.verify(token.value.as_bytes(), b"open sesame").await?.status, Status::Valid);
/// assert_eq!(password.verify(token.value.as_bytes(), b"wrong").await?.status, Status::Rejected);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Challenge\Password;
/// use Utopia\Auth\Proofs\Password as Proof;
///
/// $password = new Password('secret', new Proof()->hash('open sesame'))->withParams(['ip' => '203.0.113.0/24']);
/// $token = $password->issue();
///
/// echo $password->verify($token->value, 'open sesame')->status->value, "\n"; // valid
/// echo $password->verify($token->value, 'wrong')->status->value, "\n";       // rejected
/// ```
#[derive(Debug)]
pub struct Password<P> {
    tokens: Tokens,
    hash: Arc<[u8]>,
    proof: Arc<P>,
}

impl<P> Clone for Password<P> {
    /// Shares the hash and the proof.
    fn clone(&self) -> Self {
        Password { tokens: self.tokens.clone(), hash: self.hash.clone(), proof: self.proof.clone() }
    }
}

impl<P: Proof + Send + Sync + 'static> Password<P> {
    /// The default token lifetime, in seconds: how long the form stays valid.
    ///
    /// PHP: `Password::DEFAULT_TTL`.
    pub const DEFAULT_TTL: i64 = 600;

    /// The length of [`Password::version`], in hex characters.
    const VERSION_LENGTH: usize = 16;

    /// A password challenge for the password `hash` is a hash of, checked
    /// with `proof` (set it to the hash's algorithm unless that is Argon2 or
    /// Bcrypt), whose tokens live `ttl` seconds, signed with a key derived
    /// from `secret` and `label`, for `purpose`.
    ///
    /// Fails with [`Error::InvalidArgument`] for an empty hash (`hash must not
    /// be empty`), and as [`Tokens::new`] does.
    ///
    /// PHP: `new Password($secret, $hash, $proof = new
    /// \Utopia\Auth\Proofs\Password(), $ttl = 600, $label = 'password',
    /// $purpose = 'password')`.
    pub fn new(secret: &str, hash: &[u8], proof: P, ttl: i64, label: &str, purpose: &str) -> Result<Self> {
        if hash.is_empty() {
            return Err(Error::InvalidArgument("hash must not be empty".into()));
        }
        Ok(Password { tokens: Tokens::new(secret, label, purpose, ttl)?, hash: hash.into(), proof: Arc::new(proof) })
    }

    /// A short identifier of the stored hash: the first 16 hex characters of
    /// its SHA-256. Hashing again, even the same password, changes it (a new
    /// salt), so binding a clearance to it ends every clearance earned with
    /// the old password.
    ///
    /// PHP: `version()`.
    pub fn version(&self) -> String {
        let mut version = hex::encode(Sha256::digest(&self.hash));
        version.truncate(Self::VERSION_LENGTH);
        version
    }
}

impl<P: Proof + Send + Sync + 'static> Challenge for Password<P> {
    type Token = Token;
    type Outcome = Outcome;

    fn tokens(&self) -> &Tokens {
        &self.tokens
    }

    fn with_tokens(&self, tokens: Tokens) -> Self {
        Password { tokens, hash: self.hash.clone(), proof: self.proof.clone() }
    }

    /// A form token bound to the params, carrying `claims`.
    ///
    /// PHP: `issue($claims = [])`.
    fn issue(&self, claims: &Array) -> Result<Token> {
        Ok(Token::new(self.tokens.sign(&Array::new(), claims, 0)?))
    }

    /// Checks `answer` against the stored hash, on the blocking pool. An
    /// empty guess never matches, so a hash of an empty password cannot let
    /// anyone through.
    ///
    /// Fails with [`Error::Auth`] when the proof fails to check the hash.
    ///
    /// PHP: `verify($token, $answer)`.
    async fn verify(&self, token: &[u8], answer: &[u8]) -> Result<Outcome> {
        let claims = match self.tokens.claims_from(token) {
            Ok(claims) => claims,
            Err(status) => return Ok(Outcome::new(status, Array::new(), None)),
        };
        let matches = !answer.is_empty() && {
            let (proof, hash, answer) = (self.proof.clone(), self.hash.clone(), answer.to_vec());
            utopia_async::spawn_blocking(move || proof.verify(&answer, &hash))
                .await
                .map_err(|e| Error::Runtime(e.to_string()))?
                .map_err(Error::Auth)?
        };
        Ok(Outcome::new(if matches { Status::Valid } else { Status::Rejected }, claims, None))
    }
}
