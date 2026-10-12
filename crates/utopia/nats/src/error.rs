//! The error type of the client, JetStream, the key-value and object stores
//! and services.

use crate::jetstream::ApiError;

/// An error raised by application code: a subscription callback, a service
/// endpoint handler, an option callback (`on_error`, `on_slow_consumer`, ...)
/// or a transport factory. It travels through the client unchanged.
pub type CallbackError = Box<dyn std::error::Error + Send + Sync + 'static>;

/// Why a NATS operation failed.
///
/// PHP: one variant per exception class `utopia-php/nats` throws (see
/// [`Error::php_class`]). `TimeoutException` extends `ConnectionException`,
/// which extends `NatsException`: [`Error::is_connection`] answers the PHP
/// `instanceof ConnectionException` question.
#[derive(Debug, Clone, thiserror::Error)]
pub enum Error {
    /// A generic NATS error, such as the one passed to `on_error` for a
    /// server `-ERR`.
    ///
    /// PHP: `Utopia\NATS\Exception\NatsException`.
    #[error("{0}")]
    Nats(String),
    /// The connection failed, is not established, or was closed.
    ///
    /// PHP: `Utopia\NATS\Exception\ConnectionException`.
    #[error("{0}")]
    Connection(String),
    /// A frame body failed half way through: the parser no longer starts on
    /// a frame boundary and the connection must be rebuilt. Carries the
    /// failure that interrupted the frame.
    ///
    /// PHP: `Utopia\NATS\Exception\ConnectionException` with the cause as
    /// `getPrevious()`.
    #[error("Failed mid-frame, parser desynced from the stream: {0}")]
    Desynced(Box<Error>),
    /// Nothing arrived (or could be written) in time.
    ///
    /// PHP: `Utopia\NATS\Exception\TimeoutException`.
    #[error("{0}")]
    Timeout(String),
    /// The server refused the credentials.
    ///
    /// PHP: `Utopia\NATS\Exception\AuthenticationException`.
    #[error("{0}")]
    Authentication(String),
    /// A message larger than the server's maximum payload.
    ///
    /// PHP: `Utopia\NATS\Exception\MaxPayloadException`.
    #[error("{0}")]
    MaxPayload(String),
    /// A request nobody is subscribed to (status 503).
    ///
    /// PHP: `Utopia\NATS\Exception\NoRespondersException`.
    #[error("{0}")]
    NoResponders(String),
    /// The server denied a publish or subscription.
    ///
    /// PHP: `Utopia\NATS\Exception\PermissionException`.
    #[error("{0}")]
    Permission(String),
    /// The server sent (or the client was asked to send) something the
    /// protocol does not allow.
    ///
    /// PHP: `Utopia\NATS\Exception\ProtocolException`.
    #[error("{message}")]
    Protocol {
        /// The message (lossy when [`Error::message_bytes`] is not UTF-8).
        message: String,
        /// The exact message, when it is not UTF-8 (a message quoting wire
        /// bytes, such as a line read after a desynced frame).
        raw: Option<std::sync::Arc<[u8]>>,
    },
    /// The JetStream API answered with an error.
    ///
    /// PHP: `Utopia\NATS\Exception\JetStreamException` (`getCode()` is
    /// `code`, `$apiError` is `api`).
    #[error("{message}")]
    JetStream {
        /// The API error's description.
        message: String,
        /// The API error's HTTP-like code (`404`, `400`, ...).
        code: i64,
        /// The API error, when the server sent one.
        api: Option<ApiError>,
    },
    /// A key-value operation failed: a missing key, a lost compare-and-set.
    ///
    /// PHP: `Utopia\NATS\Exception\KeyValueException`.
    #[error("{0}")]
    KeyValue(String),
    /// An object store operation failed: a conflicting write, a bucket link.
    ///
    /// PHP: `Utopia\NATS\Exception\ObjectStoreException`.
    #[error("{0}")]
    ObjectStore(String),
    /// A service endpoint's error reply, with its NATS micro error code.
    ///
    /// PHP: `Utopia\NATS\Services\ServiceException`.
    #[error("{message}")]
    Service {
        /// The `Nats-Service-Error-Code` header value.
        code: String,
        /// The `Nats-Service-Error` header value.
        message: String,
    },
    /// An argument the operation cannot accept.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A call that is not allowed in the current state, such as reading the
    /// connection while a request batch is collecting replies.
    ///
    /// PHP: `\LogicException`.
    #[error("{0}")]
    Logic(String),
    /// Any other runtime failure, such as acknowledging a message without a
    /// reply subject or a missing object.
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// A JSON document that does not decode.
    ///
    /// PHP: `\JsonException`.
    #[error("{0}")]
    Json(String),
    /// An NKey seed of the wrong length.
    ///
    /// PHP: `\SodiumException`.
    #[error("{0}")]
    Sodium(String),
    /// An error raised by application code (see [`CallbackError`]), passed
    /// through unchanged.
    ///
    /// PHP: the exception the callback threw.
    #[error("{0}")]
    Callback(std::sync::Arc<dyn std::error::Error + Send + Sync + 'static>),
}

impl Error {
    /// The name of the exception class this error matches.
    ///
    /// PHP: the class of the exception `utopia-php/nats` throws in the same
    /// case. For [`Error::Callback`] it is the class of the wrapped error
    /// when that is an [`Error`], and `Exception` otherwise.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Nats(_) => "Utopia\\NATS\\Exception\\NatsException",
            Error::Connection(_) | Error::Desynced(_) => "Utopia\\NATS\\Exception\\ConnectionException",
            Error::Timeout(_) => "Utopia\\NATS\\Exception\\TimeoutException",
            Error::Authentication(_) => "Utopia\\NATS\\Exception\\AuthenticationException",
            Error::MaxPayload(_) => "Utopia\\NATS\\Exception\\MaxPayloadException",
            Error::NoResponders(_) => "Utopia\\NATS\\Exception\\NoRespondersException",
            Error::Permission(_) => "Utopia\\NATS\\Exception\\PermissionException",
            Error::Protocol { .. } => "Utopia\\NATS\\Exception\\ProtocolException",
            Error::JetStream { .. } => "Utopia\\NATS\\Exception\\JetStreamException",
            Error::KeyValue(_) => "Utopia\\NATS\\Exception\\KeyValueException",
            Error::ObjectStore(_) => "Utopia\\NATS\\Exception\\ObjectStoreException",
            Error::Service { .. } => "Utopia\\NATS\\Services\\ServiceException",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Logic(_) => "LogicException",
            Error::Runtime(_) => "RuntimeException",
            Error::Json(_) => "JsonException",
            Error::Sodium(_) => "SodiumException",
            Error::Callback(inner) => match inner.downcast_ref::<Error>() {
                Some(error) => error.php_class(),
                None => "Exception",
            },
        }
    }

    /// Whether this is a connection failure: a [`Error::Connection`],
    /// [`Error::Desynced`] or [`Error::Timeout`].
    ///
    /// PHP: `$e instanceof ConnectionException`.
    pub fn is_connection(&self) -> bool {
        matches!(self, Error::Connection(_) | Error::Desynced(_) | Error::Timeout(_))
    }

    /// Whether the client raised this error itself, as opposed to
    /// application code ([`Error::Callback`]) or a PHP built-in exception
    /// class.
    ///
    /// PHP: `$e instanceof NatsException`.
    pub fn is_nats(&self) -> bool {
        matches!(
            self,
            Error::Nats(_)
                | Error::Connection(_)
                | Error::Desynced(_)
                | Error::Timeout(_)
                | Error::Authentication(_)
                | Error::MaxPayload(_)
                | Error::NoResponders(_)
                | Error::Permission(_)
                | Error::Protocol { .. }
                | Error::JetStream { .. }
                | Error::KeyValue(_)
                | Error::ObjectStore(_)
        )
    }

    /// The error this one wraps: the cause of a [`Error::Desynced`].
    ///
    /// PHP: `getPrevious()`.
    pub fn previous(&self) -> Option<&Error> {
        match self {
            Error::Desynced(cause) => Some(cause),
            _ => None,
        }
    }

    /// The JetStream API error code carried by a [`Error::JetStream`].
    ///
    /// PHP: `$e->apiError?->errCode`.
    pub fn api_err_code(&self) -> Option<i64> {
        match self {
            Error::JetStream { api: Some(api), .. } => Some(api.err_code),
            _ => None,
        }
    }

    /// A [`Error::Protocol`] with a UTF-8 message.
    pub fn protocol(message: impl Into<String>) -> Error {
        Error::Protocol { message: message.into(), raw: None }
    }

    /// A [`Error::Protocol`] whose message is bytes (PHP strings are: a
    /// message can quote wire bytes that are not UTF-8).
    pub fn protocol_bytes(message: Vec<u8>) -> Error {
        match String::from_utf8(message) {
            Ok(message) => Error::protocol(message),
            Err(e) => {
                let bytes = e.into_bytes();
                Error::Protocol { message: String::from_utf8_lossy(&bytes).into_owned(), raw: Some(bytes.into()) }
            }
        }
    }

    /// The message as PHP has it, byte for byte (`to_string()` is the same
    /// text, lossy where it is not UTF-8).
    ///
    /// PHP: `getMessage()`.
    pub fn message_bytes(&self) -> std::borrow::Cow<'_, [u8]> {
        match self {
            Error::Protocol { raw: Some(raw), .. } => std::borrow::Cow::Borrowed(raw),
            Error::Desynced(cause) => {
                let mut bytes = b"Failed mid-frame, parser desynced from the stream: ".to_vec();
                bytes.extend_from_slice(&cause.message_bytes());
                std::borrow::Cow::Owned(bytes)
            }
            other => std::borrow::Cow::Owned(other.to_string().into_bytes()),
        }
    }

    pub(crate) fn json(error: php_std::json::Error) -> Error {
        Error::Json(error.message().to_owned())
    }
}

/// The result of a NATS operation.
pub type Result<T, E = Error> = std::result::Result<T, E>;
