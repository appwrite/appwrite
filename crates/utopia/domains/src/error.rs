//! The errors of domain parsing, validation and registrar calls.

/// Why an operation failed. Each variant carries the message and, where it
/// has one, the numeric code.
///
/// Registrar errors keep the code the registrar answered with (an HTTP
/// status for Name.com, an OpenSRS response code), so callers can tell a
/// missing domain (`404`) from a refused request (`400`).
///
/// PHP: one variant per exception class the library raises.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// A URL given as a domain, a request that could not be sent or read, or
    /// an operation the registrar does not offer.
    ///
    /// PHP: `\Exception`.
    #[error("{message}")]
    Exception {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// A registrar operation failed.
    ///
    /// PHP: `Utopia\Domains\Exception`.
    #[error("{message}")]
    Domains {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The credentials were refused.
    ///
    /// PHP: `Registrar\Exception\AuthException`.
    #[error("{message}")]
    Auth {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The registrar does not have the domain.
    ///
    /// PHP: `Registrar\Exception\DomainNotFoundException`.
    #[error("{message}")]
    DomainNotFound {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The domain cannot be transferred.
    ///
    /// PHP: `Registrar\Exception\DomainNotTransferableException`.
    #[error("{message}")]
    DomainNotTransferable {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The domain is registered already (by someone else, or in this account).
    ///
    /// PHP: `Registrar\Exception\DomainTakenException`.
    #[error("{message}")]
    DomainTaken {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The transfer authorization code was refused.
    ///
    /// PHP: `Registrar\Exception\InvalidAuthCodeException`.
    #[error("{message}")]
    InvalidAuthCode {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The registrar refused a contact.
    ///
    /// PHP: `Registrar\Exception\InvalidContactException`.
    #[error("{message}")]
    InvalidContact {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The registration period is not allowed for the TLD.
    ///
    /// PHP: `Registrar\Exception\InvalidPeriodException`.
    #[error("{message}")]
    InvalidPeriod {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The registrar has no price for the domain.
    ///
    /// PHP: `Registrar\Exception\PriceNotFoundException`.
    #[error("{message}")]
    PriceNotFound {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The registrar throttled the account (always code `429`).
    ///
    /// PHP: `Registrar\Exception\RateLimitException`.
    #[error("{message}")]
    RateLimit {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// The registrar does not offer the TLD.
    ///
    /// PHP: `Registrar\Exception\UnsupportedTldException`.
    #[error("{message}")]
    UnsupportedTld {
        /// What went wrong.
        message: String,
        /// The registrar's code (an HTTP status, an OpenSRS response code), `0` when none.
        code: i64,
    },
    /// A request URL that does not parse.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A date the registrar sent could not be read.
    ///
    /// PHP: `\DateMalformedStringException`.
    #[error("{0}")]
    DateMalformed(String),
    /// A value that is not a string where a domain is expected.
    ///
    /// PHP: `\TypeError`.
    #[error("{0}")]
    Type(String),
    /// An operation on a missing value (a single contact under a role instead
    /// of position `0`, for OpenSRS).
    ///
    /// PHP: `\Error`.
    #[error("{0}")]
    Engine(String),
    /// The suffix list could not be read or parsed.
    ///
    /// PHP: the data file is not a PHP array; PHP fails with a warning and a
    /// `TypeError` on the first lookup.
    #[error("{0}")]
    Data(String),
}

impl Error {
    /// The fully qualified name of the exception class this error stands
    /// for.
    ///
    /// PHP: the class `utopia-php/domains` throws (`Exception`,
    /// `Utopia\Domains\Exception`, ...).
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception { .. } => "Exception",
            Error::Domains { .. } => "Utopia\\Domains\\Exception",
            Error::Auth { .. } => "Utopia\\Domains\\Registrar\\Exception\\AuthException",
            Error::DomainNotFound { .. } => "Utopia\\Domains\\Registrar\\Exception\\DomainNotFoundException",
            Error::DomainNotTransferable { .. } => {
                "Utopia\\Domains\\Registrar\\Exception\\DomainNotTransferableException"
            }
            Error::DomainTaken { .. } => "Utopia\\Domains\\Registrar\\Exception\\DomainTakenException",
            Error::InvalidAuthCode { .. } => "Utopia\\Domains\\Registrar\\Exception\\InvalidAuthCodeException",
            Error::InvalidContact { .. } => "Utopia\\Domains\\Registrar\\Exception\\InvalidContactException",
            Error::InvalidPeriod { .. } => "Utopia\\Domains\\Registrar\\Exception\\InvalidPeriodException",
            Error::PriceNotFound { .. } => "Utopia\\Domains\\Registrar\\Exception\\PriceNotFoundException",
            Error::RateLimit { .. } => "Utopia\\Domains\\Registrar\\Exception\\RateLimitException",
            Error::UnsupportedTld { .. } => "Utopia\\Domains\\Registrar\\Exception\\UnsupportedTldException",
            Error::DateMalformed(_) => "DateMalformedStringException",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Type(_) | Error::Data(_) => "TypeError",
            Error::Engine(_) => "Error",
        }
    }

    /// The numeric code: `0` for errors without one.
    ///
    /// PHP: `getCode()`.
    pub fn code(&self) -> i64 {
        match self {
            Error::Exception { code, .. }
            | Error::Domains { code, .. }
            | Error::Auth { code, .. }
            | Error::DomainNotFound { code, .. }
            | Error::DomainNotTransferable { code, .. }
            | Error::DomainTaken { code, .. }
            | Error::InvalidAuthCode { code, .. }
            | Error::InvalidContact { code, .. }
            | Error::InvalidPeriod { code, .. }
            | Error::PriceNotFound { code, .. }
            | Error::RateLimit { code, .. }
            | Error::UnsupportedTld { code, .. } => *code,
            Error::InvalidArgument(_)
            | Error::DateMalformed(_)
            | Error::Type(_)
            | Error::Engine(_)
            | Error::Data(_) => 0,
        }
    }

    /// The message.
    ///
    /// PHP: `getMessage()`.
    pub fn message(&self) -> &str {
        match self {
            Error::Exception { message, .. }
            | Error::Domains { message, .. }
            | Error::Auth { message, .. }
            | Error::DomainNotFound { message, .. }
            | Error::DomainNotTransferable { message, .. }
            | Error::DomainTaken { message, .. }
            | Error::InvalidAuthCode { message, .. }
            | Error::InvalidContact { message, .. }
            | Error::InvalidPeriod { message, .. }
            | Error::PriceNotFound { message, .. }
            | Error::RateLimit { message, .. }
            | Error::UnsupportedTld { message, .. } => message,
            Error::InvalidArgument(message)
            | Error::DateMalformed(message)
            | Error::Type(message)
            | Error::Engine(message)
            | Error::Data(message) => message,
        }
    }

    pub(crate) fn exception(message: impl Into<String>, code: i64) -> Self {
        Error::Exception { message: message.into(), code }
    }

    pub(crate) fn domains(message: impl Into<String>, code: i64) -> Self {
        Error::Domains { message: message.into(), code }
    }
}
