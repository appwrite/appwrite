//! The errors the crate raises, one variant per PHP exception class.

/// Why an agent, an adapter or a diff check failed.
///
/// PHP: the exceptions `utopia-php/agents` throws (`\Exception` with the
/// HTTP status as its code for provider errors, `\InvalidArgumentException`,
/// `\RuntimeException`), the `\JsonException` of the request factory, and
/// those of the HTTP client and the console library, passed through.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// A provider answered with an error, the response was not understood,
    /// or the operation is not supported.
    ///
    /// PHP: `\Exception`, whose code is the HTTP status of a provider
    /// error (`0` otherwise).
    #[error("{message}")]
    Exception {
        /// The message.
        message: String,
        /// The exception code: the HTTP status for provider errors.
        code: i64,
    },
    /// An argument was refused: an unknown model, a property without a
    /// valid type, an attachment over a limit.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A command a diff check runs failed.
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// The request payload could not be encoded as JSON (a string that is
    /// not UTF-8).
    ///
    /// PHP: `\JsonException` from `Utopia\Psr7\Request\Factory::json()`.
    #[error("{message}")]
    Json {
        /// `json_last_error_msg()`.
        message: String,
        /// `json_last_error()`.
        code: i64,
    },
    /// The HTTP client failed.
    ///
    /// PHP: the `utopia-php/client` exception, passed through.
    #[error(transparent)]
    Client(#[from] utopia_client::Error),
    /// A command could not be built.
    ///
    /// PHP: the `utopia-php/console` exception, passed through.
    #[error(transparent)]
    Console(#[from] utopia_console::Error),
}

impl Error {
    /// An `\Exception` with code `0`.
    pub(crate) fn exception(message: impl Into<String>) -> Error {
        Error::Exception { message: message.into(), code: 0 }
    }

    /// The name of the PHP exception class this error matches.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception { .. } => "Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::Json { .. } => "JsonException",
            Error::Client(e) => e.php_class(),
            Error::Console(e) => e.php_class(),
        }
    }

    /// The exception code, as PHP's `getCode()`: the HTTP status of a
    /// provider error, the JSON error code of [`Error::Json`], `0`
    /// otherwise.
    pub fn code(&self) -> i64 {
        match self {
            Error::Exception { code, .. } | Error::Json { code, .. } => *code,
            _ => 0,
        }
    }
}
