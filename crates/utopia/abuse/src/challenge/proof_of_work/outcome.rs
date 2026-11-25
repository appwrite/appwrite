//! [`Outcome`]: what checking a proof of work found.

use php_std::Array;

use crate::Error;
use crate::challenge::Status;

/// A proof-of-work outcome: how hard the answered puzzle was and how long the
/// client took, both 0 when the token could not be read.
///
/// PHP: `Utopia\Abuse\Challenge\ProofOfWork\Result`.
#[derive(Debug)]
pub struct Outcome {
    /// The verdict.
    pub status: Status,
    /// The token's claims; empty when the token was not read or not bound to
    /// these params.
    pub claims: Array,
    /// The leading zero bits the answer had to reach.
    pub difficulty: i64,
    /// Milliseconds between issue and verify.
    pub elapsed_ms: i64,
    /// Why the nonce store failed, when it did.
    pub error: Option<Error>,
}

impl Outcome {
    /// A proof-of-work outcome.
    ///
    /// PHP: `new Result($status, $claims, $difficulty, $elapsedMs, $error)`.
    pub fn new(status: Status, claims: Array, difficulty: i64, elapsed_ms: i64, error: Option<Error>) -> Self {
        Outcome { status, claims, difficulty, elapsed_ms, error }
    }

    /// Whether the answer passed.
    ///
    /// PHP: `isValid()`, inherited from `Challenge\Result`.
    pub fn is_valid(&self) -> bool {
        self.status == Status::Valid
    }
}
