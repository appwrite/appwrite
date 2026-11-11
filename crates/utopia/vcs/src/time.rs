//! The clock: `time()`, `gmdate()` and Origin's microsecond timestamps.

use std::time::{SystemTime, UNIX_EPOCH};

/// `time()`.
pub(crate) fn now() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs() as i64).unwrap_or_default()
}

/// `gmdate($format)` now.
pub(crate) fn gmdate_now(format: &str) -> String {
    String::from_utf8_lossy(&php_std::datetime::gmdate(format.as_bytes(), now())).into_owned()
}

/// RFC 3339 now in UTC with microseconds (`2026-01-02T03:04:05.123456Z`).
///
/// PHP: Origin's `rfc3339Now()`: `DateTime::createFromFormat('U.u',
/// sprintf('%.6F', microtime(true)))`.
pub(crate) fn rfc3339_micros_now() -> String {
    let since = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
    let seconds = since.as_secs() as i64;
    let micros = since.subsec_micros();
    let base = String::from_utf8_lossy(&php_std::datetime::gmdate(b"Y-m-d\\TH:i:s", seconds)).into_owned();
    format!("{base}.{micros:06}Z")
}
