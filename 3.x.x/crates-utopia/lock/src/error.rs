use std::fmt;

/// PHP `Utopia\Lock\Exception`.
#[derive(Debug, thiserror::Error)]
pub enum LockError {
    #[error("{0}")]
    Message(String),
}

impl LockError {
    #[must_use]
    pub fn new(message: impl Into<String>) -> Self {
        Self::Message(message.into())
    }
}

/// PHP `Utopia\Lock\Exception\Contention`.
#[derive(Debug, thiserror::Error)]
pub struct Contention {
    message: String,
}

impl Contention {
    #[must_use]
    pub fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
        }
    }
}

impl fmt::Display for Contention {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

impl From<Contention> for LockError {
    fn from(value: Contention) -> Self {
        Self::Message(value.message)
    }
}
