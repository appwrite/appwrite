//! The error type of the detectors.

/// Why a detector refused an argument.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// An input kind the framework detector does not read, such as
    /// `language`.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A strategy name other than `filematch`, `extension` and `languages`.
    ///
    /// PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
}

impl Error {
    /// The name of the exception class this error matches, such as
    /// `InvalidArgumentException`.
    ///
    /// PHP: the class of the exception `utopia-php/detector` throws in the
    /// same case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Exception(_) => "Exception",
        }
    }
}
