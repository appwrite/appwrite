//! Appwrite exception types (stub).

use thiserror::Error;

/// Root Appwrite error type.
#[derive(Debug, Error)]
pub enum Exception {
    /// Generic application error.
    #[error("{0}")]
    General(String),
}

/// Placeholder constructor used by early stubs.
#[must_use]
pub fn stub() -> Exception {
    Exception::General("appwrite-exception stub".into())
}
