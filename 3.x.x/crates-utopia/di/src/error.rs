use thiserror::Error;

/// Dependency was not registered on this container or its parents.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
#[error("Dependency {0} not found")]
pub struct NotFoundError(pub String);

/// Errors raised while resolving dependencies.
#[derive(Debug, Error)]
pub enum ContainerError {
    #[error(transparent)]
    NotFound(#[from] NotFoundError),
    #[error("Factory for `{id}` failed: {message}")]
    Factory { id: String, message: String },
    #[error("Type mismatch for `{id}`: expected {expected}")]
    TypeMismatch { id: String, expected: &'static str },
}

impl ContainerError {
    pub fn factory(id: impl Into<String>, message: impl Into<String>) -> Self {
        Self::Factory {
            id: id.into(),
            message: message.into(),
        }
    }
}
