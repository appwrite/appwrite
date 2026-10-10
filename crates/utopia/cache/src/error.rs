//! [`Error`]: one variant per exception class the cache raises.

/// What a cache operation failed with. Each variant carries the message of,
/// and maps onto, one exception class ([`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// A generic failure (`Exception`): a directory the filesystem adapter
    /// cannot create or delete, a sharding adapter without shards, a pool
    /// holding something other than adapters.
    #[error("{0}")]
    Exception(String),
    /// An argument out of range (`InvalidArgumentException`).
    #[error("{0}")]
    InvalidArgument(String),
    /// A codec could not encode or decode a value (`RuntimeException`).
    #[error("{0}")]
    Runtime(String),
    /// JSON encoding or decoding failed (`JsonException`).
    #[error("{0}")]
    Json(String),
    /// A Redis connection failure after the retries ran out (`RedisException`).
    #[error("{0}")]
    Redis(String),
    /// A Redis Cluster failure after the retries ran out (`RedisClusterException`).
    #[error("{0}")]
    RedisCluster(String),
    /// A Memcached or Hazelcast connection failure after the retries ran out
    /// (`MemcachedException`).
    #[error("{0}")]
    Memcached(String),
    /// The multiplexed Redis connection is unusable: the socket is gone, a
    /// frame did not parse, a send was truncated (`ConnectionException`).
    #[error("{0}")]
    Connection(String),
    /// One caller's read deadline expired; the connection is still healthy
    /// (`TimeoutException`).
    #[error("{0}")]
    Timeout(String),
    /// The multiplexed connection made no progress for longer than the
    /// liveness timeout and was torn down (`IdleConnectionException`).
    #[error("{0}")]
    Idle(String),
    /// An engine error (`Error`), such as an object with no string form
    /// written to a file.
    #[error("{0}")]
    Engine(String),
    /// A value of the wrong type where a string or array is expected
    /// (`TypeError`). PHP's message also names the calling file and line.
    #[error("{0}")]
    Type(String),
    /// The server has no script cached for an `EVALSHA` digest (`NoScript`).
    #[error("{0}")]
    NoScript(String),
}

impl Error {
    /// The exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception(_) => "Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::Json(_) => "JsonException",
            Error::Redis(_) => "RedisException",
            Error::RedisCluster(_) => "RedisClusterException",
            Error::Memcached(_) => "MemcachedException",
            Error::Connection(_) => "Utopia\\Cache\\Adapter\\Redis\\ConnectionException",
            Error::Timeout(_) => "Utopia\\Cache\\Adapter\\Redis\\TimeoutException",
            Error::Idle(_) => "Utopia\\Cache\\Adapter\\Redis\\IdleConnectionException",
            Error::Engine(_) => "Error",
            Error::Type(_) => "TypeError",
            Error::NoScript(_) => "Utopia\\Cache\\Adapter\\Redis\\NoScript",
        }
    }

    /// Whether the error is about the multiplexed connection rather than one
    /// command (`ConnectionException` and its subclasses).
    pub fn is_connection(&self) -> bool {
        matches!(self, Error::Connection(_) | Error::Timeout(_) | Error::Idle(_))
    }
}

/// A cache result.
pub type Result<T, E = Error> = std::result::Result<T, E>;
