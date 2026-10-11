//! The crate's error: one variant per exception class the library raises.

/// What went wrong, one variant per PHP exception class.
#[derive(Debug, Clone, PartialEq, thiserror::Error)]
pub enum Error {
    /// A value outside what an operation accepts (`InvalidArgumentException`).
    #[error("{0}")]
    InvalidArgument(String),
    /// A failure at run time (`RuntimeException`).
    #[error("{0}")]
    Runtime(String),
    /// A handler's verdict that running the job again cannot fix
    /// (`Utopia\Queue\PermanentFailure`). See [`crate::Message::terminal`].
    #[error("{0}")]
    PermanentFailure(String),
    /// The background publisher's buffer stayed full
    /// (`Utopia\Queue\Publisher\BufferFullException`).
    #[error("{0}")]
    BufferFull(String),
    /// Redis refused a command or could not be reached (`RedisException`).
    #[error("{0}")]
    Redis(String),
    /// A Redis Cluster refused a command or could not be reached (`RedisClusterException`).
    #[error("{0}")]
    RedisCluster(String),
    /// Bytes that are not a queue envelope (`UnexpectedValueException`).
    #[error("{0}")]
    UnexpectedValue(String),
    /// A call made in the wrong state (`LogicException`).
    #[error("{0}")]
    Logic(String),
    /// A worker configuration the server refuses, or a param that fails
    /// validation (`Exception`), with PHP's exception code.
    #[error("{message}")]
    Server {
        /// The message.
        message: String,
        /// PHP's exception code (400 for a param, 0 otherwise).
        code: i64,
    },
    /// A value the JSON codec cannot encode or bytes it cannot decode (`JsonException`).
    #[error("{0}")]
    Json(String),
    /// A payload and a handler that disagree on types (`TypeError`): terminal, like
    /// [`Error::PermanentFailure`].
    #[error("{0}")]
    Type(String),
    /// An argument value a handler cannot use (`ValueError`): terminal, like
    /// [`Error::PermanentFailure`].
    #[error("{0}")]
    Value(String),
    /// A NATS or JetStream failure (`Utopia\NATS\Exception\*`), with the class it maps to.
    #[error("{message}")]
    Nats {
        /// The PHP exception class.
        class: &'static str,
        /// The message.
        message: String,
    },
    /// Any other failure a handler or hook reports, with the class it maps to.
    #[error("{message}")]
    Other {
        /// The PHP exception class.
        class: String,
        /// The message.
        message: String,
    },
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::PermanentFailure(_) => "Utopia\\Queue\\PermanentFailure",
            Error::BufferFull(_) => "Utopia\\Queue\\Publisher\\BufferFullException",
            Error::Redis(_) => "RedisException",
            Error::RedisCluster(_) => "RedisClusterException",
            Error::UnexpectedValue(_) => "UnexpectedValueException",
            Error::Logic(_) => "LogicException",
            Error::Server { .. } => "Exception",
            Error::Json(_) => "JsonException",
            Error::Type(_) => "TypeError",
            Error::Value(_) => "ValueError",
            Error::Nats { class, .. } => class,
            Error::Other { class, .. } => class,
        }
    }

    /// Whether a handler failing with this error should never be delivered
    /// again: a [`Error::PermanentFailure`], or a type or value error, which
    /// fail identically on every delivery.
    pub fn is_terminal(&self) -> bool {
        matches!(self, Error::PermanentFailure(_) | Error::Type(_) | Error::Value(_))
    }

    /// Whether this is a Redis transport or command failure, the kind a
    /// consumer recovers from by reconnecting.
    pub fn is_redis(&self) -> bool {
        matches!(self, Error::Redis(_) | Error::RedisCluster(_))
    }

    pub(crate) fn server(message: impl Into<String>) -> Self {
        Error::Server { message: message.into(), code: 0 }
    }
}

/// The crate's result type.
pub type Result<T, E = Error> = std::result::Result<T, E>;
