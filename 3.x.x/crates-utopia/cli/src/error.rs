use thiserror::Error;
use utopia_di::ContainerError;

/// Errors raised while constructing or dispatching a CLI.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum CliError {
    /// PHP `Exception('Missing command')` from [`crate::Cli::parse`].
    #[error("Missing command")]
    MissingCommand,
    /// PHP `Exception('No command found')` when [`crate::Cli::match_task`] is `None`.
    #[error("No command found")]
    NoCommandFound,
    /// PHP `Exception('Failed to find resource: "…"')`.
    #[error("Failed to find resource: \"{0}\"")]
    ResourceNotFound(String),
    /// PHP `Exception('Validator object is not an instance of the Validator class', 500)`.
    #[error("Validator object is not an instance of the Validator class")]
    InvalidValidator,
    /// PHP `Exception('Invalid {key}: {description}', 400)`.
    #[error("Invalid {key}: {description}")]
    InvalidParam { key: String, description: String },
    /// PHP `Exception('Param "{key}" is not optional.', 400)`.
    #[error("Param \"{key}\" is not optional.")]
    ParamRequired { key: String },
    /// DI factory / type errors surfaced through resource lookup.
    #[error("{0}")]
    Container(String),
}

impl CliError {
    /// PHP `Exception::getCode()`.
    pub fn code(&self) -> i32 {
        match self {
            Self::InvalidValidator => 500,
            Self::InvalidParam { .. } | Self::ParamRequired { .. } => 400,
            _ => 0,
        }
    }
}

impl From<ContainerError> for CliError {
    fn from(err: ContainerError) -> Self {
        match err {
            ContainerError::NotFound(not_found) => Self::ResourceNotFound(not_found.0),
            other => Self::Container(other.to_string()),
        }
    }
}
