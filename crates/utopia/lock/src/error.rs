/// Errors, one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// `Utopia\Lock\Exception\Contention`: the lock could not be acquired in time.
    #[error("{0}")]
    Contention(String),
    /// `Utopia\Lock\Exception`.
    #[error("{0}")]
    Lock(String),
    /// `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// `\LogicException`.
    #[error("{0}")]
    Logic(String),
    /// `\RuntimeException` (a lock file that cannot be opened).
    #[error("{0}")]
    Runtime(String),
    /// `\RedisException`.
    #[error("{0}")]
    Redis(#[from] redis::RedisError),
}

impl Error {
    /// The PHP exception class this error corresponds to.
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
