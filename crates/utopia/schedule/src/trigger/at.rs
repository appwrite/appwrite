use php_std::datetime::{DateTime, TzInfo};

use super::Trigger;
use crate::moment;

/// A one-shot trigger: a single occurrence at a fixed moment.
///
/// The moment is absolute from construction onward, so "in 30 seconds"
/// captured through [`At::after`] means 30 seconds from when it was
/// scheduled, not from whenever the scheduler happens to evaluate it. The
/// scheduler retires it once the occurrence is delivered.
///
/// ```
/// use php_std::datetime::{DateTime, Timestamp, TzInfo};
/// use utopia_schedule::{At, Trigger};
///
/// let at = |s: &str| DateTime::parse(s.as_bytes(), None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap();
/// let delayed = At::after(30, &at("2026-08-17 16:00:00"));
///
/// let due = delayed.occurrences_between(&at("2026-08-17 16:00:29"), &at("2026-08-17 16:00:31"));
/// assert_eq!(due[0].format(b"H:i:s"), b"16:00:30");
/// assert!(!delayed.recurring());
/// ```
///
/// ```php
/// use Utopia\Schedule\Trigger\At;
///
/// $delayed = At::in(30, new DateTimeImmutable('2026-08-17 16:00:00'));
///
/// $due = $delayed->occurrencesBetween(new DateTimeImmutable('2026-08-17 16:00:29'), new DateTimeImmutable('2026-08-17 16:00:31'));
/// echo $due[0]->format('H:i:s'), "\n"; // 16:00:30
/// var_dump($delayed->recurring()); // bool(false)
/// ```
///
/// PHP: `Utopia\Schedule\Trigger\At`.
#[derive(Debug, Clone)]
pub struct At {
    time: DateTime,
}

impl At {
    /// A single occurrence at `time`.
    ///
    /// PHP: `new At($time)`.
    pub fn new(time: DateTime) -> Self {
        Self { time }
    }

    /// A single occurrence `seconds` after `from`.
    ///
    /// PHP: `At::in($seconds, $from)`.
    pub fn after(seconds: i64, from: &DateTime) -> Self {
        Self { time: moment::by_seconds(from, seconds) }
    }

    /// A single occurrence `seconds` from now (the wall clock, in UTC).
    ///
    /// PHP: `At::in($seconds)`, with Appwrite's default timezone (UTC).
    pub fn from_now(seconds: i64) -> Self {
        let now = DateTime::new(b"now", None, &TzInfo::utc()).unwrap_or_else(|_| unreachable!("now always parses"));
        Self::after(seconds, &now)
    }

    /// The moment of the occurrence.
    pub fn time(&self) -> &DateTime {
        &self.time
    }
}

impl Trigger for At {
    /// The moment, when it falls inside `[start, end)`.
    fn occurrences_between(&self, start: &DateTime, end: &DateTime) -> Vec<DateTime> {
        if self.time >= *start && self.time < *end { vec![self.time.clone()] } else { Vec::new() }
    }

    /// Never: an `At` fires once.
    fn recurring(&self) -> bool {
        false
    }
}
