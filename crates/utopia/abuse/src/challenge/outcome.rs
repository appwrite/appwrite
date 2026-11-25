//! [`Outcome`]: what checking an answer found.

use php_std::Array;

use super::Status;
use crate::Error;

/// What [`super::Challenge::verify`] found. The claims are the signed
/// token's, set once the token is known to belong to these params. A kind with
/// more to report has its own outcome, as [`super::proof_of_work::Outcome`]
/// does.
///
/// PHP: `Utopia\Abuse\Challenge\Result`.
#[derive(Debug)]
pub struct Outcome {
    /// The verdict.
    pub status: Status,
    /// The token's claims; empty when the token was not read or not bound to
    /// these params.
    pub claims: Array,
    /// Why a store the challenge relies on failed, when it did.
    pub error: Option<Error>,
}

impl Outcome {
    /// An outcome of `status` with these claims and store error.
    ///
    /// PHP: `new Result($status, $claims, $error)`.
    pub fn new(status: Status, claims: Array, error: Option<Error>) -> Self {
        Outcome { status, claims, error }
    }

    /// Whether the answer passed.
    ///
    /// PHP: `isValid()`.
    pub fn is_valid(&self) -> bool {
        self.status == Status::Valid
    }
}
