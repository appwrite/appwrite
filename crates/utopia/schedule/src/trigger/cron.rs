use std::borrow::Cow;
use std::sync::LazyLock;

use php_std::datetime::{DateTime, Modifier, TzInfo};
use php_std::format::str_to_int;
use php_std::pcre::{self, Key, Value};
use php_std::string::{ctype_digit, strtolower, strtoupper, trim};

use super::Trigger;
use crate::Error;

/// The relative steps the search takes, parsed once (`modify('+1 minute')`, ...).
static MINUTE: LazyLock<Modifier> = LazyLock::new(|| step(b"+1 minute"));
static HOUR: LazyLock<Modifier> = LazyLock::new(|| step(b"+1 hour"));
static DAY: LazyLock<Modifier> = LazyLock::new(|| step(b"+1 day"));

fn step(modifier: &[u8]) -> Modifier {
    Modifier::parse(modifier).unwrap_or_else(|_| unreachable!("a relative step always parses"))
}

/// The `@` macros and the expressions they stand for.
const MACROS: &[(&[u8], &[u8])] = &[
    (b"@yearly", b"0 0 1 1 *"),
    (b"@annually", b"0 0 1 1 *"),
    (b"@monthly", b"0 0 1 * *"),
    (b"@weekly", b"0 0 * * 0"),
    (b"@daily", b"0 0 * * *"),
    (b"@midnight", b"0 0 * * *"),
    (b"@hourly", b"0 * * * *"),
];

const MONTHS: &[(&[u8], i64)] = &[
    (b"JAN", 1),
    (b"FEB", 2),
    (b"MAR", 3),
    (b"APR", 4),
    (b"MAY", 5),
    (b"JUN", 6),
    (b"JUL", 7),
    (b"AUG", 8),
    (b"SEP", 9),
    (b"OCT", 10),
    (b"NOV", 11),
    (b"DEC", 12),
];

const DAYS: &[(&[u8], i64)] =
    &[(b"SUN", 0), (b"MON", 1), (b"TUE", 2), (b"WED", 3), (b"THU", 4), (b"FRI", 5), (b"SAT", 6)];

/// How many years ahead the occurrence search scans before declaring the
/// expression impossible. February 29th recurs within 8 years; nothing a
/// five-field expression can say waits longer.
const HORIZON_YEARS: i64 = 9;

/// A Quartz day rule no plain set of days can express.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum DayRule {
    /// `L` (offset 0) or `L-n`: `n` days before the last day of the month.
    Last(i64),
    /// `LW`: the last weekday of the month.
    LastWeekday,
    /// `nW`: the weekday nearest the `n`th, never crossing the month.
    Weekday(i64),
    /// `dL`: the last day `d` of the week in the month.
    LastOf(i64),
    /// `d#n`: the `n`th day `d` of the week in the month.
    Nth(i64, i64),
}

/// A recurring trigger described by a five-field cron expression: minute,
/// hour, day of month, month, day of week.
///
/// The portable cron core is supported: `*`, values, ranges (`a-b`), steps
/// (every n-th value over `*` or a range, `a-b/n`), lists (`,`), month and
/// day names (`JAN`, `FRI`), `7` as Sunday, and the `@hourly` … `@yearly`
/// macros. Following vixie cron, a day matches when *either* day field hits
/// if both day-of-month and day-of-week are restricted, and *both*
/// otherwise.
///
/// The Quartz extensions to the two day fields are supported too, also
/// inside a list alongside plain values:
///
/// | Field | Syntax | Meaning |
/// |---|---|---|
/// | either | `?` | no specific value, same as `*` |
/// | day of month | `L` | last day of the month |
/// | day of month | `L-3` | three days before the last day |
/// | day of month | `LW` | last weekday of the month |
/// | day of month | `15W` | weekday nearest the 15th, never crossing the month |
/// | day of week | `5L` | last Friday of the month |
/// | day of week | `FRI#3` | third Friday of the month |
///
/// Occurrences have minute resolution and keep the window's timezone.
/// Invalid and impossible expressions (a date that can never exist, like
/// February 31st) are rejected at construction, not discovered as a silent
/// no-op at evaluation time. Around daylight-saving transitions, nonexistent
/// local times follow PHP's date normalization.
///
/// ```
/// use php_std::datetime::{DateTime, Timestamp, TzInfo};
/// use utopia_schedule::{Cron, Trigger};
///
/// let at = |s: &str| DateTime::parse(s.as_bytes(), None, &TzInfo::utc(), Timestamp { sec: 0, usec: 0 }).unwrap();
/// let cron = Cron::new("15,45-50/2 8 * * *")?;
///
/// let due = cron.occurrences_between(&at("2026-08-17 00:00:00"), &at("2026-08-18 00:00:00"));
/// let due: Vec<_> = due.iter().map(|d| String::from_utf8(d.format(b"H:i")).unwrap()).collect();
/// assert_eq!(due, ["08:15", "08:45", "08:47", "08:49"]);
///
/// assert_eq!(Cron::new("0 0 31 2 *").unwrap_err().to_string(), "Cron expression \"0 0 31 2 *\" never matches a date");
/// # Ok::<(), utopia_schedule::Error>(())
/// ```
///
/// ```php
/// use Utopia\Schedule\Trigger\Cron;
///
/// $cron = new Cron('15,45-50/2 8 * * *');
///
/// $due = $cron->occurrencesBetween(new DateTimeImmutable('2026-08-17 00:00:00'), new DateTimeImmutable('2026-08-18 00:00:00'));
/// echo implode(', ', array_map(fn ($d) => $d->format('H:i'), $due)), "\n"; // 08:15, 08:45, 08:47, 08:49
///
/// try {
///     new Cron('0 0 31 2 *');
/// } catch (InvalidArgumentException $e) {
///     echo $e->getMessage(), "\n"; // Cron expression "0 0 31 2 *" never matches a date
/// }
/// ```
///
/// PHP: `Utopia\Schedule\Trigger\Cron`.
#[derive(Debug, Clone)]
pub struct Cron {
    minutes: u64,
    hours: u64,
    days_of_month: u64,
    months: u64,
    days_of_week: u64,
    rules: Vec<DayRule>,
    day_of_month_restricted: bool,
    day_of_week_restricted: bool,
}

/// Bytes as the text PHP would interpolate into a message.
fn text(bytes: &[u8]) -> Cow<'_, str> {
    String::from_utf8_lossy(bytes)
}

fn has(set: u64, value: i64) -> bool {
    (0..64).contains(&value) && set & (1 << value) != 0
}

/// The smallest member of `set` above `value`.
fn first_above(set: u64, value: i64) -> Option<i64> {
    let above = if value < 0 {
        set
    } else if value >= 63 {
        0
    } else {
        set & !((2u64 << value) - 1)
    };
    (above != 0).then(|| i64::from(above.trailing_zeros()))
}

/// The capture groups of `preg_match($pattern, $subject, $matches)` when it
/// matches.
fn captures(pattern: &[u8], subject: &[u8]) -> Option<Vec<Vec<u8>>> {
    let result = pcre::preg_match(pattern, subject, 0, 0).ok()?.value;
    if result.result != Value::Int(1) {
        return None;
    }
    let matches = result.matches?;
    Some(
        (1..)
            .map_while(|group| match matches.get(&Key::Int(group)) {
                Some(Value::Str(s)) => Some(s.clone()),
                _ => None,
            })
            .collect(),
    )
}

impl Cron {
    /// Parses `expression`; fails with [`Error::InvalidArgument`] when it
    /// does not parse or never matches a date.
    ///
    /// PHP: `new Cron($expression)`.
    pub fn new(expression: &str) -> Result<Self, Error> {
        let lowered = strtolower(trim(expression.as_bytes(), b" \n\r\t\x0b\0"));
        let normalized: &[u8] =
            MACROS.iter().find(|(name, _)| *name == &*lowered).map_or(&lowered, |(_, expanded)| expanded);

        let fields: Vec<Vec<u8>> = match pcre::preg_split(b"/\\s+/", normalized, -1, 0).value {
            Value::Array(parts) => parts
                .entries()
                .iter()
                .filter_map(|(_, v)| match v {
                    Value::Str(s) => Some(s.clone()),
                    _ => None,
                })
                .collect(),
            _ => Vec::new(),
        };
        let [minute, hour, day_of_month, month, day_of_week] = fields.as_slice() else {
            return Err(Error::InvalidArgument(format!(
                "Cron expression \"{expression}\" must have exactly five fields"
            )));
        };

        let minutes = parse_field(minute, 0, 59, &[])?;
        let hours = parse_field(hour, 0, 23, &[])?;
        let months = parse_field(month, 1, 12, MONTHS)?;
        let (days_of_month, mut rules) = parse_day_field(day_of_month, 1, 31, &[], false)?;
        let (mut days_of_week, week_rules) = parse_day_field(day_of_week, 0, 7, DAYS, true)?;
        if days_of_week & (1 << 7) != 0 {
            // Both 0 and 7 mean Sunday.
            days_of_week = (days_of_week & !(1 << 7)) | 1;
        }
        rules.extend(week_rules);

        let restricted = |field: &[u8]| field != b"*" && field != b"?";
        let cron = Cron {
            minutes,
            hours,
            days_of_month,
            months,
            days_of_week,
            rules,
            day_of_month_restricted: restricted(day_of_month),
            day_of_week_restricted: restricted(day_of_week),
        };

        let now = DateTime::new(b"now", None, &TzInfo::utc()).unwrap_or_else(|_| unreachable!("now always parses"));
        if cron.next_match(&now).is_none() {
            return Err(Error::InvalidArgument(format!("Cron expression \"{expression}\" never matches a date")));
        }
        Ok(cron)
    }

    /// The first matching minute at or after `from`, or `None` when nothing
    /// matches within the search horizon.
    fn next_match(&self, from: &DateTime) -> Option<DateTime> {
        // Occurrences are whole minutes: round any sub-minute part up.
        let rounded;
        let from = if from.fields().5 > 0 || from.microsecond() > 0 {
            rounded = from.modified_by(&MINUTE);
            &rounded
        } else {
            from
        };
        let (_, _, _, h, i, _) = from.fields();
        let mut candidate = from.with_time(h, i, 0, 0);
        let horizon = candidate.fields().0 + HORIZON_YEARS;

        // Each pass either returns or advances the coarsest mismatching
        // field, so sparse expressions skip months at a time instead of
        // stepping minute by minute.
        loop {
            let (year, month, ..) = candidate.fields();
            if year > horizon {
                return None;
            }

            if !has(self.months, month) {
                candidate = if month == 12 {
                    candidate.with_date(year + 1, 1, 1).with_time(0, 0, 0, 0)
                } else {
                    candidate.with_date(year, month + 1, 1).with_time(0, 0, 0, 0)
                };
                continue;
            }

            if !self.day_matches(&candidate) {
                candidate = candidate.with_time(0, 0, 0, 0).modified_by(&DAY);
                continue;
            }

            let hour = candidate.fields().3;
            if !has(self.hours, hour) {
                candidate = match first_above(self.hours, hour) {
                    None => candidate.with_time(0, 0, 0, 0).modified_by(&DAY),
                    Some(next) => candidate.with_time(next, 0, 0, 0),
                };
                continue;
            }

            let minute = candidate.fields().4;
            if !has(self.minutes, minute) {
                candidate = match first_above(self.minutes, minute) {
                    None => candidate.with_time(hour, 0, 0, 0).modified_by(&HOUR),
                    Some(next) => candidate.with_time(hour, next, 0, 0),
                };
                continue;
            }

            return Some(candidate);
        }
    }

    fn day_matches(&self, candidate: &DateTime) -> bool {
        let (_, _, day, ..) = candidate.fields();
        let weekday = candidate.day_of_week();
        let day_of_month = has(self.days_of_month, day);
        let day_of_week = has(self.days_of_week, weekday);

        if !self.rules.is_empty() {
            let last = candidate.days_in_month();
            if self.rules.iter().any(|rule| rule_matches(*rule, candidate, day, last, weekday)) {
                return true;
            }
        }

        // Vixie cron: two restricted day fields combine with OR, so
        // "0 0 13 * 5" means the 13th *or* any Friday, not Friday the 13th.
        if self.day_of_month_restricted && self.day_of_week_restricted {
            day_of_month || day_of_week
        } else {
            day_of_month && day_of_week
        }
    }
}

fn rule_matches(rule: DayRule, candidate: &DateTime, day: i64, last: i64, weekday: i64) -> bool {
    match rule {
        DayRule::Last(offset) => day == last - offset,
        DayRule::LastWeekday => day == nearest_weekday(candidate, last, last),
        DayRule::Weekday(target) => target <= last && day == nearest_weekday(candidate, target, last),
        DayRule::LastOf(of) => weekday == of && day + 7 > last,
        DayRule::Nth(of, nth) => weekday == of && (day - 1).div_euclid(7) + 1 == nth,
    }
}

/// The weekday nearest `day` inside `candidate`'s month: a Saturday shifts
/// back, a Sunday forward, and neither leaves the month.
fn nearest_weekday(candidate: &DateTime, day: i64, last: i64) -> i64 {
    let (year, month, ..) = candidate.fields();
    let target = candidate.with_date(year, month, day);
    match target.day_of_week() {
        6 => {
            if day > 1 {
                day - 1
            } else {
                day + 2
            }
        }
        0 => {
            if day < last {
                day + 1
            } else {
                day - 2
            }
        }
        _ => day,
    }
}

/// A day field, split into the plain value set and the Quartz rules that no
/// set can express. `?` reads as `*`.
fn parse_day_field(
    field: &[u8],
    min: i64,
    max: i64,
    names: &[(&[u8], i64)],
    week: bool,
) -> Result<(u64, Vec<DayRule>), Error> {
    let field: &[u8] = if field == b"?" { b"*" } else { field };
    let mut plain: Vec<&[u8]> = Vec::new();
    let mut rules = Vec::new();
    for part in field.split(|&c| c == b',') {
        let rule = if week { day_of_week_rule(part)? } else { day_of_month_rule(part)? };
        match rule {
            Some(rule) => rules.push(rule),
            None => plain.push(part),
        }
    }
    let set = if plain.is_empty() { 0 } else { parse_field(&plain.join(&b','), min, max, names)? };
    Ok((set, rules))
}

/// `L`, `L-n`, `LW` or `nW`, or `None` when the part is a plain value.
fn day_of_month_rule(part: &[u8]) -> Result<Option<DayRule>, Error> {
    if part == b"l" {
        return Ok(Some(DayRule::Last(0)));
    }
    if part == b"lw" {
        return Ok(Some(DayRule::LastWeekday));
    }
    if let Some(groups) = captures(b"/^l-(\\d+)$/", part) {
        let offset = str_to_int(&groups[0]);
        if offset > 30 {
            return Err(Error::InvalidArgument(format!("Cron value \"{}\" is out of range L-1-L-30", text(part))));
        }
        return Ok(Some(DayRule::Last(offset)));
    }
    if let Some(groups) = captures(b"/^(\\d+)w$/", part) {
        let day = str_to_int(&groups[0]);
        if !(1..=31).contains(&day) {
            return Err(Error::InvalidArgument(format!("Cron value \"{}\" is out of range 1-31", text(part))));
        }
        return Ok(Some(DayRule::Weekday(day)));
    }
    Ok(None)
}

/// `dL` or `d#n`, or `None` when the part is a plain value.
fn day_of_week_rule(part: &[u8]) -> Result<Option<DayRule>, Error> {
    if let Some(groups) = captures(b"/^(.+)l$/", part) {
        return Ok(Some(DayRule::LastOf(day_of_week_value(&groups[0])?)));
    }
    if let Some(groups) = captures(b"/^(.+)#(\\d+)$/", part) {
        let nth = str_to_int(&groups[1]);
        if !(1..=5).contains(&nth) {
            return Err(Error::InvalidArgument(format!(
                "Cron value \"{}\" is out of range 1-5 nth days of week",
                text(part)
            )));
        }
        return Ok(Some(DayRule::Nth(day_of_week_value(&groups[0])?, nth)));
    }
    Ok(None)
}

fn day_of_week_value(value_text: &[u8]) -> Result<i64, Error> {
    let day = value(value_text, DAYS)?;
    if !(0..=7).contains(&day) {
        return Err(Error::InvalidArgument(format!("Cron value \"{}\" is out of range 0-7", text(value_text))));
    }
    // Both 0 and 7 mean Sunday.
    Ok(if day == 7 { 0 } else { day })
}

/// The set of values a plain field lists, as bits.
fn parse_field(field: &[u8], min: i64, max: i64, names: &[(&[u8], i64)]) -> Result<u64, Error> {
    let mut set = 0u64;
    for part in field.split(|&c| c == b',') {
        let mut step = 1;
        let mut range = part;
        if let Some(slash) = part.iter().position(|&c| c == b'/') {
            let step_text = &part[slash + 1..];
            range = &part[..slash];
            if !ctype_digit(step_text) || str_to_int(step_text) < 1 {
                return Err(Error::InvalidArgument(format!("Invalid cron step \"{}\"", text(part))));
            }
            step = str_to_int(step_text);
        }

        let (low, high) = if range == b"*" {
            (min, max)
        } else if let Some(dash) = range.iter().position(|&c| c == b'-') {
            let low = value(&range[..dash], names)?;
            let high = value(&range[dash + 1..], names)?;
            if low > high {
                return Err(Error::InvalidArgument(format!("Invalid cron range \"{}\"", text(part))));
            }
            (low, high)
        } else {
            if step > 1 {
                // Steps apply to * or to a range, as in vixie cron.
                return Err(Error::InvalidArgument(format!("Invalid cron step \"{}\"", text(part))));
            }
            let low = value(range, names)?;
            (low, low)
        };

        if low < min || high > max {
            return Err(Error::InvalidArgument(format!("Cron value \"{}\" is out of range {min}-{max}", text(part))));
        }

        let mut value = low;
        while value <= high {
            set |= 1 << value;
            match value.checked_add(step) {
                Some(next) => value = next,
                None => break,
            }
        }
    }

    if set == 0 {
        return Err(Error::InvalidArgument(format!("Empty cron field \"{}\"", text(field))));
    }
    Ok(set)
}

fn value(value_text: &[u8], names: &[(&[u8], i64)]) -> Result<i64, Error> {
    if ctype_digit(value_text) {
        return Ok(str_to_int(value_text));
    }
    let name = strtoupper(value_text);
    names
        .iter()
        .find(|(n, _)| *n == &*name)
        .map(|(_, v)| *v)
        .ok_or_else(|| Error::InvalidArgument(format!("Invalid cron value \"{}\"", text(value_text))))
}

impl Trigger for Cron {
    /// Every matching minute inside `[start, end)`, in `start`'s timezone.
    fn occurrences_between(&self, start: &DateTime, end: &DateTime) -> Vec<DateTime> {
        let mut occurrences = Vec::new();
        let mut due = self.next_match(start);
        while let Some(current) = due {
            if current >= *end {
                break;
            }
            due = self.next_match(&current.modified_by(&MINUTE));
            occurrences.push(current);
        }
        occurrences
    }

    /// Always: a cron expression keeps firing.
    fn recurring(&self) -> bool {
        true
    }
}
