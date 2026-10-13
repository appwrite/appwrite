//! Datetime handling with `Utopia\Database\DateTime` formats.
//!
//! * storage / bind format: `Y-m-d H:i:s.v` (UTC, `TIMESTAMP(3)` columns)
//! * API format (`formatTz`): `Y-m-d\TH:i:s.vP`, always `+00:00`

use chrono::{DateTime, Duration, FixedOffset, NaiveDate, NaiveDateTime, NaiveTime, TimeZone, Timelike, Utc};

/// Current UTC time truncated to milliseconds (`DateTime::now()`).
pub fn now() -> NaiveDateTime {
    truncate(Utc::now().naive_utc())
}

/// Drops sub-millisecond precision (TIMESTAMP(3) semantics).
pub fn truncate(dt: NaiveDateTime) -> NaiveDateTime {
    let nanos = dt.nanosecond() / 1_000_000 * 1_000_000;
    dt.with_nanosecond(nanos).unwrap_or(dt)
}

/// `Y-m-d H:i:s.v`.
pub fn format_db(dt: &NaiveDateTime) -> String {
    dt.format("%Y-%m-%d %H:%M:%S%.3f").to_string()
}

/// `Y-m-d\TH:i:s.v+00:00`.
pub fn format_tz(dt: &NaiveDateTime) -> String {
    dt.format("%Y-%m-%dT%H:%M:%S%.3f+00:00").to_string()
}

/// Adds seconds (`DateTime::addSeconds`).
pub fn add_seconds(dt: NaiveDateTime, seconds: i64) -> NaiveDateTime {
    dt + Duration::seconds(seconds)
}

/// Parses the date formats Appwrite clients send (a subset of PHP's
/// permissive `new \DateTime()`): `Y-m-d`, `Y-m-d H:i[:s[.f]]`, ISO 8601 with
/// `T`, optional fraction and optional `Z` / `±hh:mm` / `±hhmm` offsets.
/// The result is normalised to UTC.
pub fn parse(input: &str) -> Option<NaiveDateTime> {
    let s = input.trim();
    if s.is_empty() {
        return None;
    }
    if let Ok(dt) = DateTime::parse_from_rfc3339(s) {
        return Some(dt.with_timezone(&Utc).naive_utc());
    }
    for fmt in
        ["%Y-%m-%dT%H:%M:%S%.f%:z", "%Y-%m-%dT%H:%M:%S%.f%z", "%Y-%m-%d %H:%M:%S%.f%:z", "%Y-%m-%d %H:%M:%S%.f%z"]
    {
        if let Ok(dt) = DateTime::<FixedOffset>::parse_from_str(s, fmt) {
            return Some(dt.with_timezone(&Utc).naive_utc());
        }
    }
    let s = s.strip_suffix('Z').unwrap_or(s);
    for fmt in ["%Y-%m-%dT%H:%M:%S%.f", "%Y-%m-%d %H:%M:%S%.f", "%Y-%m-%dT%H:%M", "%Y-%m-%d %H:%M"] {
        if let Ok(dt) = NaiveDateTime::parse_from_str(s, fmt) {
            return Some(dt);
        }
    }
    if let Ok(d) = NaiveDate::parse_from_str(s, "%Y-%m-%d") {
        return Some(d.and_time(NaiveTime::MIN));
    }
    None
}

/// Converts a stored value into the API format; invalid input is returned unchanged.
pub fn format_tz_str(stored: &str) -> String {
    match parse(stored) {
        Some(dt) => format_tz(&dt),
        None => stored.to_owned(),
    }
}

/// Unix timestamp helper.
pub fn from_unix(secs: i64) -> NaiveDateTime {
    Utc.timestamp_opt(secs, 0).single().map(|d| d.naive_utc()).unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn formats() {
        let dt = parse("2024-01-02 03:04:05.6").unwrap();
        assert_eq!(format_db(&dt), "2024-01-02 03:04:05.600");
        assert_eq!(format_tz(&dt), "2024-01-02T03:04:05.600+00:00");
        let dt = parse("2024-01-02T05:04:05.123+02:00").unwrap();
        assert_eq!(format_db(&dt), "2024-01-02 03:04:05.123");
        assert_eq!(format_db(&parse("2024-01-02").unwrap()), "2024-01-02 00:00:00.000");
        assert!(parse("nope").is_none());
    }
}
