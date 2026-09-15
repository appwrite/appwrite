use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::time_util::{align_timestamp, unix_now};

/// PHP `Utopia\Abuse\Adapters\SlidingWindow\None`.
#[derive(Debug, Clone)]
pub struct None {
    state: AdapterState,
    limit: i64,
    timestamp: i64,
}

impl None {
    /// PHP `new None($key, $limit, $windowSize, $ttl)`.
    ///
    /// # Errors
    ///
    /// [`AbuseError::InvalidWindowSize`].
    pub fn new(
        key: impl Into<String>,
        limit: i64,
        window_size: i64,
        _ttl: i64,
    ) -> Result<Self, AbuseError> {
        if window_size <= 0 {
            return Err(AbuseError::InvalidWindowSize);
        }
        let now = unix_now();
        Ok(Self {
            state: AdapterState::new(key),
            limit,
            timestamp: align_timestamp(now, window_size),
        })
    }

    /// PHP `remaining()`.
    #[must_use]
    pub fn remaining(&self) -> i64 {
        remaining_from(self.limit, 0)
    }

    /// PHP `limit()`.
    #[must_use]
    pub fn limit(&self) -> i64 {
        self.limit
    }

    /// PHP `time()`.
    #[must_use]
    pub fn time(&self) -> i64 {
        self.timestamp
    }
}

impl Adapter for None {
    fn check(&mut self) -> Result<bool, AbuseError> {
        Ok(false)
    }

    fn set_param(&mut self, key: &str, value: &str) -> &mut Self {
        self.state.set_param(key, value);
        self
    }

    fn parse_key(&mut self) -> String {
        self.state.parse_key()
    }

    fn get_logs(&mut self, _offset: Option<i64>, _limit: Option<i64>) -> Result<Logs, AbuseError> {
        Ok(Logs::empty())
    }

    fn cleanup(&mut self, _timestamp: i64) -> Result<bool, AbuseError> {
        Ok(true)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        Ok(())
    }
}
