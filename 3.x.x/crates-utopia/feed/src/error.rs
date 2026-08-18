use thiserror::Error;

/// PHP `Utopia\Feed\Exception` and `Exception\Invalid` / `Transport` / `Unsupported`.
#[derive(Debug, Clone, Error)]
pub enum FeedError {
    /// PHP `Utopia\Feed\Exception\Invalid`.
    #[error("{0}")]
    Invalid(String),
    /// PHP `Utopia\Feed\Exception\Transport`. `code` is PHP `$code` (HTTP status when set).
    #[error("{message}")]
    Transport { message: String, code: i64 },
    /// PHP `Utopia\Feed\Exception\Unsupported`.
    #[error("{0}")]
    Unsupported(String),
    /// Handler failure. PHP rethrows the original `Throwable` after committing prior events.
    #[error("{0}")]
    Handler(String),
}

/// PHP `Utopia\Feed\Exception\Invalid`.
pub type Invalid = FeedError;
/// PHP `Utopia\Feed\Exception\Transport`.
pub type Transport = FeedError;
/// PHP `Utopia\Feed\Exception\Unsupported`.
pub type Unsupported = FeedError;

impl FeedError {
    #[must_use]
    pub fn invalid(message: impl Into<String>) -> Self {
        Self::Invalid(message.into())
    }

    #[must_use]
    pub fn transport(message: impl Into<String>) -> Self {
        Self::Transport {
            message: message.into(),
            code: 0,
        }
    }

    #[must_use]
    pub fn transport_status(message: impl Into<String>, code: i64) -> Self {
        Self::Transport {
            message: message.into(),
            code,
        }
    }

    #[must_use]
    pub fn unsupported(message: impl Into<String>) -> Self {
        Self::Unsupported(message.into())
    }

    #[must_use]
    pub fn handler(message: impl Into<String>) -> Self {
        Self::Handler(message.into())
    }

    /// PHP `Exception::getCode()`.
    #[must_use]
    pub fn code(&self) -> i64 {
        match self {
            Self::Transport { code, .. } => *code,
            _ => 0,
        }
    }

    #[must_use]
    pub fn is_invalid(&self) -> bool {
        matches!(self, Self::Invalid(_))
    }

    #[must_use]
    pub fn is_transport(&self) -> bool {
        matches!(self, Self::Transport { .. })
    }

    #[must_use]
    pub fn is_unsupported(&self) -> bool {
        matches!(self, Self::Unsupported(_))
    }

    #[must_use]
    pub fn is_handler(&self) -> bool {
        matches!(self, Self::Handler(_))
    }
}
