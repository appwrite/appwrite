//! [`Error`]: what a [`crate::Client`] call fails with, one variant per PHP
//! exception class, and the classification PHP's `Utopia\Mongo\Exception`
//! offers (network, timeout, duplicate key, transient, ...).

use bson::{Bson, Document};

/// The details every `Utopia\Mongo\Exception` carries: message, code, the
/// server's error labels, write errors and write concern errors, and the
/// operation that failed.
///
/// PHP: the properties of `Utopia\Mongo\Exception`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Failure {
    /// `getMessage()`.
    pub message: String,
    /// `getCode()`: the server's error code (`11000` duplicate key, `50`
    /// time limit, ...), or the client's (`11601` receive timeout, `9001`
    /// socket error), `0` when there is none.
    pub code: i64,
    /// `getErrorLabels()`: `TransientTransactionError`,
    /// `UnknownTransactionCommitResult`, `RetryableWriteError`, ...
    pub error_labels: Vec<Bson>,
    /// `getWriteErrors()`.
    pub write_errors: Option<Vec<Bson>>,
    /// `getWriteConcernErrors()`.
    pub write_concern_errors: Option<Vec<Bson>>,
    /// `getOperationType()`.
    pub operation_type: Option<String>,
}

impl Failure {
    /// A failure with `message` and `code` and nothing else.
    pub fn new(message: impl Into<String>, code: i64) -> Self {
        Failure { message: message.into(), code, ..Failure::default() }
    }

    /// `Exception::fromResponse($errorResponse, $operationType)`: the
    /// failure a server's error reply describes (`errmsg`, `code`,
    /// `errorLabels`, `writeErrors`, `writeConcernErrors`).
    pub fn from_response(response: &Document, operation_type: Option<&str>) -> Self {
        let list = |key: &str| match response.get(key) {
            Some(Bson::Array(items)) => Some(items.clone()),
            _ => None,
        };
        Failure {
            message: match response.get("errmsg") {
                Some(Bson::String(s)) => s.clone(),
                Some(Bson::Null) | None => "Unknown MongoDB error".to_owned(),
                Some(other) => scalar_text(other),
            },
            code: match response.get("code") {
                Some(Bson::Int32(i)) => i64::from(*i),
                Some(Bson::Int64(i)) => *i,
                Some(Bson::Double(f)) => *f as i64,
                Some(Bson::Boolean(b)) => i64::from(*b),
                _ => 0,
            },
            error_labels: list("errorLabels").unwrap_or_default(),
            write_errors: list("writeErrors"),
            write_concern_errors: list("writeConcernErrors"),
            operation_type: operation_type.map(str::to_owned),
        }
    }

    /// `isNetworkError()`: codes 11600, 11601, 11602 (socket errors), 89
    /// (network timeout), 9001 (socket exception), 6 (host unreachable) and
    /// 7 (host not found).
    pub fn is_network_error(&self) -> bool {
        matches!(self.code, 11600 | 11601 | 11602 | 89 | 9001 | 6 | 7)
    }

    /// `isRetryableWrite()`: labelled `RetryableWriteError`.
    pub fn is_retryable_write(&self) -> bool {
        self.has_label("RetryableWriteError")
    }

    /// `isTransientError()`: labelled `TransientTransactionError` or
    /// `UnknownTransactionCommitResult`, a network error or a retryable write.
    pub fn is_transient_error(&self) -> bool {
        self.has_label("TransientTransactionError")
            || self.has_label("UnknownTransactionCommitResult")
            || self.is_network_error()
            || self.is_retryable_write()
    }

    /// `isDuplicateKeyError()`: codes 11000 and 11001.
    pub fn is_duplicate_key_error(&self) -> bool {
        matches!(self.code, 11000 | 11001)
    }

    /// `isWriteConcernError()`: there are write concern errors.
    pub fn is_write_concern_error(&self) -> bool {
        self.write_concern_errors.as_ref().is_some_and(|e| !e.is_empty())
    }

    /// `isTimeoutError()`: codes 50 (time limit), 89 (network timeout) and
    /// 11601 (socket timeout).
    pub fn is_timeout_error(&self) -> bool {
        matches!(self.code, 50 | 89 | 11601)
    }

    /// `getErrorCategory()`: `Network Error`, `Timeout Error`, `Duplicate
    /// Key Error`, `Write Concern Error`, `Transient Error` or `MongoDB
    /// Error`, the first that applies.
    pub fn error_category(&self) -> &'static str {
        if self.is_network_error() {
            "Network Error"
        } else if self.is_timeout_error() {
            "Timeout Error"
        } else if self.is_duplicate_key_error() {
            "Duplicate Key Error"
        } else if self.is_write_concern_error() {
            "Write Concern Error"
        } else if self.is_transient_error() {
            "Transient Error"
        } else {
            "MongoDB Error"
        }
    }

    /// `in_array($label, $this->errorLabels)`: loose, as PHP compares a
    /// string with each label.
    fn has_label(&self, label: &str) -> bool {
        self.error_labels.iter().any(|l| match l {
            Bson::String(s) => s == label,
            Bson::Boolean(b) => *b,
            _ => false,
        })
    }
}

/// A scalar as PHP's string conversion prints it (error messages).
fn scalar_text(value: &Bson) -> String {
    match value {
        Bson::String(s) => s.clone(),
        Bson::Int32(i) => i.to_string(),
        Bson::Int64(i) => i.to_string(),
        Bson::Double(f) => php_std::number::to_string(*f),
        Bson::Boolean(true) => "1".to_owned(),
        _ => String::new(),
    }
}

/// What a call fails with.
///
/// PHP: `Utopia\Mongo\Exception` and its subclasses, one variant each, and
/// `\InvalidArgumentException` for the client's settings.
#[derive(Debug, Clone, PartialEq, thiserror::Error)]
pub enum Error {
    /// The server refused a command (its error reply or write errors), or
    /// the exchange failed after the command was sent.
    ///
    /// PHP: `Utopia\Mongo\Exception`.
    #[error("{}", .0.message)]
    Exception(Box<Failure>),
    /// A failure before the command reached the server (no connection):
    /// nothing was applied, so the caller may replay it.
    ///
    /// PHP: `Utopia\Mongo\UnsentException`.
    #[error("{}", .0.message)]
    Unsent(Box<Failure>),
    /// PHP: `Utopia\Mongo\ConnectionException` (declared, never thrown by the client).
    #[error("{}", .0.message)]
    Connection(Box<Failure>),
    /// PHP: `Utopia\Mongo\AuthenticationException` (declared, never thrown by the client).
    #[error("{}", .0.message)]
    Authentication(Box<Failure>),
    /// PHP: `Utopia\Mongo\TransactionException` (declared, never thrown by the client).
    #[error("{}", .0.message)]
    Transaction(Box<Failure>),
    /// A bulk write that failed, with the bulk write's result.
    ///
    /// PHP: `Utopia\Mongo\BulkWriteException` (declared, never thrown by the client).
    #[error("{}", .0.message)]
    BulkWrite(Box<Failure>, Box<Document>),
    /// An invalid client setting (empty host, port out of range, ...).
    ///
    /// PHP: `\InvalidArgumentException` from `new Client(...)`.
    #[error("{0}")]
    InvalidArgument(String),
    /// What the PHP engine throws where the library breaks its own
    /// contract: a typed property read before it is set (`Error`), a
    /// declared return type not met (`TypeError`); and what PHP's MongoDB
    /// extension throws when it cannot encode a command
    /// (`MongoDB\Driver\Exception\UnexpectedValueException`).
    #[error("{message}")]
    Engine {
        /// The PHP class.
        class: &'static str,
        /// The engine's message.
        message: String,
    },
}

impl Error {
    /// A `Utopia\Mongo\Exception` with `message` and `code`.
    pub fn exception(message: impl Into<String>, code: i64) -> Self {
        Error::Exception(Box::new(Failure::new(message, code)))
    }

    /// A `Utopia\Mongo\UnsentException` with `message`.
    pub fn unsent(message: impl Into<String>) -> Self {
        Error::Unsent(Box::new(Failure::new(message, 0)))
    }

    /// `Exception::fromResponse($errorResponse, $operationType)`.
    pub fn from_response(response: &Document, operation_type: Option<&str>) -> Self {
        Error::Exception(Box::new(Failure::from_response(response, operation_type)))
    }

    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception(_) => "Utopia\\Mongo\\Exception",
            Error::Unsent(_) => "Utopia\\Mongo\\UnsentException",
            Error::Connection(_) => "Utopia\\Mongo\\ConnectionException",
            Error::Authentication(_) => "Utopia\\Mongo\\AuthenticationException",
            Error::Transaction(_) => "Utopia\\Mongo\\TransactionException",
            Error::BulkWrite(..) => "Utopia\\Mongo\\BulkWriteException",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Engine { class, .. } => class,
        }
    }

    /// The details of a `Utopia\Mongo\Exception` (every variant but
    /// [`Error::InvalidArgument`]).
    pub fn failure(&self) -> Option<&Failure> {
        match self {
            Error::Exception(f)
            | Error::Unsent(f)
            | Error::Connection(f)
            | Error::Authentication(f)
            | Error::Transaction(f)
            | Error::BulkWrite(f, _) => Some(f),
            Error::InvalidArgument(_) | Error::Engine { .. } => None,
        }
    }

    /// `getMessage()`.
    pub fn message(&self) -> &str {
        match self {
            Error::InvalidArgument(m) | Error::Engine { message: m, .. } => m,
            other => other.failure().map_or("", |f| f.message.as_str()),
        }
    }

    /// `getCode()`.
    pub fn code(&self) -> i64 {
        self.failure().map_or(0, |f| f.code)
    }

    /// `isUnsentError()`: whether the command never reached the server, so
    /// nothing was applied and it may be replayed. Only [`Error::Unsent`]:
    /// a server's reply carries the server's code, whatever it looks like.
    pub fn is_unsent_error(&self) -> bool {
        matches!(self, Error::Unsent(_))
    }

    /// `isNetworkError()` (see [`Failure::is_network_error`]).
    pub fn is_network_error(&self) -> bool {
        self.failure().is_some_and(Failure::is_network_error)
    }

    /// `isTimeoutError()` (see [`Failure::is_timeout_error`]).
    pub fn is_timeout_error(&self) -> bool {
        self.failure().is_some_and(Failure::is_timeout_error)
    }

    /// `isDuplicateKeyError()` (see [`Failure::is_duplicate_key_error`]).
    pub fn is_duplicate_key_error(&self) -> bool {
        self.failure().is_some_and(Failure::is_duplicate_key_error)
    }

    /// `isTransientError()` (see [`Failure::is_transient_error`]).
    pub fn is_transient_error(&self) -> bool {
        self.failure().is_some_and(Failure::is_transient_error)
    }

    /// `isRetryableWrite()` (see [`Failure::is_retryable_write`]).
    pub fn is_retryable_write(&self) -> bool {
        self.failure().is_some_and(Failure::is_retryable_write)
    }

    /// `isWriteConcernError()` (see [`Failure::is_write_concern_error`]).
    pub fn is_write_concern_error(&self) -> bool {
        self.failure().is_some_and(Failure::is_write_concern_error)
    }

    /// `getErrorCategory()` (see [`Failure::error_category`]).
    pub fn error_category(&self) -> &'static str {
        self.failure().map_or("MongoDB Error", Failure::error_category)
    }

    /// `BulkWriteException::getResult()`.
    pub fn bulk_write_result(&self) -> Option<&Document> {
        match self {
            Error::BulkWrite(_, result) => Some(result),
            _ => None,
        }
    }
}

/// A result of this crate.
pub type Result<T, E = Error> = std::result::Result<T, E>;
