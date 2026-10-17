//! The crate's error type.

/// Everything [`Image`](crate::Image) can fail with, one variant per PHP
/// exception class.
///
/// PHP: `Utopia\Image\Image` lets `ImagickException` escape from ImageMagick,
/// throws `\InvalidArgumentException` for focal points, a plain `\Exception`
/// for an unknown output type or a directory it cannot create, and PHP's own
/// `DivisionByZeroError` when a crop target rounds down to a zero height.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// An unknown output type, or a directory [`Image::save`](crate::Image::save)
    /// could not create. PHP: `\Exception`.
    #[error("{0}")]
    Exception(String),
    /// A focal point that is incomplete, not finite or outside `0..=1`.
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A blob that cannot be decoded, a colour that cannot be parsed, a size
    /// past the pixel limits, or an encoder failure. PHP: `ImagickException`.
    #[error("{0}")]
    Imagick(String),
    /// A crop whose target height rounds down to zero with a gravity other than
    /// the centre or with a focal point. PHP: `DivisionByZeroError`.
    #[error("Division by zero")]
    DivisionByZero,
}

impl Error {
    /// The PHP exception class this error corresponds to.
    ///
    /// ```
    /// use utopia_image::Error;
    ///
    /// assert_eq!(Error::DivisionByZero.php_class(), "DivisionByZeroError");
    /// ```
    ///
    /// ```php
    /// try { intdiv(1, 0); } catch (\DivisionByZeroError $e) { echo get_class($e), "\n"; }
    /// ```
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Exception(_) => "Exception",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Imagick(_) => "ImagickException",
            Error::DivisionByZero => "DivisionByZeroError",
        }
    }

    pub(crate) fn imagick(message: impl Into<String>) -> Self {
        Error::Imagick(message.into())
    }
}

/// The result of every fallible operation of this crate.
pub type Result<T> = std::result::Result<T, Error>;
