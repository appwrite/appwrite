//! Why a hook could not be prepared or run.

/// Why a hook's params could not be resolved, or why its callback failed.
///
/// PHP: the exceptions `utopia-php/servers` throws, one variant per class.
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// A param is missing or invalid (`code` 400), or a server rejected the
    /// work.
    ///
    /// PHP: `Utopia\Servers\Exception`, with the same message and code.
    ///
    /// ```
    /// use utopia_servers::Error;
    ///
    /// let error = Error::server("Param \"name\" is not optional.", 400);
    /// assert_eq!(error.code(), 400);
    /// assert_eq!(error.php_class(), "Utopia\\Servers\\Exception");
    /// ```
    #[error("{message}")]
    Server {
        /// The message.
        message: String,
        /// The code (an HTTP status for param errors).
        code: i64,
    },
    /// A generic failure: an unknown param key, an injection declared twice,
    /// or a callback's own failure.
    ///
    /// PHP: `\Exception`.
    #[error("{message}")]
    Exception {
        /// The message.
        message: String,
        /// The code.
        code: i64,
    },
    /// An engine error rather than an exception: a callback that cannot be
    /// called. Servers that handle exceptions with error hooks let it
    /// through.
    ///
    /// PHP: `\TypeError`, which `catch (\Exception $e)` does not catch.
    #[error("{0}")]
    Type(String),
    /// A resource could not be resolved.
    ///
    /// PHP: the exceptions of `Utopia\DI\Container`.
    #[error(transparent)]
    Container(#[from] utopia_di::Error),
    /// Any other error a callback returns.
    ///
    /// PHP: an exception class of the application's.
    #[error(transparent)]
    Other(Box<dyn std::error::Error + Send + Sync>),
}

impl Error {
    /// A [`Error::Server`] error.
    pub fn server(message: impl Into<String>, code: i64) -> Self {
        Error::Server { message: message.into(), code }
    }

    /// A [`Error::Exception`] error.
    ///
    /// PHP: `new \Exception($message, $code)`.
    pub fn exception(message: impl Into<String>, code: i64) -> Self {
        Error::Exception { message: message.into(), code }
    }

    /// The error's code: 0 unless it carries one.
    ///
    /// PHP: `getCode()`.
    pub fn code(&self) -> i64 {
        match self {
            Error::Server { code, .. } | Error::Exception { code, .. } => *code,
            Error::Type(_) | Error::Container(_) | Error::Other(_) => 0,
        }
    }

    /// The name of the PHP exception class this error matches.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Server { .. } => "Utopia\\Servers\\Exception",
            Error::Exception { .. } | Error::Other(_) => "Exception",
            Error::Type(_) => "TypeError",
            Error::Container(e) => e.php_class(),
        }
    }
}
