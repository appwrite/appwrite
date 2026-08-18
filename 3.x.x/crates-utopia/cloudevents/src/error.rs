use thiserror::Error;

/// PHP `InvalidArgumentException` raised by `CloudEvent`.
#[derive(Debug, Error)]
pub enum CloudEventError {
    /// Free-form validation / parse error (PHP `InvalidArgumentException` message).
    #[error("{0}")]
    Invalid(String),
}

impl CloudEventError {
    pub(crate) fn invalid(message: impl Into<String>) -> Self {
        Self::Invalid(message.into())
    }
}
