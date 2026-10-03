//! Audit errors.

use thiserror::Error;

/// Errors raised by audit adapters and query parsing.
#[derive(Debug, Error)]
pub enum AuditError {
    #[error("{0}")]
    Message(String),
}

impl AuditError {
    pub fn message(msg: impl Into<String>) -> Self {
        Self::Message(msg.into())
    }

    pub fn get_message(&self) -> String {
        self.to_string()
    }
}

impl From<String> for AuditError {
    fn from(value: String) -> Self {
        Self::Message(value)
    }
}

impl From<&str> for AuditError {
    fn from(value: &str) -> Self {
        Self::Message(value.to_owned())
    }
}

pub type Result<T> = std::result::Result<T, AuditError>;
