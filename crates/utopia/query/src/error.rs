//! [`Error`]: why a query could not be parsed, validated or compiled.

use std::fmt;
use std::sync::Arc;

/// Why a query could not be parsed, validated or compiled.
///
/// PHP: one variant per exception class the library throws, with the same
/// message ([`Error::php_class`] names the class).
#[derive(Debug, Clone, thiserror::Error)]
pub enum Error {
    /// A malformed query, or an error raised by [`Error::new`].
    ///
    /// PHP: `Utopia\Query\Exception`.
    #[error("{message}")]
    Query {
        /// The message (lossy when [`Error::message_bytes`] is not UTF-8).
        message: String,
        /// The code (`0` unless given to [`Error::new`]).
        code: i64,
        /// The error this one wraps.
        previous: Option<Arc<dyn std::error::Error + Send + Sync>>,
        /// The exact message, when it is not UTF-8 (a message quoting part
        /// of a multi-byte character of the input).
        raw: Option<Arc<[u8]>>,
    },
    /// An input the library refuses: an unsafe identifier, a missing table, an
    /// invalid option.
    ///
    /// PHP: `Utopia\Query\Exception\ValidationException`.
    #[error("{0}")]
    Validation(String),
    /// A feature the dialect cannot express.
    ///
    /// PHP: `Utopia\Query\Exception\UnsupportedException`.
    #[error("{0}")]
    Unsupported(String),
    /// An invalid constructor argument of a hook.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A name that is not one of an enumeration's values.
    ///
    /// PHP: `\ValueError`, from `Method::from()` and the other enums' `from()`.
    #[error("{0}")]
    InvalidValue(String),
    /// An argument of the wrong type, where PHP's type checks reject it
    /// (only where a Rust type cannot rule it out, such as a nested query
    /// that is not an array).
    ///
    /// PHP: `\TypeError`.
    #[error("{0}")]
    Type(String),
    /// A statement executed without an executor.
    ///
    /// PHP: `\BadMethodCallException`.
    #[error("{0}")]
    NoExecutor(String),
    /// A value PHP's engine cannot convert, such as an object in a string
    /// (`Object of class stdClass could not be converted to string`).
    ///
    /// PHP: `\Error`.
    #[error("{0}")]
    Engine(String),
    /// A value `json_encode` cannot encode (invalid UTF-8, a non-finite
    /// float), with the code PHP's `json_last_error()` reports.
    ///
    /// PHP: `\JsonException`.
    #[error("{message}")]
    Json {
        /// The message.
        message: String,
        /// The `JSON_ERROR_*` code.
        code: i64,
    },
}

impl PartialEq for Error {
    /// Same class, message and code.
    fn eq(&self, other: &Self) -> bool {
        self.php_class() == other.php_class() && self.to_string() == other.to_string() && self.code() == other.code()
    }
}

impl Error {
    /// A [`Error::Query`] with `message` and `code`, as `new Exception($message, $code)`.
    ///
    /// ```
    /// use utopia_query::{Code, Error};
    ///
    /// assert_eq!(Error::new("test", Code::from("42")).code(), 42);
    /// assert_eq!(Error::new("test", Code::from("abc")).code(), 0);
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Exception;
    ///
    /// echo (new Exception('test', '42'))->getCode(), "\n";  // 42
    /// echo (new Exception('test', 'abc'))->getCode(), "\n"; // 0
    /// ```
    pub fn new(message: impl Into<String>, code: impl Into<Code>) -> Self {
        Error::Query { message: message.into(), code: code.into().0, previous: None, raw: None }
    }

    /// A [`Error::Query`] wrapping `previous`, as `new Exception($message, $code, $previous)`.
    pub fn with_previous(
        message: impl Into<String>,
        code: impl Into<Code>,
        previous: Arc<dyn std::error::Error + Send + Sync>,
    ) -> Self {
        Error::Query { message: message.into(), code: code.into().0, previous: Some(previous), raw: None }
    }

    /// A [`Error::Query`] with code 0.
    pub(crate) fn query(message: impl Into<String>) -> Self {
        Error::new(message, 0)
    }

    /// A [`Error::Query`] with code 0 whose message is bytes (PHP strings
    /// are: a message can quote a partial UTF-8 character).
    pub(crate) fn query_bytes(message: Vec<u8>) -> Self {
        match String::from_utf8(message) {
            Ok(message) => Error::query(message),
            Err(e) => {
                let bytes = e.into_bytes();
                Error::Query {
                    message: String::from_utf8_lossy(&bytes).into_owned(),
                    code: 0,
                    previous: None,
                    raw: Some(bytes.into()),
                }
            }
        }
    }

    /// The message as PHP has it, byte for byte (`to_string()` is the same
    /// text, lossy where it is not UTF-8).
    ///
    /// PHP: `getMessage()`.
    pub fn message_bytes(&self) -> std::borrow::Cow<'_, [u8]> {
        match self {
            Error::Query { raw: Some(raw), .. } => std::borrow::Cow::Borrowed(raw),
            other => std::borrow::Cow::Owned(other.to_string().into_bytes()),
        }
    }

    /// The code: what [`Error::new`] was given, `0` for every error the library raises.
    ///
    /// PHP: `getCode()`.
    pub fn code(&self) -> i64 {
        match self {
            Error::Query { code, .. } | Error::Json { code, .. } => *code,
            _ => 0,
        }
    }

    /// The error this one wraps.
    ///
    /// PHP: `getPrevious()`.
    pub fn previous(&self) -> Option<&Arc<dyn std::error::Error + Send + Sync>> {
        match self {
            Error::Query { previous, .. } => previous.as_ref(),
            _ => None,
        }
    }

    /// The name of the PHP exception class this error matches.
    ///
    /// ```
    /// use utopia_query::Error;
    ///
    /// assert_eq!(Error::Validation("x".into()).php_class(), "Utopia\\Query\\Exception\\ValidationException");
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Exception\ValidationException;
    ///
    /// echo ValidationException::class, "\n"; // Utopia\Query\Exception\ValidationException
    /// ```
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Query { .. } => "Utopia\\Query\\Exception",
            Error::Validation(_) => "Utopia\\Query\\Exception\\ValidationException",
            Error::Unsupported(_) => "Utopia\\Query\\Exception\\UnsupportedException",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::InvalidValue(_) => "ValueError",
            Error::Type(_) => "TypeError",
            Error::NoExecutor(_) => "BadMethodCallException",
            Error::Engine(_) => "Error",
            Error::Json { .. } => "JsonException",
        }
    }

    /// Whether PHP's class for this error extends `Utopia\Query\Exception`
    /// (catching that class catches it).
    pub fn is_query_exception(&self) -> bool {
        matches!(self, Error::Query { .. } | Error::Validation(_) | Error::Unsupported(_))
    }
}

/// An exception code: an integer, or a string PHP turns into one (a numeric
/// string is cast, anything else is `0`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Code(pub i64);

impl From<i64> for Code {
    /// The integer.
    fn from(code: i64) -> Self {
        Code(code)
    }
}

impl From<&str> for Code {
    /// `(int)` of a numeric string, else `0`.
    fn from(code: &str) -> Self {
        Code(match php_std::value::numeric_str(code) {
            Some(_) => php_std::format::Arg::Str(code.as_bytes()).to_int(),
            None => 0,
        })
    }
}

impl fmt::Display for Code {
    /// The integer.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.0)
    }
}

/// The result of the library's fallible operations.
pub type Result<T, E = Error> = std::result::Result<T, E>;
