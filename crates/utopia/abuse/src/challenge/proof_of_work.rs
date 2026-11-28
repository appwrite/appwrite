//! [`ProofOfWork`]: a leading-zero-bits puzzle.

mod outcome;
mod token;

use std::future::Future;

use php_std::{Array, Value};
use sha2::{Digest, Sha256};

pub use outcome::Outcome;
pub use token::Token;

use super::issuer::key;
use super::nonce::Store;
use super::{Challenge, Nonce, Status, Tokens};
use crate::{Error, Result};

/// A leading-zero-bits proof of work. A client must find an answer whose
/// `SHA-256(nonce ":" answer)` starts with at least `difficulty` zero bits;
/// the issued token carries the nonce and the difficulty.
///
/// Issuing signs a random nonce and the difficulty into a token, with no
/// server-side write. Verifying checks the token and params, records the
/// nonce as used before checking the answer (so one token buys one attempt),
/// then checks the answer.
///
/// PHP: `Utopia\Abuse\Challenge\ProofOfWork`.
#[derive(Debug)]
pub struct ProofOfWork<S> {
    tokens: Tokens,
    nonces: Nonce<S>,
    difficulty: i64,
    accept_when_nonces_unavailable: bool,
}

impl<S> Clone for ProofOfWork<S> {
    /// Shares the nonce store.
    fn clone(&self) -> Self {
        ProofOfWork {
            tokens: self.tokens.clone(),
            nonces: self.nonces.clone(),
            difficulty: self.difficulty,
            accept_when_nonces_unavailable: self.accept_when_nonces_unavailable,
        }
    }
}

impl<S: Store> ProofOfWork<S> {
    /// The SHA-256 digest's width: the most leading zero bits a solution can
    /// have.
    ///
    /// PHP: `ProofOfWork::MAX_DIFFICULTY`.
    pub const MAX_DIFFICULTY: i64 = 256;

    /// The default token lifetime, in seconds; also how long a used nonce is
    /// remembered.
    ///
    /// PHP: `ProofOfWork::DEFAULT_TTL`.
    pub const DEFAULT_TTL: i64 = 120;

    /// Puzzles of `difficulty` leading zero bits (0 to 256; [`issue`] can
    /// override it per token), whose tokens live `ttl` seconds, signed with a
    /// key derived from `secret` and `label`, for `purpose`. `nonces`
    /// remembers used nonces, its key placeholders (such as `{project}`)
    /// filled from each token's claims. When the nonce store fails, verifying
    /// answers [`Status::Unavailable`], or checks the solution anyway when
    /// `accept_when_nonces_unavailable` is set.
    ///
    /// Fails with [`Error::InvalidArgument`] for a difficulty outside 0 to
    /// 256 (`difficulty must be between 0 and 256, got 257`), and as
    /// [`Tokens::new`] does.
    ///
    /// PHP: `new ProofOfWork($secret, $nonces, $difficulty, $ttl = 120,
    /// $acceptWhenNoncesUnavailable = false, $label = 'challenge', $purpose =
    /// 'challenge')`.
    ///
    /// [`issue`]: ProofOfWork::issue
    pub fn new(
        secret: &str,
        nonces: Nonce<S>,
        difficulty: i64,
        ttl: i64,
        accept_when_nonces_unavailable: bool,
        label: &str,
        purpose: &str,
    ) -> Result<Self> {
        check_difficulty(difficulty)?;
        Ok(ProofOfWork {
            tokens: Tokens::new(secret, label, purpose, ttl)?,
            nonces,
            difficulty,
            accept_when_nonces_unavailable,
        })
    }

    /// A puzzle bound to the params, carrying `claims`, of `difficulty`
    /// (the constructor's when `None`).
    ///
    /// Fails with [`Error::InvalidArgument`] for a difficulty outside 0 to
    /// 256 or a reserved claim name (`nonce`, `difficulty`, `iatMs`), and as
    /// [`Tokens::sign`] does.
    ///
    /// PHP: `issue($claims = [], $difficulty = null)`.
    pub fn issue(&self, claims: &Array, difficulty: Option<i64>) -> Result<Token> {
        let difficulty = difficulty.unwrap_or(self.difficulty);
        check_difficulty(difficulty)?;

        let mut bytes = [0u8; 16];
        utopia_auth::random_bytes(&mut bytes);
        let nonce = hex::encode(bytes);
        let mut own = Array::new();
        own.set(key("nonce"), Value::Str(nonce.as_str().into()));
        own.set(key("difficulty"), Value::Int(difficulty));
        own.set(key("iatMs"), Value::Int(now_ms()));
        let value = self.tokens.sign(&own, claims, 0)?;
        Ok(Token::new(value, nonce, difficulty))
    }

    /// Checks an answer. Params are checked before the nonce is used, so a
    /// token presented by another client never spends the owner's attempt.
    ///
    /// Fails with [`Error::Type`] only for a token signed with this secret
    /// whose `iatMs` is so far off that the elapsed time is not an integer,
    /// as PHP's `Result` constructor rejects it.
    ///
    /// PHP: `verify($token, $answer)`.
    pub async fn verify(&self, token: &[u8], answer: &[u8]) -> Result<Outcome> {
        let claims = match self.tokens.claims_from(token) {
            Ok(claims) => claims,
            Err(status) => return Ok(Outcome::new(status, Array::new(), 0, 0, None)),
        };

        let nonce = match claims.get_str("nonce") {
            Some(Value::Str(n)) if !n.is_empty() => n.as_bytes().to_vec(),
            _ => return Ok(Outcome::new(Status::InvalidToken, claims, 0, 0, None)),
        };
        let difficulty = match claims.get_str("difficulty") {
            Some(Value::Int(d)) if (0..=Self::MAX_DIFFICULTY).contains(d) => *d,
            _ => return Ok(Outcome::new(Status::InvalidToken, claims, 0, 0, None)),
        };

        let issued_ms = match claims.get_str("iatMs") {
            Some(Value::Int(ms)) => *ms,
            _ => 0,
        };
        let elapsed_ms = now_ms().checked_sub(issued_ms).map(|e| e.max(0)).ok_or_else(|| {
            Error::Type(
                "Utopia\\Abuse\\Challenge\\ProofOfWork\\Result::__construct(): Argument #4 ($elapsedMs) must be of type int, float given"
                    .into(),
            )
        })?;

        let mut error = None;
        let fresh = match self
            .nonces
            .with_params(&Tokens::key_params(&claims))
            .consume(&nonce, self.tokens.issuer().ttl())
            .await
        {
            Ok(fresh) => fresh,
            Err(e) if !self.accept_when_nonces_unavailable => {
                return Ok(Outcome::new(Status::Unavailable, claims, difficulty, elapsed_ms, Some(e)));
            }
            Err(e) => {
                // The replay guard did not run; the solution is still checked below.
                error = Some(e);
                true
            }
        };

        if !fresh {
            return Ok(Outcome::new(Status::Replay, claims, difficulty, elapsed_ms, None));
        }
        let status = if Self::is_solution(&nonce, answer, difficulty) { Status::Valid } else { Status::Rejected };
        Ok(Outcome::new(status, claims, difficulty, elapsed_ms, error))
    }

    /// Whether `answer` solves the puzzle: `SHA-256(nonce ":" answer)`
    /// reaches `difficulty`. One hash, the same rule on client and server.
    ///
    /// PHP: `ProofOfWork::isSolution($nonce, $answer, $difficulty)`.
    pub fn is_solution(nonce: &[u8], answer: &[u8], difficulty: i64) -> bool {
        let mut hasher = Sha256::new();
        hasher.update(nonce);
        hasher.update(b":");
        hasher.update(answer);
        Self::difficulty_of(&hasher.finalize()) >= difficulty
    }

    /// The difficulty a hash reaches: its leading zero bits, counted from the
    /// most significant bit, the order a browser counts in.
    ///
    /// PHP: `ProofOfWork::difficultyOf($hash)`.
    pub fn difficulty_of(hash: &[u8]) -> i64 {
        let mut bits = 0;
        for byte in hash {
            if *byte == 0 {
                bits += 8;
                continue;
            }
            return bits + i64::from(byte.leading_zeros());
        }
        bits
    }
}

impl<S: Store> Challenge for ProofOfWork<S> {
    type Token = Token;
    type Outcome = Outcome;

    fn tokens(&self) -> &Tokens {
        &self.tokens
    }

    fn with_tokens(&self, tokens: Tokens) -> Self {
        ProofOfWork { tokens, ..self.clone() }
    }

    /// A puzzle of the constructor's difficulty.
    fn issue(&self, claims: &Array) -> Result<Token> {
        ProofOfWork::issue(self, claims, None)
    }

    fn verify(&self, token: &[u8], answer: &[u8]) -> impl Future<Output = Result<Outcome>> + Send {
        ProofOfWork::verify(self, token, answer)
    }
}

/// Refuses a difficulty outside 0 to 256: below 0 every answer passes; above
/// the digest's width none can.
fn check_difficulty(difficulty: i64) -> Result<()> {
    if !(0..=256).contains(&difficulty) {
        return Err(Error::InvalidArgument(format!("difficulty must be between 0 and 256, got {difficulty}")));
    }
    Ok(())
}

/// `(int) (microtime(true) * 1000)`.
fn now_ms() -> i64 {
    (php_std::datetime::microtime_float() * 1000.0) as i64
}
