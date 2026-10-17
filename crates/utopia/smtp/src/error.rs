//! The one error type of the crate.

use indexmap::IndexMap;

use crate::reply::{Outcome, Reply};

/// Everything that can go wrong, one variant per PHP exception class.
///
/// Runtime failures ([`Error::is_smtp`]) are the server's or the network's
/// doing. [`Error::InvalidArgument`] and [`Error::Logic`] are mistakes in the
/// calling code: a value that is not an address, a header the message owns,
/// a transport used before it was connected.
///
/// ```
/// use utopia_smtp::Address;
///
/// let error = Address::new("not an address", "").unwrap_err();
/// assert!(!error.is_smtp());
/// assert_eq!(error.php_class(), "InvalidArgumentException");
/// assert_eq!(error.to_string(), "Not an address: not an address");
/// ```
///
/// ```php
/// use Utopia\SMTP\Address;
///
/// try {
///     new Address('not an address');
/// } catch (\InvalidArgumentException $e) {
///     echo $e->getMessage(), "\n"; // Not an address: not an address
/// }
/// ```
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// A value the caller passed is not acceptable.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// The library was used out of order, such as reading from a transport
    /// that was never connected.
    ///
    /// PHP: `\LogicException`.
    #[error("{0}")]
    Logic(String),
    /// A runtime failure of no narrower kind, for transports and
    /// authenticators written outside this crate.
    ///
    /// PHP: `Utopia\SMTP\Exception\SmtpException`.
    #[error("{0}")]
    Smtp(String),
    /// The socket could not be opened, read, written or upgraded.
    ///
    /// PHP: `Utopia\SMTP\Exception\ConnectionException`.
    #[error("{0}")]
    Connection(String),
    /// A deadline passed with the other end still silent. A connection
    /// failure too ([`Error::is_connection`]).
    ///
    /// PHP: `Utopia\SMTP\Exception\TimeoutException`.
    #[error("{0}")]
    Timeout(String),
    /// The server said something that is not a reply, or not one this
    /// command allows. The connection is dropped.
    ///
    /// PHP: `Utopia\SMTP\Exception\ProtocolException`.
    #[error("{0}")]
    Protocol(String),
    /// No mechanism was shared with the server, or the credentials were
    /// refused. `code` is the refusing reply's code, or 0.
    ///
    /// PHP: `Utopia\SMTP\Exception\AuthenticationException`.
    #[error("{message}")]
    Authentication {
        /// What happened.
        message: String,
        /// The code of the reply that refused the credentials, or 0.
        code: u16,
    },
    /// The server cannot carry this message. Raised before anything is sent.
    ///
    /// PHP: `Utopia\SMTP\Exception\CapabilityException`.
    #[error("{0}")]
    Capability(String),
    /// The message could not be produced, such as an attachment that stopped
    /// being readable.
    ///
    /// PHP: `Utopia\SMTP\Exception\MessageException`.
    #[error("{0}")]
    Message(String),
    /// The envelope or the message data was refused. The session survives.
    ///
    /// PHP: `Utopia\SMTP\Exception\TransactionException`.
    #[error("{}", .0.message)]
    Transaction(Box<Refusal>),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Logic(_) => "LogicException",
            Error::Smtp(_) => "Utopia\\SMTP\\Exception\\SmtpException",
            Error::Connection(_) => "Utopia\\SMTP\\Exception\\ConnectionException",
            Error::Timeout(_) => "Utopia\\SMTP\\Exception\\TimeoutException",
            Error::Protocol(_) => "Utopia\\SMTP\\Exception\\ProtocolException",
            Error::Authentication { .. } => "Utopia\\SMTP\\Exception\\AuthenticationException",
            Error::Capability(_) => "Utopia\\SMTP\\Exception\\CapabilityException",
            Error::Message(_) => "Utopia\\SMTP\\Exception\\MessageException",
            Error::Transaction(_) => "Utopia\\SMTP\\Exception\\TransactionException",
        }
    }

    /// Whether this is a runtime failure rather than a mistake in the calling
    /// code: every variant except [`Error::InvalidArgument`] and [`Error::Logic`].
    ///
    /// PHP: `instanceof SmtpException`.
    pub fn is_smtp(&self) -> bool {
        !matches!(self, Error::InvalidArgument(_) | Error::Logic(_))
    }

    /// Whether the connection failed, a timeout included.
    ///
    /// PHP: `instanceof ConnectionException`.
    pub fn is_connection(&self) -> bool {
        matches!(self, Error::Connection(_) | Error::Timeout(_))
    }

    /// The error code: the reply code of a refusal, 0 otherwise.
    ///
    /// PHP: `getCode()`.
    pub fn code(&self) -> u16 {
        match self {
            Error::Authentication { code, .. } => *code,
            Error::Transaction(refusal) => refusal.reply.code(),
            _ => 0,
        }
    }

    /// The refusal, when the server refused the transaction.
    pub fn refusal(&self) -> Option<&Refusal> {
        match self {
            Error::Transaction(refusal) => Some(refusal),
            _ => None,
        }
    }
}

/// A refused envelope or message: the server's reply, and each recipient's
/// own refusal when every recipient was refused.
///
/// PHP: the properties of `TransactionException`.
#[derive(Debug, Clone, PartialEq)]
pub struct Refusal {
    /// The reply that refused the transaction.
    pub reply: Reply,
    /// The error message: the reply itself unless another was given.
    pub message: String,
    /// Each recipient's refusal, when the envelope failed because every
    /// recipient was refused. Empty when the refusal was the message's.
    pub rejected: IndexMap<String, Reply>,
}

impl Refusal {
    /// A refusal described by `message`, or by the reply itself when
    /// `message` is empty.
    ///
    /// PHP: `new TransactionException($reply, $message, $rejected)`.
    pub fn new(reply: Reply, message: impl Into<String>, rejected: IndexMap<String, Reply>) -> Self {
        let message = message.into();
        let message = if message.is_empty() { reply.to_string() } else { message };
        Refusal { reply, message, rejected }
    }

    /// Whether putting this message back on the queue is worth anything.
    ///
    /// PHP: `isTransient()`.
    pub fn is_transient(&self) -> bool {
        self.reply.outcome() == Outcome::Transient
    }

    /// Whether sending it again changes nothing.
    ///
    /// PHP: `isPermanent()`.
    pub fn is_permanent(&self) -> bool {
        self.reply.outcome() == Outcome::Permanent
    }
}

impl From<Refusal> for Error {
    fn from(refusal: Refusal) -> Self {
        Error::Transaction(Box::new(refusal))
    }
}
