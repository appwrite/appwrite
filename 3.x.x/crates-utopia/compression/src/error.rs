use thiserror::Error;

/// Errors produced by compression and decompression operations.
#[derive(Debug, Error)]
pub enum CompressionError {
    #[error("compression failed: {0}")]
    Compress(String),

    #[error("decompression failed: {0}")]
    Decompress(String),

    #[error("level must be between {min} and {max}")]
    InvalidLevel { min: i32, max: i32 },

    #[error("algorithm {0} is not supported in this build")]
    Unsupported(&'static str),
}
