//! Errors for Utopia Image.

use std::io;

use thiserror::Error;

/// Errors produced by [`crate::Image`].
#[derive(Debug, Error)]
pub enum ImageError {
    /// Failed to decode the input blob.
    #[error("failed to decode image: {0}")]
    Decode(String),
    /// Failed to encode the output blob.
    #[error("failed to encode image: {0}")]
    Encode(String),
    /// Output type is not a recognized Utopia Image format.
    #[error("invalid output type given")]
    InvalidType,
    /// Format is recognized but this build cannot encode/decode it.
    #[error("unsupported image format: {0}")]
    Unsupported(&'static str),
    /// Resource limit would be exceeded.
    #[error("resource limit exceeded: {0}")]
    ResourceLimit(&'static str),
    /// Invalid color string.
    #[error("invalid color: {0}")]
    InvalidColor(String),
    /// Filesystem error while saving.
    #[error(transparent)]
    Io(#[from] io::Error),
    /// Other processing failure.
    #[error("{0}")]
    Message(String),
}

/// Result alias for image operations.
pub type Result<T> = std::result::Result<T, ImageError>;
