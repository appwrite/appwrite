//! The errors of orchestration calls, one variant per PHP exception class.

/// Why an orchestration call failed.
///
/// PHP: `Utopia\Orchestration\Exception\*` and the exceptions the library
/// lets through, one variant per class ([`Error::php_class`]), with the
/// same message.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// Docker refused or failed the operation; the message carries its
    /// output or response.
    ///
    /// PHP: `Utopia\Orchestration\Exception\Orchestration`.
    #[error("{0}")]
    Orchestration(String),
    /// A command ran out of time.
    ///
    /// PHP: `Utopia\Orchestration\Exception\Timeout`.
    #[error("{0}")]
    Timeout(String),
    /// A command string could not be split into arguments (an unclosed
    /// quote).
    ///
    /// PHP: `\Exception`, from `Orchestration::parseCommandString()`.
    #[error("{0}")]
    Command(String),
    /// A Docker command line could not be built (an empty name or value)
    /// or run (a NUL byte).
    ///
    /// PHP: the `\InvalidArgumentException` or `\ValueError`
    /// `utopia-php/console` throws.
    #[error("{0}")]
    Console(utopia_console::Error),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Orchestration(_) => "Utopia\\Orchestration\\Exception\\Orchestration",
            Error::Timeout(_) => "Utopia\\Orchestration\\Exception\\Timeout",
            Error::Command(_) => "Exception",
            Error::Console(e) => e.php_class(),
        }
    }

    /// The message, as PHP's `getMessage()`.
    pub fn message(&self) -> String {
        match self {
            Error::Orchestration(m) | Error::Timeout(m) | Error::Command(m) => m.clone(),
            Error::Console(e) => e.to_string(),
        }
    }
}

impl From<utopia_console::Error> for Error {
    /// The error building or running a Docker command line.
    fn from(e: utopia_console::Error) -> Self {
        Error::Console(e)
    }
}
