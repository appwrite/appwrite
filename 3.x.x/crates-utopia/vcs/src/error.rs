//! Error types matching PHP `Utopia\VCS\Exception\*` and generic `Exception`.

use thiserror::Error;

/// File was missing or could not be decoded (PHP `FileNotFound`).
#[derive(Debug, Clone, PartialEq, Eq, Error)]
#[error("{0}")]
pub struct FileNotFound(pub String);

impl FileNotFound {
    /// PHP `throw new FileNotFound()` (empty message).
    #[must_use]
    pub fn new() -> Self {
        Self(String::new())
    }
}

impl Default for FileNotFound {
    fn default() -> Self {
        Self::new()
    }
}

/// Repository (or commit/branch) was not found (PHP `RepositoryNotFound`).
#[derive(Debug, Clone, PartialEq, Eq, Error)]
#[error("{0}")]
pub struct RepositoryNotFound(pub String);

impl RepositoryNotFound {
    #[must_use]
    pub fn new(message: impl Into<String>) -> Self {
        Self(message.into())
    }
}

/// Adapter / HTTP errors (PHP `\Exception` with optional status code).
#[derive(Debug, Clone, PartialEq, Eq, Error)]
pub enum VcsError {
    /// PHP `Utopia\VCS\Exception\FileNotFound`.
    #[error("{0}")]
    FileNotFound(FileNotFound),
    /// PHP `Utopia\VCS\Exception\RepositoryNotFound`.
    #[error("{0}")]
    RepositoryNotFound(RepositoryNotFound),
    /// Generic PHP `Exception` (`getMessage()`, `getCode()`).
    #[error("{message}")]
    Exception { message: String, status: i64 },
}

impl VcsError {
    /// PHP `throw new Exception($message)`.
    #[must_use]
    pub fn message(message: impl Into<String>) -> Self {
        Self::Exception {
            message: message.into(),
            status: 0,
        }
    }

    /// PHP `throw new Exception($message, $statusCode)`.
    #[must_use]
    pub fn with_status(message: impl Into<String>, status: i64) -> Self {
        Self::Exception {
            message: message.into(),
            status,
        }
    }

    /// PHP `Exception::getCode()`.
    #[must_use]
    pub fn status(&self) -> i64 {
        match self {
            Self::Exception { status, .. } => *status,
            Self::FileNotFound(_) | Self::RepositoryNotFound(_) => 0,
        }
    }

    #[must_use]
    pub fn is_file_not_found(&self) -> bool {
        matches!(self, Self::FileNotFound(_))
    }

    #[must_use]
    pub fn is_repository_not_found(&self) -> bool {
        matches!(self, Self::RepositoryNotFound(_))
    }
}

impl From<FileNotFound> for VcsError {
    fn from(value: FileNotFound) -> Self {
        Self::FileNotFound(value)
    }
}

impl From<RepositoryNotFound> for VcsError {
    fn from(value: RepositoryNotFound) -> Self {
        Self::RepositoryNotFound(value)
    }
}
