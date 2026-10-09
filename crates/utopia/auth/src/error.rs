/// Every error the crate raises, one variant per PHP exception class.
///
/// The message is PHP's, byte for byte; [`Error::php_class`] names the class
/// PHP throws for the same failure.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// `\Exception`.
    #[error("{0}")]
    Exception(String),
    /// `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// `\UnexpectedValueException`.
    #[error("{0}")]
    UnexpectedValue(String),
    /// `\Error`: an extension raised an engine error (php-scrypt's argument checks).
    #[error("{0}")]
    Engine(String),
    /// `\ValueError`: an engine function rejected an argument.
    #[error("{0}")]
    Value(String),
    /// `\TypeError`: PHP arithmetic or a typed parameter rejected an option value.
    #[error("{0}")]
    Type(String),
    /// `\JsonException`.
    #[error("{0}")]
    Json(String),
    /// `Utopia\Auth\Verifiers\VerificationException`.
    #[error("{0}")]
    Verification(String),
    /// `Utopia\Auth\OAuth2\InvalidPromptException`.
    #[error("{0}")]
    InvalidPrompt(String),
    /// `Utopia\Auth\OAuth2\InvalidRequestUriException`.
    #[error("{0}")]
    InvalidRequestUri(String),
    /// `Utopia\Auth\OAuth2\InvalidResourceException`.
    #[error("{0}")]
    InvalidResource(String),
    /// `Utopia\Auth\OAuth2\InvalidClientMetadataException`.
    #[error("{0}")]
    InvalidClientMetadata(String),
    /// `Utopia\Auth\Passkeys\Exception`.
    #[error("{0}")]
    Passkey(String),
    /// `Webauthn\Exception\CounterException`, raised by [`crate::passkeys::Counter::check`].
    #[error("{0}")]
    Counter(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception(_) => "Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::UnexpectedValue(_) => "UnexpectedValueException",
            Error::Engine(_) => "Error",
            Error::Value(_) => "ValueError",
            Error::Type(_) => "TypeError",
            Error::Json(_) => "JsonException",
            Error::Verification(_) => "Utopia\\Auth\\Verifiers\\VerificationException",
            Error::InvalidPrompt(_) => "Utopia\\Auth\\OAuth2\\InvalidPromptException",
            Error::InvalidRequestUri(_) => "Utopia\\Auth\\OAuth2\\InvalidRequestUriException",
            Error::InvalidResource(_) => "Utopia\\Auth\\OAuth2\\InvalidResourceException",
            Error::InvalidClientMetadata(_) => "Utopia\\Auth\\OAuth2\\InvalidClientMetadataException",
            Error::Passkey(_) => "Utopia\\Auth\\Passkeys\\Exception",
            Error::Counter(_) => "Webauthn\\Exception\\CounterException",
        }
    }

    /// The OAuth2 `error` code of the OAuth2 exceptions (their `ERROR_CODE` constant).
    pub fn oauth2_code(&self) -> Option<&'static str> {
        match self {
            Error::InvalidPrompt(_) | Error::InvalidRequestUri(_) => Some("invalid_request"),
            Error::InvalidResource(_) => Some("invalid_target"),
            _ => None,
        }
    }

    /// The message, as PHP's `getMessage()`.
    pub fn message(&self) -> &str {
        match self {
            Error::Exception(m)
            | Error::InvalidArgument(m)
            | Error::Runtime(m)
            | Error::UnexpectedValue(m)
            | Error::Engine(m)
            | Error::Value(m)
            | Error::Type(m)
            | Error::Json(m)
            | Error::Verification(m)
            | Error::InvalidPrompt(m)
            | Error::InvalidRequestUri(m)
            | Error::InvalidResource(m)
            | Error::InvalidClientMetadata(m)
            | Error::Passkey(m)
            | Error::Counter(m) => m,
        }
    }

    pub(crate) fn json(e: php_std::json::Error) -> Error {
        match e {
            php_std::json::Error::Value(m) => Error::Value(m),
            other => Error::Json(other.message().to_owned()),
        }
    }
}
