//! PHP `Utopia\Database\DateTime`.

use chrono::{Local, NaiveDateTime, TimeZone, Utc};

use crate::error::{DatabaseError, Result};

const FORMAT_DB: &str = "%Y-%m-%d %H:%M:%S%.3f";
const FORMAT_TZ: &str = "%Y-%m-%dT%H:%M:%S%.3f%:z";

/// PHP `Utopia\Database\DateTime`.
#[derive(Debug, Clone, Copy)]
pub struct DateTime;

impl DateTime {
    /// PHP `DateTime::now()`.
    #[must_use]
    pub fn now() -> String {
        let local = Local::now().naive_local();
        format_naive(local)
    }

    /// PHP `DateTime::format(\DateTime $date)`.
    #[must_use]
    pub fn format(date: NaiveDateTime) -> String {
        format_naive(date)
    }

    /// PHP `DateTime::addSeconds`.
    pub fn add_seconds(date: NaiveDateTime, seconds: i64) -> Result<String> {
        let Some(next) = date.checked_add_signed(chrono::Duration::seconds(seconds)) else {
            return Err(DatabaseError::database("Invalid interval"));
        };
        Ok(format_naive(next))
    }

    /// PHP `DateTime::setTimezone`.
    pub fn set_timezone(datetime: &str) -> Result<String> {
        let parsed = parse_php_datetime(datetime).ok_or_else(|| {
            DatabaseError::database(format!("Failed to parse time string ({datetime})"))
        })?;
        Ok(format_naive(parsed))
    }

    /// PHP `DateTime::formatTz`.
    #[must_use]
    pub fn format_tz(db_format: Option<&str>) -> Option<String> {
        let Some(db_format) = db_format else {
            return None;
        };
        match parse_php_datetime(db_format) {
            Some(naive) => {
                let local = Local
                    .from_local_datetime(&naive)
                    .single()
                    .unwrap_or_else(|| {
                        Local.from_utc_datetime(&Utc.from_utc_datetime(&naive).naive_utc())
                    });
                Some(local.format(FORMAT_TZ).to_string())
            }
            None => Some(db_format.to_owned()),
        }
    }
}

fn format_naive(date: NaiveDateTime) -> String {
    date.format(FORMAT_DB).to_string()
}

/// PHP `new \DateTime($value)` - several common formats.
#[must_use]
pub fn parse_php_datetime(value: &str) -> Option<NaiveDateTime> {
    let trimmed = value.trim();
    if trimmed.is_empty() {
        return None;
    }
    const FORMATS: &[&str] = &[
        "%Y-%m-%d %H:%M:%S%.f",
        "%Y-%m-%d %H:%M:%S",
        "%Y-%m-%dT%H:%M:%S%.fZ",
        "%Y-%m-%dT%H:%M:%S%.f%:z",
        "%Y-%m-%dT%H:%M:%S%.f%z",
        "%Y-%m-%dT%H:%M:%SZ",
        "%Y-%m-%dT%H:%M:%S%:z",
        "%Y-%m-%dT%H:%M:%S%z",
        "%Y-%m-%dT%H:%M:%S",
        "%Y-%m-%d",
        "%Y/%m/%d %H:%M:%S",
        "%d-%m-%Y %H:%M:%S",
    ];
    for fmt in FORMATS {
        if let Ok(dt) = NaiveDateTime::parse_from_str(trimmed, fmt) {
            return Some(dt);
        }
        if let Ok(d) = chrono::NaiveDate::parse_from_str(trimmed, fmt) {
            return d.and_hms_opt(0, 0, 0);
        }
        if let Ok(dt) = chrono::DateTime::parse_from_str(trimmed, fmt) {
            return Some(dt.naive_local());
        }
        if let Ok(dt) = chrono::DateTime::parse_from_rfc3339(trimmed) {
            return Some(dt.naive_local());
        }
    }
    None
}
