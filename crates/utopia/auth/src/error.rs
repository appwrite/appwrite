//! The error type every fallible operation in the crate returns.

/// An error raised by this crate, with its message.
///
/// Each variant is a kind of failure; [`Error::message`] gives the text and
/// [`Error::oauth2_code`] the OAuth2 error code where there is one.
///
/// PHP: one variant per PHP exception class. The message is PHP's, byte for
/// byte; [`Error::php_class`] names the class PHP throws for the same failure.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// A general failure: a missing key or secret, a key that cannot be read
    /// or used to sign.
    ///
    /// PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
    /// An argument out of range, such as a negative cost or an empty audience.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A hash could not be computed, or an option has the wrong type.
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// A value had an unexpected shape. Nothing in this crate raises it yet.
    ///
    /// PHP: `\UnexpectedValueException`.
    #[error("{0}")]
    UnexpectedValue(String),
    /// A scrypt parameter was rejected by the algorithm's own argument checks.
    ///
    /// PHP: `\Error`, which the php-scrypt extension raises.
    #[error("{0}")]
    Engine(String),
    /// A built-in operation rejected an argument, such as a key path
    /// containing a NUL byte.
    ///
    /// PHP: `\ValueError`, raised by an engine function.
    #[error("{0}")]
    Value(String),
    /// An option value has a type the algorithm cannot use.
    ///
    /// PHP: `\TypeError`, raised by PHP arithmetic or a typed parameter.
    #[error("{0}")]
    Type(String),
    /// A value could not be encoded to or decoded from JSON.
    ///
    /// PHP: `\JsonException`.
    #[error("{0}")]
    Json(String),
    /// A JWT failed verification: malformed, badly signed, expired or with
    /// the wrong claims.
    ///
    /// PHP: `Utopia\Auth\Verifiers\VerificationException`.
    #[error("{0}")]
    Verification(String),
    /// An OAuth2 `prompt` parameter is not valid. The message quotes the
    /// request's value, which need not be UTF-8.
    ///
    /// PHP: `Utopia\Auth\OAuth2\InvalidPromptException`.
    #[error("{}", String::from_utf8_lossy(.0))]
    InvalidPrompt(Vec<u8>),
    /// An OAuth2 pushed authorization `request_uri` is not valid.
    ///
    /// PHP: `Utopia\Auth\OAuth2\InvalidRequestUriException`.
    #[error("{0}")]
    InvalidRequestUri(String),
    /// An OAuth2 `resource` indicator is not valid.
    ///
    /// PHP: `Utopia\Auth\OAuth2\InvalidResourceException`.
    #[error("{0}")]
    InvalidResource(String),
    /// An OAuth2 client identifier URL or client metadata document is not valid.
    ///
    /// PHP: `Utopia\Auth\OAuth2\InvalidClientMetadataException`.
    #[error("{0}")]
    InvalidClientMetadata(String),
    /// A passkey ceremony failed. The message can quote CBOR text from the
    /// client (map keys, the attestation format), which need not be UTF-8.
    ///
    /// PHP: `Utopia\Auth\Passkeys\Exception`.
    #[error("{}", String::from_utf8_lossy(.0))]
    Passkey(Vec<u8>),
    /// A passkey's signature counter did not increase, a sign of a cloned
    /// authenticator; raised by [`crate::passkeys::Counter::check`].
    ///
    /// PHP: `Webauthn\Exception\CounterException`.
    #[error("{0}")]
    Counter(String),
}

impl Error {
    /// The name of the exception class matching this error, such as
    /// `InvalidArgumentException`, for callers that report errors by class.
    ///
    /// PHP: the class PHP throws for the same failure.
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

    /// The OAuth2 `error` code to answer with: `invalid_request` for a bad
    /// prompt or request URI, `invalid_target` for a bad resource, `None`
    /// for every other error.
    ///
    /// PHP: the `ERROR_CODE` constant of the OAuth2 exceptions.
    pub fn oauth2_code(&self) -> Option<&'static str> {
        match self {
            Error::InvalidPrompt(_) | Error::InvalidRequestUri(_) => Some("invalid_request"),
            Error::InvalidResource(_) => Some("invalid_target"),
            _ => None,
        }
    }

    /// The message as bytes, exactly as raised: it can quote input that is
    /// not UTF-8.
    ///
    /// PHP: `getMessage()`.
    pub fn message_bytes(&self) -> &[u8] {
        match self {
            Error::InvalidPrompt(m) => m,
            other => other.text(),
        }
    }

    fn text(&self) -> &[u8] {
        match self {
            Error::InvalidPrompt(m) | Error::Passkey(m) => m,
            Error::Exception(m)
            | Error::InvalidArgument(m)
            | Error::Runtime(m)
            | Error::UnexpectedValue(m)
            | Error::Engine(m)
            | Error::Value(m)
            | Error::Type(m)
            | Error::Json(m)
            | Error::Verification(m)
            | Error::InvalidRequestUri(m)
            | Error::InvalidResource(m)
            | Error::InvalidClientMetadata(m)
            | Error::Counter(m) => m.as_bytes(),
        }
    }

    /// The message as text, with any non-UTF-8 input it quotes replaced
    /// lossily.
    ///
    /// PHP: `getMessage()`.
    pub fn message(&self) -> std::borrow::Cow<'_, str> {
        String::from_utf8_lossy(self.text())
    }

    pub(crate) fn json(e: php_std::json::Error) -> Error {
        match e {
            php_std::json::Error::Value(m) => Error::Value(m),
            other => Error::Json(other.message().to_owned()),
        }
    }
}
