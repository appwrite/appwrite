//! Errors matching PHP `Exception` / `InvalidArgumentException` messages.

use thiserror::Error;

/// Errors raised while building or sending messages.
#[derive(Debug, Error)]
pub enum MessagingError {
    /// PHP `Exception('Invalid message type.')`.
    #[error("Invalid message type.")]
    InvalidMessageType,

    /// PHP `"{Name} can only send {max} messages per request."`.
    #[error("{name} can only send {max} messages per request.")]
    TooManyMessages {
        /// Adapter [`crate::Adapter::get_name`].
        name: String,
        /// [`crate::Adapter::get_max_messages_per_request`].
        max: usize,
    },

    /// PHP `Exception('Adapter does not implement process method.')`.
    #[error("Adapter does not implement process method.")]
    MissingProcess,

    /// PHP `InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),

    /// PHP `Exception` with a free-form message.
    #[error("{0}")]
    Message(String),

    /// PHP JWT `Algorithm not supported`.
    #[error("Algorithm not supported")]
    AlgorithmNotSupported,

    /// PHP JWT `OpenSSL sign failed for JWT`.
    #[error("OpenSSL sign failed for JWT")]
    JwtSignFailed,
}

impl MessagingError {
    /// PHP `InvalidArgumentException` helper.
    pub fn invalid_argument(message: impl Into<String>) -> Self {
        Self::InvalidArgument(message.into())
    }

    /// PHP `Exception` helper.
    pub fn message(message: impl Into<String>) -> Self {
        Self::Message(message.into())
    }
}
