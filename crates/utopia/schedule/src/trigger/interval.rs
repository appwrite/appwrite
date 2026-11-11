use php_std::datetime::DateTime;
use php_std::format::ceil;
use php_std::value::Number;

use super::Trigger;
use crate::Error;
use crate::moment;

/// A recurring trigger that fires every fixed number of seconds.
///
/// Occurrences sit on a deterministic grid, `anchor + k × seconds`, so the
/// cadence survives restarts instead of re-phasing to whenever the process
/// happened to boot. The anchor defaults to the Unix epoch; pass one to
/// phase the grid (for example to a resource's creation time).
///
/// ```
/// use php_std::datetime::{DateTime, Timestamp, TzInfo};
/// use utopia_schedule::{Interval, Trigger};
///
/// let at = |s: &str| DateTime::parse(s.as_bytes(), None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap();
/// let quarterly = Interval::new(900, None)?;
///
/// let due = quarterly.occurrences_between(&at("2026-08-17 16:07:00"), &at("2026-08-17 16:35:00"));
/// let due: Vec<_> = due.iter().map(|d| String::from_utf8(d.format(b"H:i:s")).unwrap()).collect();
/// assert_eq!(due, ["16:15:00", "16:30:00"]);
/// # Ok::<(), utopia_schedule::Error>(())
/// ```
///
/// ```php
/// use Utopia\Schedule\Trigger\Interval;
///
/// $quarterly = new Interval(900);
///
/// $due = $quarterly->occurrencesBetween(new DateTimeImmutable('2026-08-17 16:07:00'), new DateTimeImmutable('2026-08-17 16:35:00'));
/// echo implode(', ', array_map(fn ($d) => $d->format('H:i:s'), $due)), "\n"; // 16:15:00, 16:30:00
/// ```
///
/// PHP: `Utopia\Schedule\Trigger\Interval`.
#[derive(Debug, Clone)]
pub struct Interval {
    seconds: i64,
    anchor: f64,
}

impl Interval {
    /// Every `seconds` seconds, on a grid through `anchor` (the epoch when
    /// `None`). Fails with [`Error::InvalidArgument`] when `seconds` is
    /// below 1.
    ///
    /// PHP: `new Interval($seconds, $anchor)`.
    pub fn new(seconds: i64, anchor: Option<&DateTime>) -> Result<Self, Error> {
        if seconds < 1 {
            return Err(Error::InvalidArgument("Interval must be at least 1 second".into()));
        }
        Ok(Self { seconds, anchor: anchor.map_or(0.0, moment::seconds) })
    }
}

impl Trigger for Interval {
    /// The grid points inside `[start, end)`, in `start`'s timezone.
    fn occurrences_between(&self, start: &DateTime, end: &DateTime) -> Vec<DateTime> {
        let from = moment::seconds(start);
        let until = moment::seconds(end);
        let step = self.seconds as f64;
        let zone = start.timezone();

        let mut occurrences = Vec::new();
        let mut timestamp = self.anchor + step * ceil(Number::Float((from - self.anchor) / step));
        while timestamp < until {
            let Some(mut due) = moment::from_seconds(timestamp) else {
                break;
            };
            if let Some(zone) = &zone {
                due.set_timezone(zone);
            }
            occurrences.push(due);
            timestamp += step;
        }
        occurrences
    }

    /// Always: an interval keeps firing.
    fn recurring(&self) -> bool {
        true
    }
}
