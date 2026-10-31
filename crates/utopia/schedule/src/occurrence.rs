//! One due run of a schedule.

use php_std::datetime::DateTime;

use crate::moment;

/// A single due run of a registered schedule.
///
/// ```
/// use php_std::datetime::{DateTime, Timestamp, TzInfo};
/// use utopia_schedule::Occurrence;
///
/// let due = DateTime::parse(b"2026-08-18 03:01:00", None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap();
/// let run = Occurrence::new("fn", due, "payload");
/// assert_eq!(run.key(), "fn@1787022060.000000");
/// ```
///
/// ```php
/// use Utopia\Schedule\Occurrence;
///
/// $run = new Occurrence('fn', new DateTimeImmutable('2026-08-18 03:01:00'), 'payload');
/// echo $run->key(), "\n"; // fn@1787022060.000000
/// ```
///
/// PHP: `Utopia\Schedule\Occurrence`.
#[derive(Debug, Clone)]
pub struct Occurrence<P> {
    /// The schedule's id.
    pub id: String,
    /// When the run is due.
    pub due: DateTime,
    /// The schedule's payload.
    pub payload: P,
    /// The definition this run was selected against; see
    /// [`Scheduler::is_current`](crate::Scheduler::is_current).
    pub version: String,
}

impl<P> Occurrence<P> {
    /// A run of `id` due at `due`, with an empty version.
    ///
    /// PHP: `new Occurrence($id, $due, $payload)`.
    pub fn new(id: impl Into<String>, due: DateTime, payload: P) -> Self {
        Self { id: id.into(), due, payload, version: String::new() }
    }

    /// The run, selected against `version`.
    ///
    /// PHP: the `version` argument.
    pub fn with_version(mut self, version: impl Into<String>) -> Self {
        self.version = version.into();
        self
    }

    /// Stable identity of this run: the schedule and the moment it was due,
    /// `id@seconds.micros`.
    ///
    /// Delivery is at-least-once, so a handover or a retried tick can hand
    /// the same run over twice. Deriving the downstream identity from this
    /// key (the row id, the message id, the job name) turns that second
    /// delivery into a conflict the consumer can ignore.
    ///
    /// PHP: `key()`.
    pub fn key(&self) -> String {
        format!("{}@{}", self.id, moment::text(&self.due))
    }
}
