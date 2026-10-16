/// PHP `Utopia\CircuitBreaker` errors.
#[derive(Debug, thiserror::Error)]
pub enum CircuitBreakerError {
    #[error("{0}")]
    InvalidArgument(String),
    #[error("{0}")]
    Adapter(String),
}

impl CircuitBreakerError {
    #[must_use]
    pub fn empty_key() -> Self {
        Self::InvalidArgument("Key must not be empty when a cache adapter is configured.".into())
    }
}
