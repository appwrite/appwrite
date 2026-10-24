//! The error type of circuit breakers and their stores.

/// Why a breaker operation failed.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, thiserror::Error)]
pub enum Error {
    /// An option out of range, such as a window of 0 seconds.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// The shared store failed: Redis was unreachable, or a value had the
    /// wrong type.
    ///
    /// PHP: `Utopia\CircuitBreaker\Adapter\AdapterException`.
    #[error("{0}")]
    Adapter(String),
}

impl Error {
    /// The name of the exception class this error matches.
    ///
    /// PHP: the class `utopia-php/circuit-breaker` throws in the same case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Adapter(_) => "Utopia\\CircuitBreaker\\Adapter\\AdapterException",
        }
    }
}
