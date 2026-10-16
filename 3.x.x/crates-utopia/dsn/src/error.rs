use thiserror::Error;

/// Error raised while constructing a [`crate::Dsn`].
///
/// Matches PHP `InvalidArgumentException` messages from `Utopia\DSN\DSN`.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum DsnError {
    /// Unparseable DSN, missing scheme, or missing host.
    #[error("{0}")]
    InvalidArgument(String),
}

impl DsnError {
    pub(crate) fn unparseable(dsn: &str) -> Self {
        Self::InvalidArgument(format!("Unable to parse DSN: {dsn}"))
    }

    pub(crate) fn scheme_required() -> Self {
        Self::InvalidArgument("Unable to parse DSN: scheme is required".to_string())
    }

    pub(crate) fn host_required() -> Self {
        Self::InvalidArgument("Unable to parse DSN: host is required".to_string())
    }
}
