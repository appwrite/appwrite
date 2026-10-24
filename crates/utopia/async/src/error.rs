//! The errors of [`crate::Promise`].

/// Why a promise combinator has no value.
///
/// PHP: one variant per exception of `Utopia\Async\Exception` the facades
/// throw (see [`Error::php_class`]).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// A combinator could not settle: [`crate::Promise::race`] or
    /// [`crate::Promise::any`] of nothing, or every future of
    /// [`crate::Promise::any`] failed.
    ///
    /// PHP: `Utopia\Async\Exception\Promise`.
    #[error("{0}")]
    Promise(String),
    /// A promise took longer than its timeout.
    ///
    /// PHP: `Utopia\Async\Exception\Timeout` (`Promise timed out after
    /// {ms}ms`).
    #[error("{0}")]
    Timeout(String),
}

impl Error {
    /// The name of the exception class this error matches.
    ///
    /// PHP: the class `utopia-php/async` throws in the same case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Promise(_) => "Utopia\\Async\\Exception\\Promise",
            Error::Timeout(_) => "Utopia\\Async\\Exception\\Timeout",
        }
    }
}
