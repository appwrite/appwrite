//! Moments as Unix seconds with microsecond precision, the unit claims and
//! clocks work in, converted exactly as PHP converts them.

use php_std::datetime::{DateTime, Timestamp, TzInfo};
use php_std::format::{Arg, sprintf, str_to_float};

/// `(float) $moment->format('U.u')`: the decimal text, correctly rounded.
pub(crate) fn seconds(moment: &DateTime) -> f64 {
    str_to_float(text(moment).as_bytes())
}

/// `$moment->format('U.u')`: the timestamp and the microseconds (`-5.500000`
/// for half a second past -5, as PHP prints it).
pub(crate) fn text(moment: &DateTime) -> String {
    format!("{}.{:06}", moment.timestamp(), moment.microsecond())
}

/// `sprintf('%.6F', $seconds)`.
pub(crate) fn fixed(seconds: f64) -> Vec<u8> {
    sprintf(b"%.6F", &[Arg::Float(seconds)]).unwrap_or_default()
}

/// `DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $seconds))`:
/// `None` where PHP gets `false`.
pub(crate) fn from_seconds(seconds: f64) -> Option<DateTime> {
    let text = fixed(seconds);
    DateTime::from_format(b"U.u", &text, None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).ok()?.ok()
}

/// `$moment->modify($modifier)` for the relative modifiers the triggers
/// build, which always parse.
pub(crate) fn shifted(moment: &DateTime, modifier: &[u8]) -> DateTime {
    moment.modified(modifier).unwrap_or_else(|_| moment.clone())
}

/// `"{$seconds} seconds"`.
pub(crate) fn by_seconds(moment: &DateTime, seconds: i64) -> DateTime {
    shifted(moment, format!("{seconds} seconds").as_bytes())
}
