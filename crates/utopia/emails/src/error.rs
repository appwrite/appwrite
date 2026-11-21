//! The errors of parsing an address and making it canonical.

/// Why an address could not be parsed or made canonical.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// [`Email::new`](crate::Email::new) was given an empty address, or one
    /// without exactly one `@` and something on both sides of it, or
    /// [`Address::new`](crate::Address::new) a URL as its domain. The message
    /// quotes the input, which can be any bytes.
    ///
    /// PHP: `Exception`.
    #[error("{}", String::from_utf8_lossy(.0))]
    Parse(Vec<u8>),
    /// A provider's rules left the local part empty.
    ///
    /// PHP: `InvalidArgumentException`.
    #[error("Email local part cannot be empty after normalization")]
    EmptyLocal,
}

impl Error {
    /// The class of the exception PHP throws.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Parse(_) => "Exception",
            Error::EmptyLocal => "InvalidArgumentException",
        }
    }

    /// The message, byte for byte.
    pub fn message(&self) -> &[u8] {
        match self {
            Error::Parse(message) => message,
            Error::EmptyLocal => b"Email local part cannot be empty after normalization",
        }
    }
}
