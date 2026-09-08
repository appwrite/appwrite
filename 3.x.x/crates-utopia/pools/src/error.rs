use std::error::Error;
use std::fmt;

/// Boxed error used for `init` and callback failures.
pub type BoxError = Box<dyn Error + Send + Sync>;

/// Errors from [`crate::Pool`] and [`crate::Group`].
///
/// Messages match PHP `Exception` / `InvalidArgumentException` text that tests assert.
#[derive(Debug, thiserror::Error)]
pub enum PoolError {
    /// PHP `InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// PHP `Exception` when `pop()` exhausts `timeout`.
    #[error("{0}")]
    Timeout(String),
    /// PHP `Group::get()` - `Pool '{name}' not found`.
    #[error("Pool '{0}' not found")]
    NotFound(String),
    /// PHP `Group::use()` with `[]`.
    #[error("Cannot use with empty names")]
    EmptyNames,
    /// PHP `$init` threw. The inner error is the original type (not wrapped in a pool message).
    #[error("{0}")]
    Init(BoxError),
    /// Callback passed to `use_resource` / `Group::use_resources` failed.
    #[error("{0}")]
    Callback(BoxError),
    /// Adapter `synchronized()` failed (PHP Swoole lock / test doubles).
    #[error("{0}")]
    Adapter(String),
}

impl PoolError {
    pub(crate) fn invalid_size(name: &str, size: usize) -> Self {
        Self::InvalidArgument(format!(
            "Pool '{name}' size must be at least 1, got {size}."
        ))
    }

    pub(crate) fn invalid_timeout(name: &str, timeout: f64) -> Self {
        Self::InvalidArgument(format!(
            "Pool '{name}' timeout cannot be negative, got {timeout}."
        ))
    }

    pub(crate) fn timeout_exhausted(
        name: &str,
        timeout: f64,
        size: usize,
        active: usize,
        idle: usize,
    ) -> Self {
        Self::Timeout(format!(
            "Pool '{name}' could not provide a connection within {timeout}s (size {size}, active {active}, idle {idle})"
        ))
    }

    /// PHP `throw new Exception($message)` from a callback.
    #[must_use]
    pub fn callback(message: impl Into<String>) -> Self {
        Self::Callback(message.into().into())
    }

    /// The original `init` error, when this is [`PoolError::Init`].
    #[must_use]
    pub fn init_source(&self) -> Option<&(dyn Error + Send + Sync)> {
        match self {
            Self::Init(error) => Some(error.as_ref()),
            _ => None,
        }
    }
}

impl From<&str> for PoolError {
    fn from(value: &str) -> Self {
        Self::callback(value)
    }
}

impl From<String> for PoolError {
    fn from(value: String) -> Self {
        Self::callback(value)
    }
}

/// Marker matching PHP `\TypeError` from a failed `init`.
#[derive(Debug)]
pub struct TypeError {
    message: String,
}

impl TypeError {
    #[must_use]
    pub fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }
}

impl fmt::Display for TypeError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str(&self.message)
    }
}

impl Error for TypeError {}
