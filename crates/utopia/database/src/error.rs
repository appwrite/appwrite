use std::fmt;

/// Database errors mapped to the PHP exception classes they mirror.
#[derive(Debug)]
pub enum Error {
    /// `DuplicateException` (unique violation on `_uid` or any unique index).
    Duplicate(String),
    /// `TimeoutException` (statement timeout).
    Timeout,
    /// `QueryException` with a message.
    Query(String),
    /// `OrderException`: a cursor document has a null value for an order attribute.
    Order { attribute: String },
    /// `NotFoundException` (missing table).
    NotFound(String),
    /// `LimitException` (bounded increment/decrement could not be applied).
    Limit,
    /// The connection pool could not provide a connection.
    Pool(String),
    /// Any other driver error.
    Driver(tokio_postgres::Error),
    /// Corrupt or unexpected data in a row.
    Decode(String),
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Error::Duplicate(m) => write!(f, "{m}"),
            Error::Timeout => write!(f, "Query timed out"),
            Error::Query(m) => write!(f, "{m}"),
            Error::Order { attribute } => write!(f, "Order attribute '{attribute}' is empty"),
            Error::NotFound(m) => write!(f, "{m}"),
            Error::Limit => write!(f, "Limit exceeded"),
            Error::Pool(m) => write!(f, "pool error: {m}"),
            Error::Driver(e) => write!(f, "database error: {e}"),
            Error::Decode(m) => write!(f, "decode error: {m}"),
        }
    }
}

impl std::error::Error for Error {}

impl From<tokio_postgres::Error> for Error {
    fn from(err: tokio_postgres::Error) -> Self {
        use tokio_postgres::error::SqlState;
        if let Some(db) = err.as_db_error() {
            let code = db.code();
            if *code == SqlState::UNIQUE_VIOLATION {
                let detail = db.constraint().unwrap_or("").to_owned();
                if detail.ends_with("_uid") {
                    return Error::Duplicate("Document already exists".to_owned());
                }
                return Error::Duplicate(format!(
                    "Document with the requested unique attributes already exists ({detail})"
                ));
            }
            if *code == SqlState::QUERY_CANCELED {
                return Error::Timeout;
            }
            if *code == SqlState::UNDEFINED_TABLE {
                return Error::NotFound("Collection not found".to_owned());
            }
        }
        Error::Driver(err)
    }
}

impl From<deadpool_postgres::PoolError> for Error {
    fn from(err: deadpool_postgres::PoolError) -> Self {
        match err {
            deadpool_postgres::PoolError::Backend(e) => Error::from(e),
            other => Error::Pool(other.to_string()),
        }
    }
}
