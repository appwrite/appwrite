//! The error type of the scheduler, its triggers, clocks and stores.

/// An error raised by code the scheduler calls on the caller's behalf: a
/// [`Source`](crate::Source) that cannot list or build its schedules, or a
/// dispatch handler that cannot take a hand-over.
pub type BoxError = Box<dyn std::error::Error + Send + Sync>;

/// Why a scheduling operation failed.
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]);
/// errors PHP lets through from a source or a handler unchanged are
/// [`Error::Source`] and [`Error::Handler`].
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// An invalid argument: a cron expression that does not parse or never
    /// matches, an interval under a second, or scheduler cadences that do
    /// not fit together.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A [`TestClock`](crate::TestClock) holding a moment no date can
    /// represent (an infinite or far out of range timestamp).
    ///
    /// PHP: `\RuntimeException`.
    #[error("{0}")]
    Runtime(String),
    /// The Redis connection of a [`RedisStore`](crate::RedisStore) failed.
    ///
    /// PHP: `\RedisException`.
    #[error("{0}")]
    Redis(#[from] redis::RedisError),
    /// The [`Source`](crate::Source) failed to list its rows or to build one.
    ///
    /// PHP: whatever the source threw, rethrown as is.
    #[error("{0}")]
    Source(BoxError),
    /// The dispatch handler of [`Scheduler::run`](crate::Scheduler::run)
    /// failed; the tick was not committed and is delivered again.
    ///
    /// PHP: whatever the handler threw, rethrown as is.
    #[error("{0}")]
    Handler(BoxError),
}

impl Error {
    /// The name of the exception class this error matches, such as
    /// `InvalidArgumentException`. For [`Error::Source`] and
    /// [`Error::Handler`] it is `Throwable`: the class is the caller's own,
    /// carried by the boxed error.
    ///
    /// PHP: the class of the exception `utopia-php/schedule` throws in the
    /// same case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Runtime(_) => "RuntimeException",
            Error::Redis(_) => "RedisException",
            Error::Source(_) | Error::Handler(_) => "Throwable",
        }
    }
}
