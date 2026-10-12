//! The errors of encoding, decoding, resolving and serving DNS messages.

use std::fmt;

use crate::message::Header;

/// Why an operation failed.
///
/// Messages are bytes: an error can quote a label or a record's data,
/// which DNS does not require to be UTF-8. `Display` writes them lossily;
/// [`Error::message`] returns them exactly.
///
/// PHP: one variant per exception class the library raises.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Error {
    /// An invalid value: a name that cannot be encoded, record data that
    /// does not match its type, a message whose counts or sections are
    /// inconsistent, a zone with a record outside it.
    ///
    /// PHP: `InvalidArgumentException`.
    InvalidArgument(Vec<u8>),
    /// A packet that cannot be decoded, or a header field out of range.
    ///
    /// PHP: `Utopia\DNS\Exception\Message\DecodingException`.
    Decoding(Vec<u8>),
    /// A packet whose header decoded but whose sections did not: the header
    /// lets a server still answer `FORMERR` to the right query.
    ///
    /// PHP: `Utopia\DNS\Exception\Message\PartialDecodingException`
    /// (`getHeader()`, code `Message::RCODE_FORMERR`).
    PartialDecoding {
        /// The decoded header.
        header: Header,
        /// What could not be decoded.
        message: Vec<u8>,
    },
    /// A zone file that cannot be imported.
    ///
    /// PHP: `Utopia\DNS\Exception\Zone\ImportException` (`getContent()`).
    Import {
        /// The zone file.
        content: Vec<u8>,
        /// What is wrong with it.
        message: Vec<u8>,
        /// The error it wraps, when the line parser raised one.
        previous: Option<Box<Error>>,
    },
    /// A network or transport failure, a server that is not an IP address,
    /// an invalid PROXY protocol header.
    ///
    /// PHP: `Exception`.
    Exception(Vec<u8>),
    /// An argument PHP's engine refuses (an address with a NUL byte).
    ///
    /// PHP: `ValueError`.
    Value(Vec<u8>),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Decoding(_) => "Utopia\\DNS\\Exception\\Message\\DecodingException",
            Error::PartialDecoding { .. } => "Utopia\\DNS\\Exception\\Message\\PartialDecodingException",
            Error::Import { .. } => "Utopia\\DNS\\Exception\\Zone\\ImportException",
            Error::Exception(_) => "Exception",
            Error::Value(_) => "ValueError",
        }
    }

    /// The message, as PHP's `getMessage()` returns it.
    pub fn message(&self) -> &[u8] {
        match self {
            Error::InvalidArgument(m) | Error::Decoding(m) | Error::Exception(m) | Error::Value(m) => m,
            Error::PartialDecoding { message, .. } | Error::Import { message, .. } => message,
        }
    }

    /// Whether this is a decoding error (a `DecodingException` or its
    /// `PartialDecodingException` subclass).
    pub fn is_decoding(&self) -> bool {
        matches!(self, Error::Decoding(_) | Error::PartialDecoding { .. })
    }

    pub(crate) fn invalid(message: impl Into<Vec<u8>>) -> Self {
        Error::InvalidArgument(message.into())
    }

    pub(crate) fn decoding(message: impl Into<Vec<u8>>) -> Self {
        Error::Decoding(message.into())
    }

    pub(crate) fn exception(message: impl Into<Vec<u8>>) -> Self {
        Error::Exception(message.into())
    }
}

impl fmt::Display for Error {
    /// Writes the message (lossily when it is not UTF-8).
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&String::from_utf8_lossy(self.message()))
    }
}

impl std::error::Error for Error {
    fn source(&self) -> Option<&(dyn std::error::Error + 'static)> {
        match self {
            Error::Import { previous: Some(previous), .. } => Some(previous.as_ref()),
            _ => None,
        }
    }
}

impl From<php_std::net::NulByteError> for Error {
    fn from(e: php_std::net::NulByteError) -> Self {
        Error::Value(e.to_string().into_bytes())
    }
}

/// `bytes` with `parts` appended: an error message quoting input.
pub(crate) fn quote(prefix: &str, input: &[u8]) -> Vec<u8> {
    let mut message = Vec::with_capacity(prefix.len() + input.len());
    message.extend_from_slice(prefix.as_bytes());
    message.extend_from_slice(input);
    message
}
