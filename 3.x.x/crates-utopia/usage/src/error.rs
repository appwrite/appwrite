//! Usage errors.

use thiserror::Error;

#[derive(Debug, Error)]
pub enum UsageError {
    #[error("{0}")]
    Message(String),
}

impl UsageError {
    pub fn message(msg: impl Into<String>) -> Self {
        Self::Message(msg.into())
    }
}

impl From<String> for UsageError {
    fn from(value: String) -> Self {
        Self::Message(value)
    }
}

impl From<&str> for UsageError {
    fn from(value: &str) -> Self {
        Self::Message(value.to_owned())
    }
}

pub type Result<T> = std::result::Result<T, UsageError>;
