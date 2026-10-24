//! The error type shared by [`Audit`](crate::Audit) and every adapter.

/// Why an audit operation failed.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// A failure the library reports as a plain exception: an invalid
    /// setting, an unknown attribute, a query ClickHouse rejected, a
    /// ClickHouse request that failed.
    ///
    /// PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
    /// A log or query of the wrong shape, such as a batch entry missing a
    /// required ClickHouse column.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A value of the wrong type reached a PHP engine function, such as a
    /// batch log's `data` that is not an array.
    ///
    /// PHP: `\TypeError`.
    #[error("{0}")]
    Type(String),
    /// The database behind the Database adapter failed (a document that is
    /// not valid for the collection, a query it rejects, a lost connection).
    ///
    /// PHP: `Utopia\Database\Exception` and its subclasses.
    #[cfg(feature = "database")]
    #[error("{0}")]
    Database(#[from] utopia_database::Error),
    /// The query builder rejected a query (a ClickHouse statement it cannot
    /// build).
    ///
    /// PHP: `Utopia\Query\Exception` and its subclasses.
    #[error("{0}")]
    Query(#[from] utopia_query::Error),
    /// An HTTP client error that is not an `\Exception` in PHP (a
    /// `\ValueError` or `\TypeError`), which the ClickHouse adapter lets
    /// through instead of wrapping.
    ///
    /// PHP: the client error's own class.
    #[cfg(feature = "clickhouse")]
    #[error("{0}")]
    Client(utopia_client::Error),
    /// A date that does not parse, such as a batch log's `time`.
    ///
    /// PHP: `\DateMalformedStringException` (and the other engine errors of
    /// `new \DateTime()`).
    #[error("{0}")]
    DateTime(#[from] php_std::datetime::Error),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &str {
        match self {
            Error::Exception(_) => "Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Type(_) => "TypeError",
            #[cfg(feature = "database")]
            Error::Database(e) => e.php_class(),
            Error::Query(e) => e.php_class(),
            #[cfg(feature = "clickhouse")]
            Error::Client(e) => e.php_class(),
            Error::DateTime(e) => e.php_class(),
        }
    }

    /// The message, as PHP's `getMessage()` (lossy where it is not UTF-8;
    /// see [`Error::message_bytes`]).
    pub fn message(&self) -> String {
        String::from_utf8_lossy(&self.message_bytes()).into_owned()
    }

    /// The exact bytes of the message: a message quoting the input (an
    /// invalid date, a malformed query) may not be UTF-8.
    pub fn message_bytes(&self) -> Vec<u8> {
        match self {
            #[cfg(feature = "database")]
            Error::Database(e) => e.message_bytes().to_vec(),
            Error::Query(e) => e.message_bytes().into_owned(),
            Error::DateTime(e) => e.message_bytes().to_vec(),
            other => other.to_string().into_bytes(),
        }
    }

    #[cfg_attr(not(any(feature = "database", feature = "clickhouse")), allow(dead_code))]
    pub(crate) fn exception(message: impl Into<String>) -> Error {
        Error::Exception(message.into())
    }
}

/// A result of an audit operation.
pub type Result<T, E = Error> = std::result::Result<T, E>;
