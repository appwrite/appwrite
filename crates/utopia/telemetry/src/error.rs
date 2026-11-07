//! The error type of telemetry transports.

/// Why exporting telemetry failed.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, thiserror::Error)]
pub enum Error {
    /// The export failed: the transport was shut down, the collector could
    /// not be reached, or it answered with an error status.
    ///
    /// PHP: `Utopia\Telemetry\Exception`.
    #[error("{0}")]
    Telemetry(String),
    /// An invalid argument, such as a malformed endpoint URL.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
}

impl Error {
    /// The name of the exception class this error matches.
    ///
    /// PHP: the class `utopia-php/telemetry` throws in the same case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Telemetry(_) => "Utopia\\Telemetry\\Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
        }
    }
}
