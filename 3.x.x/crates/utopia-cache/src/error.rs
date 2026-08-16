use thiserror::Error;

/// Errors that match PHP exceptions thrown by cache adapters.
#[derive(Debug, Error)]
pub enum CacheError {
    #[error("{0}")]
    Message(String),
    #[error("No adapters provided")]
    NoAdapters,
    #[error("{0} must be a directory")]
    NotADirectory(String),
    #[error("Error happened during glob")]
    Glob,
    #[error("Can't create directory {0}")]
    CreateDirectory(String),
    #[error("Failed to connect to Redis: {0}")]
    RedisConnect(String),
    #[error("Redis send failed: {0}")]
    RedisSend(String),
    #[error("Timed out waiting for Redis response")]
    RedisTimeout,
    #[error("Redis connection is not open")]
    RedisNotOpen,
    #[error("Connection closed")]
    RedisClosed,
    #[error("Unknown RESP type: {0}")]
    UnknownRespType(char),
    #[error("{0}")]
    Redis(String),
    #[error("{0}")]
    Connection(String),
    #[error("timeout must be greater than 0")]
    TimeoutMustBePositive,
    #[error("readTimeout must be greater than 0")]
    ReadTimeoutMustBePositive,
    #[error("Memcached connection failed after {attempts} attempts. Error: {error}")]
    Memcached { attempts: usize, error: String },
    #[error("Hazelcast connection failed after {attempts} attempts. Error: {error}")]
    Hazelcast { attempts: usize, error: String },
    #[error("Cache failed.")]
    AdapterFailed,
    #[error("I/O error: {0}")]
    Io(String),
}

impl CacheError {
    pub(crate) fn message(msg: impl Into<String>) -> Self {
        Self::Message(msg.into())
    }
}

impl From<std::io::Error> for CacheError {
    fn from(err: std::io::Error) -> Self {
        Self::Io(err.to_string())
    }
}

#[cfg(feature = "redis")]
impl From<redis::RedisError> for CacheError {
    fn from(err: redis::RedisError) -> Self {
        Self::Redis(err.to_string())
    }
}
