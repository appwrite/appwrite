//! Errors raised by the framework and by route actions, and the `Result`
//! type that carries them.
//!
//! PHP: one variant per PHP exception class the library raises.

use std::borrow::Cow;

/// An error raised by the framework or by an action.
///
/// | PHP | Rust |
/// |---|---|
/// | `Utopia\Http\Exception` | [`Error::Http`] |
/// | `\Exception` (router, view, files, response helpers) | [`Error::Generic`] |
/// | `Utopia\DI\Exceptions\NotFoundException` (missing injection) | [`Error::NotFound`] |
/// | any other `\Throwable` an action throws | [`Error::Custom`] |
#[derive(Debug, Clone, PartialEq, thiserror::Error)]
pub enum Error {
    /// An HTTP error: a message with a status code, such as a 400 for an
    /// invalid param or a 404 when no route matches.
    ///
    /// PHP: `Utopia\Http\Exception($message, $code, $previous)`.
    #[error("{message}")]
    Http {
        /// The error message.
        message: String,
        /// The status code (or any code the raiser chose).
        code: i64,
        /// The error this one wraps, if any.
        previous: Option<Box<Error>>,
    },
    /// A plain error with a message and no code, raised by the router, views,
    /// static files and response helpers.
    ///
    /// PHP: a plain `\Exception($message)`.
    #[error("{0}")]
    Generic(String),
    /// A hook injects a resource that is not registered; holds its name.
    ///
    /// PHP: `Utopia\DI\Exceptions\NotFoundException`.
    #[error("Dependency {0} not found")]
    NotFound(String),
    /// An application error of a named class, raised by application code.
    #[error("{message}")]
    Custom {
        /// The error's class name.
        class: Cow<'static, str>,
        /// The error message.
        message: String,
        /// The error code.
        code: i64,
        /// The error this one wraps, if any.
        previous: Option<Box<Error>>,
    },
}

impl Error {
    /// An [`Error::Http`] with `message` and `code`, wrapping nothing.
    ///
    /// PHP: `new Utopia\Http\Exception($message, $code)`.
    pub fn http(message: impl Into<String>, code: i64) -> Self {
        Error::Http { message: message.into(), code, previous: None }
    }

    /// An [`Error::Custom`] of class `class`, with `message` and `code`.
    ///
    /// PHP: `new $class($message, $code)`.
    pub fn custom(class: impl Into<Cow<'static, str>>, message: impl Into<String>, code: i64) -> Self {
        Error::Custom { class: class.into(), message: message.into(), code, previous: None }
    }

    /// The class name of the error: the variant's fixed name, or the class of
    /// an [`Error::Custom`].
    ///
    /// PHP: the class of the exception this error corresponds to
    /// (`Utopia\Http\Exception`, `Exception`,
    /// `Utopia\DI\Exceptions\NotFoundException`).
    pub fn php_class(&self) -> &str {
        match self {
            Error::Http { .. } => "Utopia\\Http\\Exception",
            Error::Generic(_) => "Exception",
            Error::NotFound(_) => "Utopia\\DI\\Exceptions\\NotFoundException",
            Error::Custom { class, .. } => class,
        }
    }

    /// The error code: the code of an [`Error::Http`] or [`Error::Custom`],
    /// `0` for the others.
    ///
    /// PHP: `getCode()`.
    pub fn code(&self) -> i64 {
        match self {
            Error::Http { code, .. } | Error::Custom { code, .. } => *code,
            Error::Generic(_) | Error::NotFound(_) => 0,
        }
    }

    /// The error this one wraps, if any.
    ///
    /// PHP: `getPrevious()`.
    pub fn previous(&self) -> Option<&Error> {
        match self {
            Error::Http { previous, .. } | Error::Custom { previous, .. } => previous.as_deref(),
            Error::Generic(_) | Error::NotFound(_) => None,
        }
    }

    /// `Error handler had an error: <class>: <message>`, chaining the error being handled.
    pub(crate) fn handler(failure: &Error, handled: Error) -> Self {
        Error::Http {
            message: format!("Error handler had an error: {}: {}", failure.php_class(), failure),
            code: 500,
            previous: Some(Box::new(handled)),
        }
    }
}

/// A result whose error defaults to [`Error`].
pub type Result<T, E = Error> = std::result::Result<T, E>;
