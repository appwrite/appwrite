use thiserror::Error;
use utopia_domains::DomainsError;

/// Errors raised by [`crate::Email`] and canonical providers.
///
/// PHP maps constructor failures to `\Exception` and empty-after-normalization
/// failures to `\InvalidArgumentException`. Variant identity is the Rust
/// equivalent of `instanceof`.
#[derive(Debug, Error)]
pub enum EmailError {
    /// PHP `Exception('Email address cannot be empty')`.
    #[error("Email address cannot be empty")]
    Empty,

    /// PHP `Exception("'{email}' must be a valid email address")`.
    #[error("'{email}' must be a valid email address")]
    Invalid {
        /// Original constructor argument (before trim / lowercase).
        email: String,
    },

    /// PHP `InvalidArgumentException('Email local part cannot be empty after normalization')`.
    #[error("Email local part cannot be empty after normalization")]
    EmptyLocalAfterNormalization,

    /// Propagated [`utopia_domains::Domain::new`] failure.
    #[error(transparent)]
    Domain(#[from] DomainsError),
}

impl EmailError {
    /// PHP `Exception::getMessage()`.
    pub fn message(&self) -> String {
        self.to_string()
    }
}
