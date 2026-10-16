use thiserror::Error;

/// Errors raised by Utopia detectors.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum DetectorError {
    /// PHP `InvalidArgumentException` from `Detector\Framework::addInput`.
    #[error("Invalid input type '{0}'")]
    InvalidInputType(String),

    /// PHP `Exception` from `Detector\Strategy::__construct`.
    #[error("Invalid strategy: {0}")]
    InvalidStrategy(String),
}
