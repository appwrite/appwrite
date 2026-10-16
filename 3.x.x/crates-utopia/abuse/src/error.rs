use thiserror::Error;

use crate::database::DatabaseError;

/// Errors raised by abuse adapters and the [`crate::Abuse`] facade.
#[derive(Debug, Error)]
pub enum AbuseError {
    /// PHP `Exception('Method not supported')`.
    #[error("Method not supported")]
    MethodNotSupported,

    /// PHP `Exception('You need to create database before running timelimit setup')`.
    #[error("You need to create database before running timelimit setup")]
    DatabaseNotCreated,

    /// PHP `InvalidArgumentException('refillRate must be greater than 0')`.
    #[error("refillRate must be greater than 0")]
    InvalidRefillRate,

    /// PHP `InvalidArgumentException('windowSize must be greater than 0')`.
    #[error("windowSize must be greater than 0")]
    InvalidWindowSize,

    /// PHP sliding-window TTL guard.
    #[error(
        "ttl must be at least twice the windowSize so the previous window bucket outlives the current window"
    )]
    InvalidTtl,

    /// PHP `Exception('Document Not Found')`.
    #[error("Document Not Found")]
    DocumentNotFound,

    /// PHP Database adapter `set()` race-handling failure.
    #[error("Unable to find abuse tracking document after race condition handling")]
    DocumentRace,

    /// PHP `TablesDB` `set()` race-handling failure.
    #[error("Unable to find abuse tracking row after race condition handling")]
    RowRace,

    /// PHP `RedisPool` `RuntimeException('Redis transaction failed.')`.
    #[error("Redis transaction failed.")]
    RedisTransaction,

    /// PHP `TablesDB` `Exception("Failed to setup {$resourceType}.")`.
    #[error("Failed to setup {0}.")]
    SetupFailed(String),

    /// PHP `TablesDB` `Exception("No endpoint for column '{$key}'.")`.
    #[error("No endpoint for column '{0}'.")]
    NoColumnEndpoint(String),

    /// No connection available in the mutex pool.
    #[error("Redis connection pool is empty")]
    PoolEmpty,

    /// reCAPTCHA / HTTP JSON could not be decoded as an object.
    #[error("invalid JSON response")]
    InvalidJson,

    /// Neighboring database backend error.
    #[error(transparent)]
    Database(#[from] DatabaseError),

    /// Redis client error.
    #[error(transparent)]
    Redis(#[from] redis::RedisError),

    /// HTTP client error (reCAPTCHA / Appwrite).
    #[error("{0}")]
    Http(String),

    /// Appwrite `TablesDB` API error (`type` field from the JSON body).
    #[error("{message}")]
    Appwrite {
        /// Human-readable message.
        message: String,
        /// Appwrite error `type` (e.g. `table_already_exists`).
        error_type: String,
        /// HTTP status code.
        code: u16,
    },

    /// Generic message matching PHP `Exception($msg)`.
    #[error("{0}")]
    Message(String),
}

impl AbuseError {
    /// Appwrite SDK `getType()` equivalent.
    #[must_use]
    pub fn appwrite_type(&self) -> Option<&str> {
        match self {
            Self::Appwrite { error_type, .. } => Some(error_type.as_str()),
            _ => None,
        }
    }
}
