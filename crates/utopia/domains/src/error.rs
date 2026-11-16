//! The errors of domain parsing.

/// Why an operation failed.
///
/// PHP: one variant per exception class the library raises.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// A URL given as a domain.
    ///
    /// PHP: `\Exception`.
    #[error("{message}")]
    Exception {
        /// What went wrong.
        message: String,
        /// The code, `0` when none.
        code: i64,
    },
    /// A Public Suffix List that could not be read or parsed.
    #[error("{0}")]
    Data(String),
}

impl Error {
    /// The fully qualified name of the exception class this error stands for.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception { .. } => "Exception",
            Error::Data(_) => "TypeError",
        }
    }

    /// The numeric code: `0` for errors without one.
    ///
    /// PHP: `getCode()`.
    pub fn code(&self) -> i64 {
        match self {
            Error::Exception { code, .. } => *code,
            Error::Data(_) => 0,
        }
    }

    pub(crate) fn exception(message: impl Into<String>, code: i64) -> Self {
        Error::Exception { message: message.into(), code }
    }
}
