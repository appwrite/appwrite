//! The errors an adapter raises, one variant per PHP exception class.

use std::fmt;

/// An exception message: bytes, as PHP messages are (one quoting a response
/// body need not be UTF-8).
#[derive(Clone, PartialEq, Eq, Default)]
pub struct Message(pub Vec<u8>);

impl Message {
    /// The message as text (invalid UTF-8 replaced).
    pub fn text(&self) -> std::borrow::Cow<'_, str> {
        String::from_utf8_lossy(&self.0)
    }
}

impl fmt::Debug for Message {
    /// The message as text.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        fmt::Debug::fmt(&self.text(), f)
    }
}

impl fmt::Display for Message {
    /// The message as text.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.text())
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

/// Why an adapter call failed.
///
/// Each variant is one PHP exception class ([`Error::php_class`]) with the
/// same message, and the same code ([`Error::code`]): adapters carry the
/// HTTP status of a failed provider call as the code.
#[derive(Debug, Clone, PartialEq, thiserror::Error)]
pub enum Error {
    /// A provider call failed, or the provider answered with something the
    /// adapter cannot use.
    ///
    /// PHP: `\Exception`.
    #[error("{message}")]
    Exception {
        /// The message.
        message: Message,
        /// The HTTP status of the failed call, or 0.
        code: i64,
    },
    /// An argument the adapter refuses: a clone version that would reach git
    /// as an option.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// The file does not exist at that path and ref (or is not a file).
    ///
    /// PHP: `Utopia\VCS\Exception\FileNotFound`.
    #[error("{message}")]
    FileNotFound {
        /// The message (empty for most adapters).
        message: Message,
        /// The code of the failure it wraps, or 0.
        code: i64,
    },
    /// The installation an owner was looked up through does not exist.
    ///
    /// PHP: `Utopia\VCS\Exception\OwnerNotFound`.
    #[error("{0}")]
    OwnerNotFound(String),
    /// The repository, branch or commit does not exist.
    ///
    /// PHP: `Utopia\VCS\Exception\RepositoryNotFound`.
    #[error("{0}")]
    RepositoryNotFound(String),
    /// A value PHP's engine refuses: a provider field of a type the adapter
    /// cannot use (an array where a string is returned), a null byte in a
    /// glob.
    ///
    /// PHP: `\TypeError`, `\ValueError`.
    #[error("{0}")]
    Engine(php_std::EngineError),
    /// An HTTP client error that is not a transport failure (an invalid
    /// header value or URI).
    ///
    /// PHP: the client's or PSR-7's own exception, thrown through.
    #[error(transparent)]
    Client(utopia_client::Error),
    /// A command could not be built.
    ///
    /// PHP: `Utopia\Console\Command`'s `\InvalidArgumentException`.
    #[error(transparent)]
    Console(#[from] utopia_console::Error),
    /// The token cache failed.
    ///
    /// PHP: the cache adapter's own exception, thrown through.
    #[error(transparent)]
    Cache(#[from] utopia_cache::Error),
}

impl Error {
    /// An `\Exception` with a code.
    pub fn exception(message: impl Into<Message>, code: i64) -> Self {
        Error::Exception { message: message.into(), code }
    }

    /// A [`Error::FileNotFound`] with no message, as most adapters throw it.
    pub fn file_not_found() -> Self {
        Error::FileNotFound { message: Message::default(), code: 0 }
    }

    /// A `\TypeError`.
    pub fn type_error(message: impl Into<String>) -> Self {
        Error::Engine(php_std::EngineError::new("TypeError", message))
    }

    /// Whether PHP's `catch (\Exception)` catches this error: every one but
    /// the engine's `Error`s (`TypeError`, `ValueError`, `Error`).
    pub fn is_exception(&self) -> bool {
        !matches!(self.php_class(), "TypeError" | "ValueError" | "Error" | "ArgumentCountError")
    }

    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception { .. } => "Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::FileNotFound { .. } => "Utopia\\VCS\\Exception\\FileNotFound",
            Error::OwnerNotFound(_) => "Utopia\\VCS\\Exception\\OwnerNotFound",
            Error::RepositoryNotFound(_) => "Utopia\\VCS\\Exception\\RepositoryNotFound",
            Error::Engine(e) => e.class,
            Error::Client(e) => e.php_class(),
            Error::Console(e) => e.php_class(),
            Error::Cache(e) => e.php_class(),
        }
    }

    /// The exception code: the HTTP status of a failed provider call, else 0.
    pub fn code(&self) -> i64 {
        match self {
            Error::Exception { code, .. } | Error::FileNotFound { code, .. } => *code,
            _ => 0,
        }
    }

    /// The message, as bytes.
    pub fn message(&self) -> Vec<u8> {
        match self {
            Error::Exception { message, .. } | Error::FileNotFound { message, .. } => message.0.clone(),
            other => other.to_string().into_bytes(),
        }
    }
}

/// A result of an adapter call.
pub type Result<T, E = Error> = std::result::Result<T, E>;
