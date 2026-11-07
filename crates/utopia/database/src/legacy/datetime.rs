//! Dates as the database stores them: `Y-m-d H:i:s.v` in the default
//! timezone ([`FORMAT_DB`]), and as the API shows them, with the offset
//! ([`FORMAT_TZ`]).
//!
//! Dates are [`php_std::datetime::DateTime`], a port of PHP's own date
//! library, so parsing, timezone conversion and formatting are PHP's.
//!
//! PHP: `Utopia\Database\DateTime`. PHP's default timezone
//! (`date_default_timezone_get()`) is UTC, as Appwrite sets it; here it is
//! UTC.
//!
//! ```
//! use utopia_database::datetime;
//!
//! assert_eq!(datetime::set_timezone("2024-01-02 03:04:05.678+02:00").unwrap(), "2024-01-02 01:04:05.678");
//! assert_eq!(datetime::format_tz(Some("2024-01-02 01:04:05.678")).as_deref(), Some("2024-01-02T01:04:05.678+00:00"));
//! ```

use php_std::datetime::{DateTime, Interval, TimeZone, TzInfo};

use crate::error::{Error, Kind, Result};

/// How the database stores dates (`DateTime::$formatDb`).
pub const FORMAT_DB: &str = "Y-m-d H:i:s.v";

/// How dates are shown with their offset (`DateTime::$formatTz`).
pub const FORMAT_TZ: &str = "Y-m-d\\TH:i:s.vP";

/// The current time in the database format.
///
/// PHP: `DateTime::now()`.
pub fn now() -> String {
    // "now" always parses.
    DateTime::new(b"now", None, &TzInfo::utc()).map(|date| format(&date)).unwrap_or_default()
}

/// `date` in the database format ([`FORMAT_DB`]).
///
/// PHP: `DateTime::format($date)`.
pub fn format(date: &DateTime) -> String {
    text(date.format(FORMAT_DB.as_bytes()))
}

/// Adds `seconds` (negative to subtract) to `date`, and returns it in the
/// database format.
///
/// Fails like PHP for `i64::MIN` seconds, which PHP's interval parser does
/// not read as a number (a [`Kind::DateMalformedIntervalString`] error).
///
/// PHP: `DateTime::addSeconds($date, $seconds)`.
pub fn add_seconds(date: &mut DateTime, seconds: i64) -> Result<String> {
    let interval = Interval::from_date_string(format!("{seconds} seconds").as_bytes())
        .map_err(|e| Error::new(Kind::DateMalformedIntervalString, e.message()))?;
    date.add(&interval);
    Ok(format(date))
}

/// `datetime` (any string PHP's `DateTime` parses) converted to the default
/// timezone, in the database format.
///
/// Fails with the parser's message when `datetime` is not a date
/// ([`Kind::Database`]).
///
/// PHP: `DateTime::setTimezone($datetime)`.
pub fn set_timezone(datetime: impl AsRef<[u8]>) -> Result<String> {
    let mut value = DateTime::new(datetime.as_ref(), None, &TzInfo::utc()).map_err(|e| Error::database(e.message()))?;
    value.set_timezone(&TimeZone::utc());
    Ok(format(&value))
}

/// `value` with its offset ([`FORMAT_TZ`]); `value` itself when it is not
/// a date, and `None` for `None`.
///
/// PHP: `DateTime::formatTz($dbFormat)`.
pub fn format_tz(value: Option<&str>) -> Option<String> {
    let value = value?;
    if let Some(formatted) = format_tz_stored(value) {
        return Some(formatted);
    }
    Some(match DateTime::new(value.as_bytes(), None, &TzInfo::utc()) {
        Ok(date) => text(date.format(FORMAT_TZ.as_bytes())),
        Err(_) => value.to_owned(),
    })
}

/// [`format_tz`] of a date as the database returns it: `Y-m-d H:i:s` with
/// up to six fraction digits and every field in range, which parses to
/// itself in UTC, so its digits are copied (milliseconds are the first three
/// fraction digits). `None` for anything else, which goes through the parser.
fn format_tz_stored(value: &str) -> Option<String> {
    let b = value.as_bytes();
    if b.len() < 19 || b[4] != b'-' || b[7] != b'-' || b[10] != b' ' || b[13] != b':' || b[16] != b':' {
        return None;
    }
    let number = |range: std::ops::Range<usize>| -> Option<u32> {
        b[range].iter().try_fold(0u32, |n, c| c.is_ascii_digit().then(|| n * 10 + u32::from(c - b'0')))
    };
    let (year, month, day) = (number(0..4)?, number(5..7)?, number(8..10)?);
    let (hour, minute, second) = (number(11..13)?, number(14..16)?, number(17..19)?);
    let leap = year % 4 == 0 && (year % 100 != 0 || year % 400 == 0);
    let days = match month {
        1 | 3 | 5 | 7 | 8 | 10 | 12 => 31,
        4 | 6 | 9 | 11 => 30,
        2 if leap => 29,
        2 => 28,
        _ => return None,
    };
    if year < 1000 || day == 0 || day > days || hour > 23 || minute > 59 || second > 59 {
        return None;
    }
    let fraction = match &b[19..] {
        [] => &[][..],
        [b'.', digits @ ..] if (1..=6).contains(&digits.len()) && digits.iter().all(u8::is_ascii_digit) => digits,
        _ => return None,
    };
    let mut out = String::with_capacity(29);
    out.push_str(&value[..10]);
    out.push('T');
    out.push_str(&value[11..19]);
    out.push('.');
    for i in 0..3 {
        out.push(char::from(*fraction.get(i).unwrap_or(&b'0')));
    }
    out.push_str("+00:00");
    Some(out)
}

/// Formatted bytes as text (the formats here only produce ASCII).
fn text(bytes: Vec<u8>) -> String {
    String::from_utf8(bytes).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned())
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The parser's answer, which the stored-date path must match.
    fn parsed(value: &str) -> String {
        match DateTime::new(value.as_bytes(), None, &TzInfo::utc()) {
            Ok(date) => text(date.format(FORMAT_TZ.as_bytes())),
            Err(_) => value.to_owned(),
        }
    }

    #[test]
    fn stored_dates_format_as_the_parser_does() {
        let mut values: Vec<String> = [
            "2024-01-02 01:04:05.678",
            "2024-01-02 01:04:05.67",
            "2024-01-02 01:04:05.6",
            "2024-01-02 01:04:05",
            "2024-01-02 01:04:05.000",
            "2024-01-02 01:04:05.123456",
            "2024-02-29 23:59:59.999",
            "2023-02-29 00:00:00.000",
            "2100-02-29 00:00:00.000",
            "2000-02-29 00:00:00.000",
            "2024-04-31 00:00:00.000",
            "2024-13-01 00:00:00.000",
            "2024-00-10 00:00:00.000",
            "2024-01-00 00:00:00.000",
            "2024-01-01 24:00:00.000",
            "2024-01-01 23:60:00.000",
            "2024-01-01 23:59:60.000",
            "0999-01-01 00:00:00.000",
            "2024-01-01 00:00:00.1234567",
            "2024-01-01 00:00:00.",
            "2024-01-01T00:00:00.000",
            "2024-01-01 00:00:00.000+02:00",
            "2024-01-01 00:00:00.000 ",
            "not a date",
        ]
        .map(str::to_owned)
        .to_vec();
        for year in [1970, 1999, 2000, 2024, 2025, 9999] {
            for month in 1..=12 {
                for day in [1, 28, 29, 30, 31] {
                    values.push(format!("{year:04}-{month:02}-{day:02} 13:07:59.042"));
                }
            }
        }
        for value in &values {
            assert_eq!(format_tz(Some(value)).unwrap(), parsed(value), "{value}");
        }
        assert_eq!(format_tz_stored("2024-01-02 01:04:05.67").as_deref(), Some("2024-01-02T01:04:05.670+00:00"));
        assert_eq!(format_tz_stored("2024-01-02T01:04:05.678"), None);
    }
}
