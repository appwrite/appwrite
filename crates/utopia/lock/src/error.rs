//! The error type shared by every lock.

/// Why a lock operation failed.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// The lock could not be acquired in time.
    ///
    /// PHP: `Utopia\Lock\Exception\Contention`.
    #[error("{0}")]
    Contention(String),
    /// The lock cannot be used, such as a lock file whose directory is
    /// missing or not writable.
    ///
    /// PHP: `Utopia\Lock\Exception`.
    #[error("{0}")]
    Lock(String),
    /// An invalid argument, such as fewer than one permit or an empty token.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A call the lock's state does not allow, such as replacing the token of
    /// a [`Distributed`](crate::Distributed) lock.
    ///
    /// PHP: `\LogicException`.
    #[error("{0}")]
    Logic(String),
    /// A lock file that cannot be opened.
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// The Redis connection failed.
    ///
    /// PHP: `\RedisException`.
    #[error("{0}")]
    Redis(#[from] redis::RedisError),
}

impl Error {
    /// The name of the exception class this error matches, such as
    /// `LogicException`.
    ///
    /// PHP: the class of the exception `utopia-php/lock` throws in the same
    /// case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Contention(_) => "Utopia\\Lock\\Exception\\Contention",
            Error::Lock(_) => "Utopia\\Lock\\Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Logic(_) => "LogicException",
            Error::Runtime(_) => "RuntimeException",
            Error::Redis(_) => "RedisException",
        }
    }
}
