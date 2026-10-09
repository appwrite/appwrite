/// Errors, one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// `Exception`: `new Email()` was given an empty address, one without
    /// exactly one `@` and something on both sides of it, or a URL as its
    /// domain. The message quotes the input, which can be any bytes.
    #[error("{}", String::from_utf8_lossy(.0))]
    Parse(Vec<u8>),
    /// `InvalidArgumentException`: a provider's rules left the local part
    /// empty (`Gmail`, `Outlook`, `Icloud`).
    #[error("{0}")]
    InvalidArgument(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
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
