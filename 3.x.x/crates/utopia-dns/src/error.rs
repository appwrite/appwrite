//! DNS exceptions matching PHP `Utopia\DNS\Exception\*`.

use crate::message::Header;

/// Error type covering decode, encode, zone import, and I/O failures.
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// PHP `Utopia\DNS\Exception\Message\DecodingException`.
    #[error("{0}")]
    Decoding(String),
    /// PHP `Utopia\DNS\Exception\Message\PartialDecodingException`.
    #[error("{message}")]
    PartialDecoding { header: Header, message: String },
    /// PHP `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// PHP `Utopia\DNS\Exception\Zone\ImportException`.
    #[error("{message}")]
    Import { content: String, message: String },
    /// PHP `\Exception` from the client, adapters, and PROXY protocol parser.
    #[error("{0}")]
    Other(String),
}

impl Error {
    pub(crate) fn decoding(msg: impl Into<String>) -> Self {
        Self::Decoding(msg.into())
    }

    pub(crate) fn invalid(msg: impl Into<String>) -> Self {
        Self::InvalidArgument(msg.into())
    }

    pub(crate) fn other(msg: impl Into<String>) -> Self {
        Self::Other(msg.into())
    }

    pub(crate) fn import(content: impl Into<String>, message: impl Into<String>) -> Self {
        Self::Import {
            content: content.into(),
            message: message.into(),
        }
    }

    pub(crate) fn partial(header: Header, message: impl Into<String>) -> Self {
        Self::PartialDecoding {
            header,
            message: message.into(),
        }
    }

    /// PHP `PartialDecodingException::getHeader()`.
    #[must_use]
    pub fn header(&self) -> Option<&Header> {
        match self {
            Self::PartialDecoding { header, .. } => Some(header),
            _ => None,
        }
    }

    /// PHP `ImportException::getContent()`.
    #[must_use]
    pub fn content(&self) -> Option<&str> {
        match self {
            Self::Import { content, .. } => Some(content.as_str()),
            _ => None,
        }
    }
}

/// PHP `Utopia\DNS\Exception\Message\DecodingException`.
pub type DecodingException = Error;

/// PHP `Utopia\DNS\Exception\Message\PartialDecodingException`.
pub type PartialDecodingException = Error;

/// PHP `Utopia\DNS\Exception\Zone\ImportException`.
pub type ImportException = Error;

pub type Result<T> = std::result::Result<T, Error>;
