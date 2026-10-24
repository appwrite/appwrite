use php_std::datetime::{DateTime, Modifier};
use php_std::format::{Arg, sprintf};

use super::Trigger;

/// `$moment->modify(...)` with a parsed modifier, or the moment itself.
fn shift(moment: &DateTime, modifier: Option<&Modifier>) -> DateTime {
    modifier.map_or_else(|| moment.clone(), |m| moment.modified_by(m))
}

/// Another trigger, every occurrence moved by a fixed number of seconds.
///
/// A fleet of schedules sharing an expression shares its due second, so
/// `* * * * *` across three thousand rows is three thousand dispatches at
/// `:00` and nothing for the rest of the minute. Give each row an offset
/// derived from its id and the burst spreads across the minute while every
/// row keeps its slot across deploys.
///
/// The shift belongs to the schedule rather than to delivery, so an
/// occurrence's `due` *is* the moment it runs: the window that covers it
/// covers the shifted time and the watermark commits the shifted time. The
/// window is shifted back and the results forward, so occurrences still land
/// in exactly one window.
///
/// ```
/// use php_std::datetime::{DateTime, Timestamp, TzInfo};
/// use utopia_schedule::{Cron, Shifted, Trigger};
///
/// let at = |s: &str| DateTime::parse(s.as_bytes(), None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap();
/// let spread = Shifted::new(Cron::new("*/15 * * * *")?, 40);
///
/// let due = spread.occurrences_between(&at("2026-08-18 03:00:00"), &at("2026-08-18 03:16:00"));
/// let due: Vec<_> = due.iter().map(|d| String::from_utf8(d.format(b"H:i:s")).unwrap()).collect();
/// assert_eq!(due, ["03:00:40", "03:15:40"]);
/// # Ok::<(), utopia_schedule::Error>(())
/// ```
///
/// ```php
/// use Utopia\Schedule\Trigger\Cron;
/// use Utopia\Schedule\Trigger\Shifted;
///
/// $spread = new Shifted(new Cron('*/15 * * * *'), 40);
///
/// $due = $spread->occurrencesBetween(new DateTimeImmutable('2026-08-18 03:00:00'), new DateTimeImmutable('2026-08-18 03:16:00'));
/// echo implode(', ', array_map(fn ($d) => $d->format('H:i:s'), $due)), "\n"; // 03:00:40, 03:15:40
/// ```
///
/// PHP: `Utopia\Schedule\Trigger\Shifted`.
#[derive(Debug, Clone)]
pub struct Shifted<T> {
    trigger: T,
    seconds: i64,
    /// `"{$seconds} seconds"` and `sprintf('%d seconds', -$seconds)`.
    /// `modify("{$seconds} seconds")` and its inverse, parsed once (`None`
    /// leaves a moment as it is, as `moment::shifted` does for a modifier
    /// that does not parse).
    forward: Option<Modifier>,
    back: Option<Modifier>,
}

impl<T: Trigger> Shifted<T> {
    /// `trigger`, moved by `seconds` (negative moves earlier).
    ///
    /// PHP: `new Shifted($trigger, $seconds)`.
    pub fn new(trigger: T, seconds: i64) -> Self {
        // `-PHP_INT_MIN` is a float in PHP, which `%d` then formats.
        let negated = seconds.checked_neg().map_or(Arg::Float(-(seconds as f64)), Arg::Int);
        let back = sprintf(b"%d seconds", &[negated]).unwrap_or_default();
        Self {
            trigger,
            seconds,
            forward: Modifier::parse(format!("{seconds} seconds").as_bytes()).ok(),
            back: Modifier::parse(&back).ok(),
        }
    }

    /// The wrapped trigger.
    pub fn inner(&self) -> &T {
        &self.trigger
    }

    /// The shift, in seconds.
    pub fn seconds(&self) -> i64 {
        self.seconds
    }
}

impl<T: Trigger> Trigger for Shifted<T> {
    /// The wrapped trigger's occurrences in the window shifted back, each
    /// moved forward.
    fn occurrences_between(&self, start: &DateTime, end: &DateTime) -> Vec<DateTime> {
        if self.seconds == 0 {
            return self.trigger.occurrences_between(start, end);
        }
        let start = shift(start, self.back.as_ref());
        let end = shift(end, self.back.as_ref());
        let mut occurrences = self.trigger.occurrences_between(&start, &end);
        for due in &mut occurrences {
            *due = shift(due, self.forward.as_ref());
        }
        occurrences
    }

    /// Whether the wrapped trigger recurs.
    fn recurring(&self) -> bool {
        self.trigger.recurring()
    }
}
