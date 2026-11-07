//! The errors of purges and certificate providers, one variant per PHP
//! exception class.

use crate::certificates::{Challenge, Status};

/// Why a purge or a certificate operation failed.
///
/// PHP: `Utopia\Cdn\Exception\*` and the SPL exceptions the library throws,
/// one variant per class ([`Error::php_class`]), with the same message.
#[derive(Debug, Clone, PartialEq, thiserror::Error)]
pub enum Error {
    /// An argument is malformed: a domain that is not a hostname, a cache
    /// path that does not begin with `/`, a polling setting out of range.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A provider refused a request or answered with something unusable.
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// The library is configured in a way it cannot work with: no option
    /// left after the balancer's filters, no provider for custom domains.
    ///
    /// PHP: `Utopia\Cdn\Exception\Configuration`.
    #[error("{0}")]
    Configuration(String),
    /// The adapter cannot serve this operation with the configuration it
    /// was given (Fastly without a service ID cannot purge keys); a fan-out
    /// skips it rather than failing.
    ///
    /// PHP: `Utopia\Cdn\Exception\UnsupportedOperation`.
    #[error("{0}")]
    UnsupportedOperation(String),
    /// A purge fanned out over several providers failed on at least one;
    /// `errors` holds every underlying failure in order.
    ///
    /// PHP: `Utopia\Cdn\Exception\Purge` (`getErrors()`; its previous
    /// exception is the first of them).
    #[error("{message}")]
    Purge {
        /// The summary, naming the providers that failed.
        message: String,
        /// Each provider's failure.
        errors: Vec<Error>,
    },
    /// Issuance cannot finish without the domain owner.
    ///
    /// PHP: `Utopia\Cdn\Exception\Certificate`.
    #[error("{}", .0.message)]
    Certificate(Box<Blocked>),
    /// The request could not be built (an API base or path that is not a
    /// URI), or a transport failed outside of sending.
    ///
    /// PHP: the exception `utopia-php/psr7` or the client throws.
    #[error("{0}")]
    Http(utopia_client::Error),
    /// A certificate's expiry date could not be read.
    ///
    /// PHP: what `new \DateTimeImmutable()` throws
    /// (`\DateMalformedStringException`).
    #[error("{message}")]
    Date {
        /// The PHP class of the error.
        class: &'static str,
        /// PHP's message.
        message: String,
    },
    /// A provider answered with a value of a type PHP refuses to pass on
    /// (a subscription state that is an object).
    ///
    /// PHP: `\TypeError`.
    #[error("{0}")]
    Type(String),
}

/// What a certificate authority waits for before it can issue: the DNS
/// records that prove ownership and the provider's own instructions.
///
/// PHP: the state of `Utopia\Cdn\Exception\Certificate`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Blocked {
    /// The message, naming the domain and the records to create.
    pub message: String,
    /// [`Status::Blocked`] while the provider waits on the domain owner,
    /// [`Status::Failed`] once it has stopped trying.
    ///
    /// PHP: `getStatus()`.
    pub status: Status,
    /// The DNS records that unblock issuance; several are alternatives.
    ///
    /// PHP: `getChallenges()`.
    pub challenges: Vec<Challenge>,
    /// The provider's instructions, such as records that conflict with a
    /// challenge.
    ///
    /// PHP: `getWarnings()`.
    pub warnings: Vec<String>,
}

impl Blocked {
    /// Whether the provider is still waiting on the domain owner rather
    /// than having given up.
    ///
    /// PHP: `isBlocked()`.
    pub fn is_blocked(&self) -> bool {
        self.status == Status::Blocked
    }
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::Configuration(_) => "Utopia\\Cdn\\Exception\\Configuration",
            Error::UnsupportedOperation(_) => "Utopia\\Cdn\\Exception\\UnsupportedOperation",
            Error::Purge { .. } => "Utopia\\Cdn\\Exception\\Purge",
            Error::Certificate(_) => "Utopia\\Cdn\\Exception\\Certificate",
            Error::Http(e) => e.php_class(),
            Error::Date { class, .. } => class,
            Error::Type(_) => "TypeError",
        }
    }

    /// The message, as PHP's `getMessage()`.
    pub fn message(&self) -> &str {
        match self {
            Error::InvalidArgument(m)
            | Error::Runtime(m)
            | Error::Configuration(m)
            | Error::UnsupportedOperation(m)
            | Error::Type(m) => m,
            Error::Purge { message, .. } | Error::Date { message, .. } => message,
            Error::Certificate(blocked) => &blocked.message,
            Error::Http(e) => e.message(),
        }
    }

    /// A purge failure carrying every provider's error.
    ///
    /// PHP: `new Purge($message, $errors)`.
    pub fn purge(message: impl Into<String>, errors: Vec<Error>) -> Error {
        Error::Purge { message: message.into(), errors }
    }

    /// A certificate issuance blocked on the domain owner, or abandoned.
    ///
    /// PHP: `new Certificate($message, $status, $challenges, $warnings)`.
    pub fn certificate(
        message: impl Into<String>,
        status: Status,
        challenges: Vec<Challenge>,
        warnings: Vec<String>,
    ) -> Error {
        Error::Certificate(Box::new(Blocked { message: message.into(), status, challenges, warnings }))
    }
}

impl From<utopia_client::Error> for Error {
    /// The error building or sending a request raised.
    fn from(e: utopia_client::Error) -> Self {
        Error::Http(e)
    }
}
