//! Errors, one variant per PHP exception class the library raises.

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
    /// `Utopia\Http\Exception($message, $code, $previous)`.
    #[error("{message}")]
    Http { message: String, code: i64, previous: Option<Box<Error>> },
    /// A plain `\Exception($message)`.
    #[error("{0}")]
    Generic(String),
    /// `Utopia\DI\Exceptions\NotFoundException`: an injection that is not registered.
    #[error("Dependency {0} not found")]
    NotFound(String),
    /// An error of any other class, raised by application code.
    #[error("{message}")]
    Custom { class: Cow<'static, str>, message: String, code: i64, previous: Option<Box<Error>> },
}

impl Error {
    /// `new Utopia\Http\Exception($message, $code)`.
    pub fn http(message: impl Into<String>, code: i64) -> Self {
        Error::Http { message: message.into(), code, previous: None }
    }

    /// An application error of class `class` (`new $class($message, $code)`).
    pub fn custom(class: impl Into<Cow<'static, str>>, message: impl Into<String>, code: i64) -> Self {
        Error::Custom { class: class.into(), message: message.into(), code, previous: None }
    }

    /// The PHP class of the exception this error corresponds to.
    pub fn php_class(&self) -> &str {
        match self {
            Error::Http { .. } => "Utopia\\Http\\Exception",
            Error::Generic(_) => "Exception",
            Error::NotFound(_) => "Utopia\\DI\\Exceptions\\NotFoundException",
            Error::Custom { class, .. } => class,
        }
    }

    /// `getCode()`.
    pub fn code(&self) -> i64 {
        match self {
            Error::Http { code, .. } | Error::Custom { code, .. } => *code,
            Error::Generic(_) | Error::NotFound(_) => 0,
        }
    }

    /// `getPrevious()`.
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

pub type Result<T, E = Error> = std::result::Result<T, E>;
