//! The error type shared by every algorithm.

/// Why compressing, decompressing or configuring an algorithm failed.
///
/// PHP: one variant per way `utopia-php/compression` fails (see
/// [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// An argument out of range, such as a compression level.
    ///
    /// PHP: `\InvalidArgumentException`, thrown by `setLevel()`.
    #[error("{0}")]
    InvalidArgument(String),
    /// The codec refused the input: data that is not a valid stream of the
    /// algorithm, or a level the codec does not accept. The message is the
    /// warning PHP's extension emits, such as `gzinflate(): data error`.
    ///
    /// PHP: no exception. The extension emits an `E_WARNING` with this
    /// message and returns `false`, which the method's `string` return type
    /// coerces to an empty string.
    #[error("{0}")]
    Codec(String),
    /// The algorithm was built without the cargo feature that provides it.
    /// The message names the missing function, such as
    /// `Call to undefined function Utopia\Compression\Algorithms\xzencode()`.
    ///
    /// PHP: `\Error`, thrown when the extension is not loaded.
    #[error("{0}")]
    Unsupported(String),
}

impl Error {
    /// The name of the PHP class this error matches, such as
    /// `InvalidArgumentException`.
    ///
    /// PHP: the class of the exception `utopia-php/compression` throws in the
    /// same case. [`Error::Codec`] is a warning in PHP, not an exception; it
    /// reports `ErrorException`, the class an error handler that turns
    /// warnings into exceptions throws.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Codec(_) => "ErrorException",
            Error::Unsupported(_) => "Error",
        }
    }

    /// The error of an algorithm whose feature is off: PHP's message for a
    /// call to the missing extension function `function`.
    #[allow(dead_code)]
    pub(crate) fn undefined(function: &str) -> Self {
        Error::Unsupported(format!("Call to undefined function Utopia\\Compression\\Algorithms\\{function}()"))
    }
}
