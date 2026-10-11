//! The error type shared by every rate limit and store.

/// Why a rate-limit operation failed.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// A limit built with an invalid setting, such as a window of zero
    /// seconds.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A store answered with something that is not a count, such as a Redis
    /// script that failed.
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// A store failed in a way its own library reports as a plain exception,
    /// such as a counter document that vanished.
    ///
    /// PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
    /// A token bucket that refills at zero tokens a second was asked when it
    /// is full again.
    ///
    /// PHP: `\DivisionByZeroError`.
    #[error("{0}")]
    DivisionByZero(String),
    /// A time computed past the range of a 64-bit integer.
    ///
    /// PHP: `\TypeError`, raised when the overflowed float reaches
    /// `Utopia\Abuse\Result`'s `int` properties.
    #[error("{0}")]
    Type(String),
    /// A document or row that already exists.
    ///
    /// PHP: `Utopia\Database\Exception\Duplicate`.
    #[error("{0}")]
    Duplicate(String),
    /// An Appwrite API error, with its type (`row_already_exists`, ...).
    ///
    /// PHP: `Appwrite\AppwriteException`.
    #[error("{message}")]
    Appwrite {
        /// The error message.
        message: String,
        /// The HTTP status code.
        code: u16,
        /// The error type, such as `table_already_exists`.
        kind: String,
    },
    /// An HTTP request that could not be sent or got no response.
    ///
    /// PHP: `Psr\Http\Client\ClientExceptionInterface`.
    #[error("{0}")]
    Client(String),
    /// The Redis connection failed.
    ///
    /// PHP: `\RedisException`.
    #[cfg(feature = "redis")]
    #[error("{0}")]
    Redis(#[from] redis::RedisError),
    /// The Redis Cluster connection failed.
    ///
    /// PHP: `\RedisClusterException`.
    #[cfg(feature = "redis-cluster")]
    #[error("{0}")]
    Cluster(redis::RedisError),
}

impl Error {
    /// The name of the exception class this error matches, such as
    /// `InvalidArgumentException`.
    ///
    /// PHP: the class of the exception `utopia-php/abuse` throws in the same
    /// case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::Exception(_) => "Exception",
            Error::DivisionByZero(_) => "DivisionByZeroError",
            Error::Type(_) => "TypeError",
            Error::Duplicate(_) => "Utopia\\Database\\Exception\\Duplicate",
            Error::Appwrite { .. } => "Appwrite\\AppwriteException",
            Error::Client(_) => "Psr\\Http\\Client\\ClientExceptionInterface",
            #[cfg(feature = "redis")]
            Error::Redis(_) => "RedisException",
            #[cfg(feature = "redis-cluster")]
            Error::Cluster(_) => "RedisClusterException",
        }
    }
}

/// A result of this crate.
pub type Result<T, E = Error> = std::result::Result<T, E>;
