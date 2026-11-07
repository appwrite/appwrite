//! The errors of parsing an address and making it canonical.

/// Why an address could not be parsed or made canonical.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// [`Email::new`](crate::Email::new) was given an empty address, one
    /// without exactly one `@` and something on both sides of it, or a URL as
    /// its domain. The message quotes the input, which can be any bytes.
    ///
    /// PHP: `Exception`.
    #[error("{}", String::from_utf8_lossy(.0))]
    Parse(Vec<u8>),
    /// A provider's rules left the local part empty ([`Gmail`](crate::Gmail),
    /// [`Outlook`](crate::Outlook), [`Icloud`](crate::Icloud)).
    ///
    /// PHP: `InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
}

impl Error {
    /// The name of the exception class this error stands for: `Exception` or
    /// `InvalidArgumentException`.
    ///
    /// PHP: the class `utopia-php/emails` throws.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Parse(_) => "Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
        }
    }

    /// The message, byte for byte.
    pub fn message(&self) -> &[u8] {
        match self {
            Error::Parse(m) => m,
            Error::InvalidArgument(m) => m.as_bytes(),
        }
    }
}
