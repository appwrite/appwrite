//! The error type of the codec, the broker, its adapters and the client.

/// Why an MQTT operation failed.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// A failure at run time: an unknown property identifier
    /// (`Unknown MQTT property 0x7f`), a client that is not connected or
    /// cannot connect, send or receive, a transport that cannot send, or an
    /// address a listener cannot bind.
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// An invalid argument: a client URL without a host, an adapter without
    /// transports.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A length or offset that a malformed packet's variable byte integer
    /// pushed past the 64-bit range, where PHP's integer arithmetic turns it
    /// into a float that a typed parameter or return value rejects. The
    /// message is PHP's, up to the PHP source location PHP appends to errors
    /// raised for its own functions' parameters.
    ///
    /// PHP: `\TypeError`.
    #[error("{0}")]
    Type(String),
}

impl Error {
    /// The name of the exception class this error matches, such as
    /// `RuntimeException`.
    ///
    /// PHP: the class of the exception `utopia-php/mqtt` throws in the same
    /// case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Runtime(_) => "RuntimeException",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Type(_) => "TypeError",
        }
    }

    pub(crate) fn unknown_property(id: u8) -> Self {
        Error::Runtime(format!("Unknown MQTT property 0x{id:x}"))
    }
}
