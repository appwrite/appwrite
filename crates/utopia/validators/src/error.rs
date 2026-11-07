//! The error a validator returns when a value cannot be validated or
//! described, or when a constructor refuses its arguments.

/// Why a value could not be validated or described, or why a constructor
/// refused its arguments.
///
/// PHP: one variant per exception class PHP throws, carrying PHP's message.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// A constructor refused its arguments.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// An unsupported option, such as an unknown [`IpVersion`](crate::IpVersion)
    /// name.
    ///
    /// PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
    /// An operation the value does not support, such as converting an object to
    /// a string.
    ///
    /// PHP: `\Error`, raised by the engine.
    #[error("{0}")]
    Engine(String),
    /// A value of the wrong type for an operation, such as a label count too
    /// large to be an integer.
    ///
    /// PHP: `\TypeError`, raised where a value reaches a typed parameter.
    #[error("{0}")]
    Type(String),
}

impl Error {
    /// The name of the exception class this error matches, such as `TypeError`.
    ///
    /// PHP: the class of the exception `utopia-php/validators` throws in the same
    /// case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Exception(_) => "Exception",
            Error::Engine(_) => "Error",
            Error::Type(_) => "TypeError",
        }
    }
}

impl From<php_std::string::Error> for Error {
    /// Converts the error of a string operation: type errors stay type errors,
    /// the others become [`Error::Engine`].
    fn from(e: php_std::string::Error) -> Self {
        match e {
            php_std::string::Error::Type(m) => Error::Type(m),
            other => Error::Engine(other.message().to_owned()),
        }
    }
}
