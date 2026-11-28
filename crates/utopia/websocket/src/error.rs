//! The error type of servers, adapters and the client.

/// Why a WebSocket operation failed.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// An invalid argument, such as a URL without a host or a send timeout
    /// that is not a positive number of seconds.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A connection that failed or is missing: connecting, sending or
    /// receiving without a connection.
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// Any other adapter failure, such as an address the server cannot
    /// listen on.
    ///
    /// PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
}

impl Error {
    /// The name of the exception class this error matches, such as
    /// `RuntimeException`.
    ///
    /// PHP: the class of the exception `utopia-php/websocket` throws in the
    /// same case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::Exception(_) => "Exception",
        }
    }
}
