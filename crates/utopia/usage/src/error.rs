//! The errors usage operations fail with.

use std::fmt;

/// What a usage operation failed with. Each variant is one PHP exception
/// class ([`Error::php_class`]), carrying the same message.
///
/// PHP: `\Exception` and `\InvalidArgumentException` thrown by the library,
/// plus the exceptions of the libraries it calls, passed through unchanged.
#[derive(Debug, Clone, PartialEq)]
pub enum Error {
    /// `Exception`: invalid input to an adapter, a failed ClickHouse request,
    /// an unsupported operation.
    Exception(Message),
    /// `InvalidArgumentException`: an argument the library refuses.
    InvalidArgument(Message),
    /// `JsonException`: `json_encode` with `JSON_THROW_ON_ERROR` failed.
    Json(Message),
    /// A database failure (the Database adapter), with its own PHP class.
    #[cfg(feature = "database")]
    Database(utopia_database::Error),
    /// A query or builder failure, with its own PHP class.
    Query(utopia_query::Error),
    /// A date PHP could not parse (`DateMalformedStringException`, ...).
    DateTime(php_std::datetime::Error),
}

/// An error message: bytes, as PHP messages may quote input that is not UTF-8.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Message(Vec<u8>);

impl Message {
    /// The message bytes.
    pub fn as_bytes(&self) -> &[u8] {
        &self.0
    }
}

impl From<String> for Message {
    fn from(s: String) -> Self {
        Message(s.into_bytes())
    }
}

impl From<&str> for Message {
    fn from(s: &str) -> Self {
        Message(s.as_bytes().to_vec())
    }
}

impl From<Vec<u8>> for Message {
    fn from(b: Vec<u8>) -> Self {
        Message(b)
    }
}

impl Error {
    /// An `Exception` with `message`.
    pub fn exception(message: impl Into<Message>) -> Self {
        Error::Exception(message.into())
    }

    /// An `InvalidArgumentException` with `message`.
    pub fn invalid_argument(message: impl Into<Message>) -> Self {
        Error::InvalidArgument(message.into())
    }

    /// The PHP class of the exception this error is.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception(_) => "Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Json(_) => "JsonException",
            #[cfg(feature = "database")]
            Error::Database(e) => e.php_class(),
            Error::Query(e) => e.php_class(),
            Error::DateTime(e) => e.php_class(),
        }
    }

    /// The message, byte for byte.
    pub fn message_bytes(&self) -> std::borrow::Cow<'_, [u8]> {
        match self {
            Error::Exception(m) | Error::InvalidArgument(m) | Error::Json(m) => m.as_bytes().into(),
            #[cfg(feature = "database")]
            Error::Database(e) => e.message_bytes().to_vec().into(),
            Error::Query(e) => e.message_bytes(),
            Error::DateTime(e) => e.message_bytes().into(),
        }
    }

    /// The message, with invalid UTF-8 replaced.
    pub fn message(&self) -> String {
        String::from_utf8_lossy(&self.message_bytes()).into_owned()
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message())
    }
}

impl std::error::Error for Error {}

#[cfg(feature = "database")]
impl From<utopia_database::Error> for Error {
    fn from(e: utopia_database::Error) -> Self {
        Error::Database(e)
    }
}

impl From<utopia_query::Error> for Error {
    fn from(e: utopia_query::Error) -> Self {
        Error::Query(e)
    }
}

impl From<php_std::datetime::Error> for Error {
    fn from(e: php_std::datetime::Error) -> Self {
        Error::DateTime(e)
    }
}

/// A result of a usage operation.
pub type Result<T, E = Error> = std::result::Result<T, E>;
