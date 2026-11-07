//! When a schedule runs: [`Cron`], [`Interval`], [`At`], and [`Shifted`],
//! which moves another trigger's occurrences by a fixed offset.

mod at;
mod cron;
mod interval;
mod shifted;

use std::sync::Arc;

use php_std::datetime::DateTime;

pub use at::At;
pub use cron::Cron;
pub use interval::Interval;
pub use shifted::Shifted;

/// When a schedule should run, expressed as pure occurrence math.
///
/// A trigger never consults the current time: callers hand it an explicit
/// half-open window and it answers which occurrences fall inside. That is
/// what makes a scheduler immune to evaluation-time races: an occurrence
/// belongs to exactly one window no matter how long the caller's loop has
/// been running when the trigger is evaluated.
///
/// Implement it to add a kind of schedule; the scheduler takes any trigger
/// as an `Arc<dyn Trigger>`.
///
/// PHP: `Utopia\Schedule\Trigger`.
pub trait Trigger: Send + Sync {
    /// Occurrences inside the half-open window `[start, end)`, ascending.
    ///
    /// An occurrence exactly at `start` belongs to this window; one exactly
    /// at `end` belongs to the next. Consecutive windows that share a
    /// boundary therefore select every occurrence exactly once.
    ///
    /// PHP: `occurrencesBetween()`.
    fn occurrences_between(&self, start: &DateTime, end: &DateTime) -> Vec<DateTime>;

    /// Whether this trigger keeps producing occurrences. One-shot triggers
    /// return `false` and are retired by the scheduler once delivered.
    ///
    /// PHP: `recurring()`.
    fn recurring(&self) -> bool;
}

impl<T: Trigger + ?Sized> Trigger for Arc<T> {
    /// The shared trigger's occurrences.
    fn occurrences_between(&self, start: &DateTime, end: &DateTime) -> Vec<DateTime> {
        (**self).occurrences_between(start, end)
    }

    /// Whether the shared trigger recurs.
    fn recurring(&self) -> bool {
        (**self).recurring()
    }
}

impl<T: Trigger + ?Sized> Trigger for Box<T> {
    /// The boxed trigger's occurrences.
    fn occurrences_between(&self, start: &DateTime, end: &DateTime) -> Vec<DateTime> {
        (**self).occurrences_between(start, end)
    }

    /// Whether the boxed trigger recurs.
    fn recurring(&self) -> bool {
        (**self).recurring()
    }
}
