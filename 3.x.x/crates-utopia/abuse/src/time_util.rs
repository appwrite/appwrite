use std::time::{SystemTime, UNIX_EPOCH};

/// Whole-second Unix timestamp (`time()`).
pub(crate) fn unix_now() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_secs() as i64)
        .unwrap_or(0)
}

/// Fractional Unix timestamp (`microtime(true)`).
pub(crate) fn unix_now_f64() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_secs_f64())
        .unwrap_or(0.0)
}

/// Align `now` down to a multiple of `seconds` (PHP `$now - ($now % $seconds)`).
#[must_use]
pub fn align_timestamp(now: i64, seconds: i64) -> i64 {
    if seconds == 0 {
        now
    } else {
        now - now.rem_euclid(seconds)
    }
}

/// PHP `DateTime::format('Y-m-d H:i:s.v')` in UTC for a Unix timestamp.
#[must_use]
pub fn format_datetime(timestamp: i64) -> String {
    let days = timestamp.div_euclid(86_400);
    let rem = timestamp.rem_euclid(86_400);
    let (year, month, day) = civil_from_days(days);
    let hour = rem / 3600;
    let minute = (rem % 3600) / 60;
    let second = rem % 60;
    format!("{year:04}-{month:02}-{day:02} {hour:02}:{minute:02}:{second:02}.000")
}

/// Howard Hinnant's civil-from-days (days since Unix epoch).
fn civil_from_days(days: i64) -> (i64, u32, u32) {
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = (z - era * 146_097) as u32;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let y = i64::from(yoe) + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    let y = y + i64::from(m <= 2);
    (y, m, d)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn formats_unix_epoch() {
        assert_eq!(format_datetime(0), "1970-01-01 00:00:00.000");
    }

    #[test]
    fn formats_known_timestamp() {
        assert_eq!(format_datetime(1_704_067_200), "2024-01-01 00:00:00.000");
    }

    #[test]
    fn aligns_window() {
        assert_eq!(align_timestamp(10, 5), 10);
        assert_eq!(align_timestamp(11, 5), 10);
        assert_eq!(align_timestamp(14, 5), 10);
    }
}
