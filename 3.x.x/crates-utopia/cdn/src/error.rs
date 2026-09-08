//! PHP `Utopia\Cdn\Exception\*`.

use thiserror::Error;

/// PHP `Utopia\Cdn\Exception\Configuration`.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
#[error("{0}")]
pub struct Configuration(pub String);

/// PHP `Utopia\Cdn\Exception\UnsupportedOperation`.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
#[error("{0}")]
pub struct UnsupportedOperation(pub String);

/// PHP `Utopia\Cdn\Exception\Purge`.
#[derive(Debug, Error)]
#[error("{message}")]
pub struct Purge {
    pub message: String,
    errors: Vec<CdnError>,
}

impl Purge {
    #[must_use]
    pub fn new(message: impl Into<String>, errors: Vec<CdnError>) -> Self {
        Self {
            message: message.into(),
            errors,
        }
    }

    /// PHP `Purge::getErrors()`.
    #[must_use]
    pub fn get_errors(&self) -> &[CdnError] {
        &self.errors
    }

    /// PHP `Exception::getMessage()`.
    #[must_use]
    pub fn get_message(&self) -> &str {
        &self.message
    }
}

/// Combined CDN error (PHP thrown exceptions).
#[derive(Debug, Error)]
pub enum CdnError {
    #[error("{0}")]
    InvalidArgument(String),
    #[error("{0}")]
    Configuration(#[from] Configuration),
    #[error("{0}")]
    UnsupportedOperation(#[from] UnsupportedOperation),
    #[error("{0}")]
    Purge(#[from] Purge),
    #[error("{0}")]
    Runtime(String),
}

impl CdnError {
    #[must_use]
    pub fn invalid(message: impl Into<String>) -> Self {
        Self::InvalidArgument(message.into())
    }

    #[must_use]
    pub fn runtime(message: impl Into<String>) -> Self {
        Self::Runtime(message.into())
    }
}
