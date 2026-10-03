use thiserror::Error;

/// PHP `Utopia\Replication\Exception`.
#[derive(Debug, Error)]
pub enum ReplicationError {
    /// Free-form message matching PHP `Exception`.
    #[error("{0}")]
    Message(String),
}

impl ReplicationError {
    pub(crate) fn msg(message: impl Into<String>) -> Self {
        Self::Message(message.into())
    }
}
