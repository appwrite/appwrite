//! The crate's error type.

/// Everything [`Image`](crate::Image) can fail with, one variant per PHP
/// exception class. The messages are the library's own and say what the
/// caller can act on; what a codec reported is kept for `Debug` only.
///
/// PHP: `Utopia\Image\Exception\ImageException` and its subclasses for what an
/// image or the system makes fail, and `\InvalidArgumentException` for an
/// argument that could never work.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// Data whose signature is not one of the [`Format`](crate::Format)s, an
    /// output type that is not one, or a format this build has no codec for.
    /// PHP: `Utopia\Image\Exception\UnsupportedFormatException`.
    #[error("{0}")]
    UnsupportedFormat(String),
    /// Data of a known format that cannot be decoded; holds what the decoder
    /// reported. PHP: `Utopia\Image\Exception\CorruptImageException`.
    #[error("Corrupt or truncated image")]
    Corrupt(String),
    /// An image larger than its canvas's [`Limits`](crate::Limits).
    /// PHP: `Utopia\Image\Exception\LimitExceededException`.
    #[error("Image exceeds the size or memory limits")]
    LimitExceeded,
    /// A focal point, colour or size that is not valid.
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A crop whose target height rounds down to zero with a gravity other than
    /// the centre or with a focal point. PHP: `DivisionByZeroError`.
    #[error("Division by zero")]
    DivisionByZero,
    /// Anything else: an operation or encoder that failed, a file or directory
    /// that could not be written. PHP: `Utopia\Image\Exception\ImageException`.
    #[error("{0}")]
    Failed(String),
}

impl Error {
    /// The PHP exception class this error corresponds to.
    ///
    /// ```
    /// use utopia_image::Error;
    ///
    /// assert_eq!(Error::LimitExceeded.php_class(), "Utopia\\Image\\Exception\\LimitExceededException");
    /// ```
    ///
    /// ```php
    /// use Utopia\Image\Exception\ImageException;
    /// use Utopia\Image\Image;
    ///
    /// try { new Image('not an image'); } catch (ImageException $e) { echo get_class($e), "\n"; }
    /// ```
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::UnsupportedFormat(_) => "Utopia\\Image\\Exception\\UnsupportedFormatException",
            Error::Corrupt(_) => "Utopia\\Image\\Exception\\CorruptImageException",
            Error::LimitExceeded => "Utopia\\Image\\Exception\\LimitExceededException",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::DivisionByZero => "DivisionByZeroError",
            Error::Failed(_) => "Utopia\\Image\\Exception\\ImageException",
        }
    }

    /// Data that is not an image of a supported format.
    pub(crate) fn unsupported() -> Self {
        Error::UnsupportedFormat("Unsupported image format".into())
    }

    /// A format this build cannot read (`input`) or write (`output`).
    pub(crate) fn unavailable(format: &str, direction: &str) -> Self {
        Error::UnsupportedFormat(format!("{format} {direction} is not available"))
    }

    /// An operation between decoding and encoding that failed.
    pub(crate) fn process(_: impl std::fmt::Display) -> Self {
        Error::Failed("Failed to process image".into())
    }

    /// An encoder or a file write that failed.
    pub(crate) fn write(_: impl std::fmt::Display) -> Self {
        Error::Failed("Failed to write image".into())
    }
}

/// The result of every fallible operation of this crate.
pub type Result<T> = std::result::Result<T, Error>;
