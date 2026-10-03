use thiserror::Error;
use utopia_di::ContainerError;
use utopia_servers::HookError;

/// Queue, broker, connection, and job-validation failures.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum QueueError {
    #[error("{0}")]
    InvalidArgument(String),
    #[error("{message}")]
    Failed { message: String, code: u16 },
    #[error("{0}")]
    Redis(String),
    #[error("{0}")]
    Nats(String),
    #[error("NATS broker requires the `nats` feature")]
    NatsDisabled,
    #[error("{0}")]
    Io(String),
    #[error("{0}")]
    Hook(String),
    #[error("{0}")]
    Container(String),
    #[error("{0}")]
    Other(String),
}

impl QueueError {
    pub fn invalid_argument(msg: impl Into<String>) -> Self {
        Self::InvalidArgument(msg.into())
    }

    pub fn failed(message: impl Into<String>, code: u16) -> Self {
        Self::Failed {
            message: message.into(),
            code,
        }
    }

    /// PHP `Invalid {key}: {description}` (HTTP 400).
    pub fn invalid_param(key: &str, description: &str) -> Self {
        Self::failed(format!("Invalid {key}: {description}"), 400)
    }

    /// PHP `Param {key} is not optional.` (HTTP 400).
    pub fn param_not_optional(key: &str) -> Self {
        Self::failed(format!("Param {key} is not optional."), 400)
    }

    /// PHP `Validator object is not an instance of the Validator class` (HTTP 500).
    pub fn invalid_validator() -> Self {
        Self::failed(
            "Validator object is not an instance of the Validator class",
            500,
        )
    }

    pub fn redis(msg: impl Into<String>) -> Self {
        Self::Redis(msg.into())
    }

    pub fn is_redis(&self) -> bool {
        matches!(self, Self::Redis(_))
    }

    pub fn code(&self) -> Option<u16> {
        match self {
            Self::Failed { code, .. } => Some(*code),
            _ => None,
        }
    }
}

impl From<HookError> for QueueError {
    fn from(value: HookError) -> Self {
        Self::Hook(value.to_string())
    }
}

impl From<ContainerError> for QueueError {
    fn from(value: ContainerError) -> Self {
        Self::Container(value.to_string())
    }
}

impl From<std::io::Error> for QueueError {
    fn from(value: std::io::Error) -> Self {
        Self::Io(value.to_string())
    }
}
