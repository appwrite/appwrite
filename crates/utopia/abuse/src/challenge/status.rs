//! [`Status`]: the outcome of checking an answer.

/// The outcome of verifying an answer to any challenge. Each failed check
/// has its own case, so the caller can log which one rejected the attempt.
///
/// PHP: `Utopia\Abuse\Challenge\Status`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Status {
    /// Token, binding and answer all passed.
    Valid,
    /// A bad signature, an expired token, one issued for another purpose, or
    /// a malformed one.
    InvalidToken,
    /// The token is bound to different params, such as another client's.
    BindingMismatch,
    /// The answer is wrong.
    Rejected,
    /// The token was already answered: the submission is a replay.
    Replay,
    /// A store the challenge needs could not be reached, and the challenge
    /// requires it.
    Unavailable,
}

impl Status {
    /// The case's value: `valid`, `invalid_token`, `binding_mismatch`,
    /// `rejected`, `replay` or `unavailable`.
    ///
    /// PHP: `Status::$value`.
    pub fn as_str(self) -> &'static str {
        match self {
            Status::Valid => "valid",
            Status::InvalidToken => "invalid_token",
            Status::BindingMismatch => "binding_mismatch",
            Status::Rejected => "rejected",
            Status::Replay => "replay",
            Status::Unavailable => "unavailable",
        }
    }

    /// The case with this value, if any.
    ///
    /// PHP: `Status::tryFrom($value)`.
    pub fn from_value(value: &str) -> Option<Status> {
        Some(match value {
            "valid" => Status::Valid,
            "invalid_token" => Status::InvalidToken,
            "binding_mismatch" => Status::BindingMismatch,
            "rejected" => Status::Rejected,
            "replay" => Status::Replay,
            "unavailable" => Status::Unavailable,
            _ => return None,
        })
    }
}
