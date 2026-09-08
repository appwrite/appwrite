use thiserror::Error;

/// PHP `Utopia\Orchestration\Exception\Orchestration` and `Timeout`.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum OrchestrationError {
    /// PHP `Utopia\Orchestration\Exception\Orchestration`.
    #[error("{0}")]
    Orchestration(String),
    /// PHP `Utopia\Orchestration\Exception\Timeout`.
    #[error("{0}")]
    Timeout(String),
}

impl OrchestrationError {
    #[must_use]
    pub fn docker(message: impl Into<String>) -> Self {
        Self::Orchestration(format!("Docker Error: {}", message.into()))
    }

    #[must_use]
    pub fn timed_out() -> Self {
        Self::Timeout("Command timed out".to_string())
    }

    #[must_use]
    pub fn is_timeout(&self) -> bool {
        matches!(self, Self::Timeout(_))
    }
}
