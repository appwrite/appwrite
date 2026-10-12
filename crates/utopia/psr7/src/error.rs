//! The errors of building and reading HTTP messages.

/// Why an operation failed.
///
/// PHP: one variant per exception class the library raises.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// An argument was refused: a header name, a status code, a port, a URI
    /// that cannot be parsed, a parsed body that is not an array or object,
    /// an XML document that is not well formed, a multipart response without
    /// a boundary.
    ///
    /// PHP: `InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A stream or file operation failed: reading a detached stream, opening
    /// a file, moving an uploaded file.
    ///
    /// PHP: `RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// A JSON body could not be encoded or decoded.
    ///
    /// PHP: `JsonException`.
    #[error("{0}")]
    Json(String),
    /// A JSON decode depth below 1.
    ///
    /// PHP: `ValueError`.
    #[error("{0}")]
    Value(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::Json(_) => "JsonException",
            Error::Value(_) => "ValueError",
        }
    }

    /// The message, as PHP's `getMessage()` returns it.
    pub fn message(&self) -> &str {
        match self {
            Error::InvalidArgument(m) | Error::Runtime(m) | Error::Json(m) | Error::Value(m) => m,
        }
    }

    pub(crate) fn invalid(message: &str) -> Self {
        Error::InvalidArgument(message.to_owned())
    }

    pub(crate) fn runtime(message: &str) -> Self {
        Error::Runtime(message.to_owned())
    }
}
