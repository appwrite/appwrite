//! The errors sending a message raises, one variant per PHP exception class.

/// Why a message or a provider refused an input: the `$type` of PHP's
/// `Utopia\Messaging\Exception\InvalidArgumentException`.
///
/// PHP: the `InvalidArgumentException::*` constants.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Reason {
    /// The adapter cannot send this kind of message (`message_type`).
    MessageType,
    /// A push notification without title, body or data (`message_empty`).
    MessageEmpty,
    /// More recipients than the provider takes in one request (`too_many_recipients`).
    TooManyRecipients,
    /// An empty recipient address (`recipient_empty`).
    RecipientEmpty,
    /// A recipient that is not an address (`recipient_malformed`).
    RecipientMalformed,
    /// A recipient whose domain cannot receive mail (`recipient_domain_invalid`).
    RecipientDomainInvalid,
    /// The provider refused the request as malformed (`provider_rejected`).
    ProviderRejected,
    /// A display name with control characters (`name_malformed`).
    NameMalformed,
    /// A sender or reply-to that is not an address (`sender_malformed`).
    SenderMalformed,
    /// A header that would break the message (`header_malformed`).
    HeaderMalformed,
}

impl Reason {
    /// Every reason, in the order PHP declares the constants.
    pub const ALL: [Reason; 10] = [
        Reason::MessageType,
        Reason::MessageEmpty,
        Reason::TooManyRecipients,
        Reason::RecipientEmpty,
        Reason::RecipientMalformed,
        Reason::RecipientDomainInvalid,
        Reason::ProviderRejected,
        Reason::NameMalformed,
        Reason::SenderMalformed,
        Reason::HeaderMalformed,
    ];

    /// The PHP constant's value (`recipient_malformed`).
    pub fn as_str(self) -> &'static str {
        match self {
            Reason::MessageType => "message_type",
            Reason::MessageEmpty => "message_empty",
            Reason::TooManyRecipients => "too_many_recipients",
            Reason::RecipientEmpty => "recipient_empty",
            Reason::RecipientMalformed => "recipient_malformed",
            Reason::RecipientDomainInvalid => "recipient_domain_invalid",
            Reason::ProviderRejected => "provider_rejected",
            Reason::NameMalformed => "name_malformed",
            Reason::SenderMalformed => "sender_malformed",
            Reason::HeaderMalformed => "header_malformed",
        }
    }

    /// The reason a PHP constant's value names.
    pub fn from_name(name: &str) -> Option<Reason> {
        Reason::ALL.into_iter().find(|r| r.as_str() == name)
    }
}

/// An error building or sending a message.
///
/// PHP: the exceptions `send()` and the message constructors throw. A
/// provider's answer, even a refusal, is not an error: it is a failed result
/// of the [`Response`](crate::Response).
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// Input no provider can deliver: why (`getType()`), and the offending
    /// input (`getValue()`).
    ///
    /// PHP: `Utopia\Messaging\Exception\InvalidArgumentException`.
    #[error("{message}")]
    Invalid {
        /// Why the input was refused.
        reason: Reason,
        /// The message.
        message: String,
        /// The offending input, when there is one.
        value: Option<String>,
    },
    /// An invalid configuration or metadata value.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A provider API call that failed (WhatsApp template management).
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// Anything else: an attachment too large, a token that could not be
    /// obtained, a signature that could not be made.
    ///
    /// PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
    /// A body that cannot be encoded as JSON (a string that is not UTF-8).
    ///
    /// PHP: `\JsonException`.
    #[error("{0}")]
    Json(String),
    /// A provider answered with a value of a type a result cannot carry.
    ///
    /// PHP: `\TypeError`.
    #[error("{0}")]
    Type(String),
    /// An invalid argument to an engine function (an empty attachment path).
    ///
    /// PHP: `\ValueError`.
    #[error("{0}")]
    Value(String),
    /// An engine error: a method called on nothing (`GEOSMS` given no
    /// recipient at all).
    ///
    /// PHP: `\Error`.
    #[error("{0}")]
    Fatal(String),
    /// Building a MIME message failed (an attachment that cannot be read).
    ///
    /// PHP: `Utopia\SMTP\Exception\*`.
    #[error(transparent)]
    Smtp(#[from] utopia_smtp::Error),
    /// Building a request failed (a multipart file that cannot be read).
    ///
    /// PHP: the `RuntimeException` of `Utopia\Psr7\Request\Multipart`.
    #[error(transparent)]
    Multipart(#[from] utopia_client::multipart::Error),
}

impl Error {
    /// The input-refused error.
    pub fn invalid(reason: Reason, message: impl Into<String>, value: Option<String>) -> Error {
        Error::Invalid { reason, message: message.into(), value }
    }

    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Invalid { .. } => "Utopia\\Messaging\\Exception\\InvalidArgumentException",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::Exception(_) => "Exception",
            Error::Json(_) => "JsonException",
            Error::Type(_) => "TypeError",
            Error::Value(_) => "ValueError",
            Error::Fatal(_) => "Error",
            Error::Smtp(e) => e.php_class(),
            Error::Multipart(e) => e.php_class(),
        }
    }

    /// The message, as PHP's `getMessage()`.
    pub fn message(&self) -> String {
        match self {
            Error::Invalid { message, .. } => message.clone(),
            Error::InvalidArgument(m)
            | Error::Runtime(m)
            | Error::Exception(m)
            | Error::Json(m)
            | Error::Type(m)
            | Error::Value(m)
            | Error::Fatal(m) => m.clone(),
            Error::Smtp(e) => e.to_string(),
            Error::Multipart(e) => e.to_string(),
        }
    }

    /// The code, as PHP's `getCode()`: a `JsonException`'s `JSON_ERROR_*`,
    /// an SMTP refusal's reply code, `0` otherwise.
    pub fn code(&self) -> i64 {
        use php_std::json::ErrorCode as J;
        match self {
            Error::Json(m) => [
                J::Depth,
                J::StateMismatch,
                J::CtrlChar,
                J::Syntax,
                J::Utf8,
                J::Recursion,
                J::InfOrNan,
                J::UnsupportedType,
                J::InvalidPropertyName,
                J::Utf16,
                J::NonBackedEnum,
            ]
            .into_iter()
            .find(|c| c.message() == m)
            .map_or(0, J::code),
            Error::Smtp(e) => i64::from(e.code()),
            _ => 0,
        }
    }

    /// Whether PHP's `catch (\Exception)` catches it: every error but the
    /// engine's `Error`, `TypeError` and `ValueError`.
    pub fn is_exception(&self) -> bool {
        !matches!(self, Error::Type(_) | Error::Value(_) | Error::Fatal(_))
    }
}
