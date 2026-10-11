//! A scheduler that reconciles schedules from a source of truth and
//! dispatches cron, interval, delayed and fixed-time occurrences without
//! silently skipping a run.
//!
//! A [`Scheduler`] keeps the schedules a [`Source`] lists (usually database
//! rows) in memory, each built into a [`Trigger`]: a [`Cron`] expression, an
//! [`Interval`], a one-shot [`At`], or any of them [`Shifted`] by an offset.
//! Every tick selects the occurrences in a half-open window that opens where
//! the previous committed window closed, so nothing falls between ticks, and
//! commits that watermark to a [`Store`] together with a leadership lease, so
//! replicas sharing a [`RedisStore`] elect one dispatcher and a successor
//! resumes where its predecessor stopped. Time comes from a [`Clock`]: the
//! wall clock, or a [`TestClock`] that stands still until advanced.
//!
//! Moments are [`php_std::datetime::DateTime`] values, PHP's own date
//! arithmetic, so occurrences fall on exactly the same instants (daylight
//! saving transitions included) in both runtimes.
//!
//! PHP: ports `utopia-php/schedule`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Schedule\Scheduler` | [`Scheduler`] ([`Options`] for the named cadence arguments, [`Scheduler::with_telemetry`], [`Scheduler::on_error`]) |
//! | `Scheduler::reconcile()`, `tick()`, `commit()`, `run()`, `stop()` | [`Scheduler::reconcile`], [`Scheduler::tick`], [`Scheduler::commit`], [`Scheduler::run`], [`Scheduler::stop`] / [`Stop`] |
//! | `Scheduler::isCurrent()`, `count()` | [`Scheduler::is_current`], [`Scheduler::count`] |
//! | `Utopia\Schedule\Trigger` | [`Trigger`] |
//! | `Utopia\Schedule\Trigger\Cron` | [`Cron`] |
//! | `Utopia\Schedule\Trigger\Interval` | [`Interval`] |
//! | `Utopia\Schedule\Trigger\At` (`At::in()`) | [`At`] ([`At::after`], [`At::from_now`]) |
//! | `Utopia\Schedule\Trigger\Shifted` | [`Shifted`] |
//! | `Utopia\Schedule\Source` | [`Source`] |
//! | `Utopia\Schedule\Changes` | [`Source::since`] (`None`: no change feed) |
//! | `Utopia\Schedule\Source\Row` | [`Row`] |
//! | `Utopia\Schedule\Source\Entry` | [`Entry`] |
//! | `Utopia\Schedule\Occurrence` | [`Occurrence`] |
//! | `Utopia\Schedule\Claim` | [`Claim`] |
//! | `Utopia\Schedule\Store` | [`Store`] |
//! | `Utopia\Schedule\Store\Memory` | [`MemoryStore`] |
//! | `Utopia\Schedule\Store\Redis` | [`RedisStore`] |
//! | `Utopia\Schedule\Clock` | [`Clock`] |
//! | `Utopia\Schedule\Clock\System` | [`SystemClock`] |
//! | `Utopia\Schedule\Clock\Test` | [`TestClock`] |
//! | `\InvalidArgumentException`, `\RuntimeException`, `\RedisException` | [`Error::InvalidArgument`], [`Error::Runtime`], [`Error::Redis`] |
//! | a source's or handler's own exception | [`Error::Source`], [`Error::Handler`] ([`BoxError`]) |
//!
//! PHP's `Scheduler::run()` handler receives each hand-over and stops the
//! loop through the scheduler it captured; in Rust the handler returns a
//! future and stops the loop through a [`Stop`] handle.

#![warn(missing_docs)]

mod clock;
mod error;
mod moment;
mod occurrence;
mod scheduler;
mod source;
mod store;
mod trigger;

pub use clock::{Clock, SystemClock, TestClock};
pub use error::{BoxError, Error};
pub use occurrence::Occurrence;
pub use scheduler::{Options, Scheduler, Stop};
pub use source::{Entry, Row, Source};
pub use store::{Claim, MemoryStore, RedisStore, Store};
pub use trigger::{At, Cron, Interval, Shifted, Trigger};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
