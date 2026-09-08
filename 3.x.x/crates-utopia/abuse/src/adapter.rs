use crate::error::AbuseError;
use crate::logs::Logs;

/// Shared key-pattern state (`Adapter::$params` / `$key`).
#[derive(Debug, Clone, Default)]
pub struct AdapterState {
    /// Substitution map; insertion order matches PHP array order.
    params: Vec<(String, String)>,
    /// Key pattern, mutated in place by [`Self::parse_key`] like PHP.
    key: String,
}

impl AdapterState {
    /// Create state with an initial key pattern.
    #[must_use]
    pub fn new(key: impl Into<String>) -> Self {
        Self {
            params: Vec::new(),
            key: key.into(),
        }
    }

    /// Current (possibly already substituted) key.
    #[must_use]
    pub fn key(&self) -> &str {
        &self.key
    }

    /// PHP `setParam($key, $value)`.
    pub fn set_param(&mut self, key: impl Into<String>, value: impl Into<String>) -> &mut Self {
        let key = key.into();
        let value = value.into();
        if let Some((_, existing)) = self.params.iter_mut().find(|(item, _)| item == &key) {
            *existing = value;
        } else {
            self.params.push((key, value));
        }
        self
    }

    /// PHP `getParams()`.
    #[must_use]
    pub fn params(&self) -> &[(String, String)] {
        &self.params
    }

    /// PHP `parseKey()`: `str_replace` each param into `$this->key` (mutates the key).
    pub fn parse_key(&mut self) -> String {
        for (key, value) in &self.params {
            self.key = self.key.replace(key, value);
        }
        self.key.clone()
    }
}

/// Storage backend used by [`crate::Abuse`].
///
/// `check() == true` means **abuse** for time-limit / token-bucket / sliding-window
/// adapters. [`crate::ReCaptcha`] matches PHP and returns `true` when the user looks human.
pub trait Adapter {
    /// PHP `check()`.
    ///
    /// # Errors
    ///
    /// Returns adapter-specific failures (Redis, HTTP, database, unsupported methods).
    fn check(&mut self) -> Result<bool, AbuseError>;

    /// PHP `setParam($key, $value)`.
    fn set_param(&mut self, key: &str, value: &str) -> &mut Self;

    /// PHP `parseKey()` (protected in PHP; public here so tests can assert substitution).
    fn parse_key(&mut self) -> String;

    /// PHP `getLogs(?int $offset = null, ?int $limit = 25)`.
    ///
    /// # Errors
    ///
    /// Returns storage or "method not supported" errors.
    fn get_logs(&mut self, offset: Option<i64>, limit: Option<i64>) -> Result<Logs, AbuseError>;

    /// PHP `cleanup(int $timestamp)`.
    ///
    /// # Errors
    ///
    /// Returns storage or "method not supported" errors.
    fn cleanup(&mut self, timestamp: i64) -> Result<bool, AbuseError>;

    /// PHP `reset()`.
    ///
    /// # Errors
    ///
    /// Returns storage or "method not supported" errors.
    fn reset(&mut self) -> Result<(), AbuseError>;
}

/// Remaining-count formula shared by time-limit / token-bucket / sliding-window:
/// `max(0, limit - (count + 1))`.
#[must_use]
pub fn remaining_from(limit: i64, count: i64) -> i64 {
    let left = limit - (count + 1);
    if left < 0 {
        0
    } else {
        left
    }
}
