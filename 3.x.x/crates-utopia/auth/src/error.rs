//! Error types for `utopia-auth`.

use thiserror::Error;

/// Errors returned by hashing, proof, store, and JWT operations.
#[derive(Debug, Error)]
pub enum AuthError {
    /// A required configuration value is missing or invalid.
    #[error("{0}")]
    InvalidInput(String),

    /// Password hashing failed.
    #[error("hashing failed: {0}")]
    HashingFailed(String),

    /// JSON serialization or deserialization failed.
    #[error("json error: {0}")]
    Json(#[from] serde_json::Error),

    /// Base64 decoding failed.
    #[error("invalid base64 encoding")]
    InvalidBase64,

    /// JWT signing failed.
    #[error("token signing failed: {0}")]
    SigningFailed(String),

    /// JWT verification failed.
    #[error("{0}")]
    Verification(String),
}

/// Thrown when a token fails verification.
pub type VerificationException = AuthError;
