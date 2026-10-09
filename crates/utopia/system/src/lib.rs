//! Environment access with the same semantics as `Utopia\System\System::getEnv`.
//!
//! PHP reads variables with `getenv($name) ?: $default`, so a variable that is
//! unset, empty or `"0"` falls back to the default. Every Appwrite setting is
//! read through these helpers so Rust and PHP agree on configuration.

use std::env;

/// Returns the variable, or `None` when it is unset, empty or `"0"`.
pub fn env(name: &str) -> Option<String> {
    match env::var(name) {
        Ok(value) if !value.is_empty() && value != "0" => Some(value),
        _ => None,
    }
}

/// Returns the variable, or `default` when it is unset, empty or `"0"`.
pub fn env_or(name: &str, default: &str) -> String {
    env(name).unwrap_or_else(|| default.to_owned())
}

/// Reads a variable like PHP's raw `getenv` (empty strings are kept).
pub fn env_raw(name: &str) -> Option<String> {
    env::var(name).ok()
}

/// Parses a variable as an integer, falling back to `default`.
pub fn env_int(name: &str, default: i64) -> i64 {
    env(name).and_then(|v| v.trim().parse().ok()).unwrap_or(default)
}

/// `true` when the variable equals `"enabled"` (the Appwrite convention).
pub fn enabled(name: &str, default: bool) -> bool {
    match env(name) {
        Some(v) => v == "enabled",
        None => default,
    }
}

/// Hostname of this machine (`gethostname()`), used in cache keys and log tags.
pub fn hostname() -> String {
    if let Ok(h) = std::fs::read_to_string("/etc/hostname") {
        let h = h.trim();
        if !h.is_empty() {
            return h.to_owned();
        }
    }
    env::var("HOSTNAME").unwrap_or_else(|_| "localhost".to_owned())
}

/// Number of CPUs available to the process.
pub fn cpus() -> usize {
    std::thread::available_parallelism().map(|n| n.get()).unwrap_or(1)
}
