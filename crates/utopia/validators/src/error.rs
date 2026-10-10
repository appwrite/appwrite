/// An exception a PHP validator throws: one variant per PHP class, carrying
/// PHP's message.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// `\InvalidArgumentException`: a constructor rejected its arguments.
    #[error("{0}")]
    InvalidArgument(String),
    /// `\Exception`: `IP` with an unsupported type.
    #[error("{0}")]
    Exception(String),
    /// `\Error`: the engine refused an operation, such as converting a
    /// `stdClass` to a string.
    #[error("{0}")]
    Engine(String),
    /// `\TypeError`: a value of the wrong type reached a typed parameter.
    #[error("{0}")]
    Type(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
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
    fn from(e: php_std::string::Error) -> Self {
        match e {
            php_std::string::Error::Type(m) => Error::Type(m),
            other => Error::Engine(other.message().to_owned()),
        }
    }
}
