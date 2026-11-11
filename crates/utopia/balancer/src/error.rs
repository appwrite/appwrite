//! The error type of the balancer.

/// Why choosing an option failed.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// An algorithm was asked to choose from no options at all.
    ///
    /// PHP: `\ValueError`, which `array_rand()` throws when
    /// `Algorithm\Random::run()` gets an empty array.
    #[error("{0}")]
    Value(String),
    /// A [`RoundRobin`](crate::RoundRobin) index that cannot move past
    /// `i64::MAX`.
    ///
    /// PHP: `\TypeError`, which incrementing the typed `int $index` property
    /// past `PHP_INT_MAX` throws.
    #[error("{0}")]
    Type(String),
    /// An error raised by a custom [`Algorithm`](crate::Algorithm), with the
    /// PHP class it stands for.
    #[error("{message}")]
    Custom {
        /// The PHP exception class.
        class: &'static str,
        /// The message.
        message: String,
    },
}

impl Error {
    /// The name of the exception class this error matches, such as
    /// `ValueError`.
    ///
    /// PHP: the class of the exception `utopia-php/balancer` throws in the
    /// same case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Value(_) => "ValueError",
            Error::Type(_) => "TypeError",
            Error::Custom { class, .. } => class,
        }
    }
}
