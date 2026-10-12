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
    Some(match DateTime::new(value.as_bytes(), None, &TzInfo::utc()) {
        Ok(date) => text(date.format(FORMAT_TZ.as_bytes())),
        Err(_) => value.to_owned(),
    })
}

/// Formatted bytes as text (the formats here only produce ASCII).
fn text(bytes: Vec<u8>) -> String {
    String::from_utf8(bytes).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned())
}
