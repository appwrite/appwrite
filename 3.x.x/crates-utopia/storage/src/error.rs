use std::collections::HashMap;

use thiserror::Error;

/// Base error type for storage operations.
#[derive(Debug, Error)]
pub enum StorageError {
    #[error("not found: {0}")]
    NotFound(#[from] NotFound),

    #[error("upload error: {0}")]
    Upload(#[from] UploadError),

    #[error("{0}")]
    Io(#[from] std::io::Error),

    #[error("remote error ({status}): {message}{request_ids}", request_ids = format_request_ids(request_ids))]
    Remote {
        status: u16,
        error_code: Option<String>,
        message: String,
        request_ids: HashMap<String, String>,
    },

    #[error("transport error: {message}")]
    Transport {
        message: String,
        #[source]
        source: Option<Box<dyn std::error::Error + Send + Sync>>,
    },

    #[error("{0}")]
    Message(String),
}

/// A requested file or directory does not exist.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
#[error("{0}")]
pub struct NotFound(pub String);

/// A chunked upload is in an invalid state or could not be finalized.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
#[error("{0}")]
pub struct UploadError(pub String);

impl StorageError {
    pub fn message(message: impl Into<String>) -> Self {
        Self::Message(message.into())
    }

    pub fn remote(
        status: u16,
        error_code: Option<String>,
        message: impl Into<String>,
        request_ids: HashMap<String, String>,
    ) -> Self {
        Self::Remote {
            status,
            error_code,
            message: message.into(),
            request_ids,
        }
    }

    pub fn transport(message: impl Into<String>) -> Self {
        Self::Transport {
            message: message.into(),
            source: None,
        }
    }

    pub fn transport_with_source(
        message: impl Into<String>,
        source: impl std::error::Error + Send + Sync + 'static,
    ) -> Self {
        Self::Transport {
            message: message.into(),
            source: Some(Box::new(source)),
        }
    }
}

fn format_request_ids(request_ids: &HashMap<String, String>) -> String {
    if request_ids.is_empty() {
        return String::new();
    }

    let mut ids = request_ids.iter().collect::<Vec<_>>();
    ids.sort_by(|(left, _), (right, _)| left.cmp(right));
    let joined = ids
        .into_iter()
        .map(|(key, value)| format!("{key}: {value}"))
        .collect::<Vec<_>>()
        .join(", ");
    format!(" [{joined}]")
}
