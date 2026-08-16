use serde_json::Value;

use super::bucket_key;
use super::redis_base::{count_estimate, WindowConfig, LIMIT_CHECK_SCRIPT};
use super::NAMESPACE;
use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::redis_ops::{bulk_values, slice_logs, value_as_i64};
use crate::redis_pool::Pool;

/// PHP `Utopia\Abuse\Adapters\SlidingWindow\RedisPool`.
pub struct RedisPool {
    state: AdapterState,
    config: WindowConfig,
    timestamp: i64,
    pool: Pool,
}

impl std::fmt::Debug for RedisPool {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("RedisPool")
            .field("limit", &self.config.limit)
            .finish_non_exhaustive()
    }
}

impl RedisPool {
    /// PHP `new RedisPool($key, $limit, $windowSize, $ttl, $pool)`.
    ///
    /// # Errors
    ///
    /// Window size / TTL guards.
    pub fn new(
        key: impl Into<String>,
        limit: i64,
        window_size: i64,
        ttl: i64,
        pool: Pool,
    ) -> Result<Self, AbuseError> {
        let config = WindowConfig::init(limit, window_size, ttl)?;
        let (timestamp, _) = config.window();
        Ok(Self {
            state: AdapterState::new(key),
            config,
            timestamp,
            pool,
        })
    }

    /// PHP `NAMESPACE`.
    pub const NAMESPACE: &'static str = NAMESPACE;

    fn count(&mut self, key: &str) -> Result<i64, AbuseError> {
        if self.config.limit == 0 {
            return Ok(0);
        }
        let (window_start, elapsed) = self.config.window();
        self.timestamp = window_start;
        let current_key = bucket_key(key, window_start);
        let previous_key = bucket_key(key, window_start - self.config.window_size);
        let (current, previous) = self.pool.use_connection(|conn| {
            let current = conn
                .get_string(&current_key)?
                .as_deref()
                .and_then(|text| text.parse().ok())
                .unwrap_or(0);
            let previous = conn
                .get_string(&previous_key)?
                .as_deref()
                .and_then(|text| text.parse().ok())
                .unwrap_or(0);
            Ok((current, previous))
        })?;
        Ok(count_estimate(current, previous, elapsed))
    }

    /// PHP `remaining()`.
    ///
    /// # Errors
    ///
    /// Redis / pool failures.
    pub fn remaining(&mut self) -> Result<i64, AbuseError> {
        let key = self.state.parse_key();
        Ok(remaining_from(self.config.limit, self.count(&key)?))
    }

    /// PHP `limit()`.
    #[must_use]
    pub fn limit(&self) -> i64 {
        self.config.limit
    }

    /// PHP `time()`.
    pub fn time(&mut self) -> i64 {
        let (timestamp, _) = self.config.window();
        self.timestamp = timestamp;
        timestamp
    }
}

impl Adapter for RedisPool {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.config.limit == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        let (timestamp, elapsed) = self.config.window();
        self.timestamp = timestamp;
        let keys = [
            bucket_key(&key, timestamp),
            bucket_key(&key, timestamp - self.config.window_size),
        ];
        let argv = [
            self.config.limit.to_string(),
            elapsed.to_string(),
            self.config.ttl.to_string(),
        ];
        let result = self
            .pool
            .use_connection(|conn| conn.eval_script(LIMIT_CHECK_SCRIPT, &keys, &argv))?;
        let items = bulk_values(result)?;
        let allowed = items.first().map_or(0, value_as_i64);
        Ok(allowed == 0)
    }

    fn set_param(&mut self, key: &str, value: &str) -> &mut Self {
        self.state.set_param(key, value);
        self
    }

    fn parse_key(&mut self) -> String {
        self.state.parse_key()
    }

    fn get_logs(&mut self, offset: Option<i64>, limit: Option<i64>) -> Result<Logs, AbuseError> {
        self.pool.use_connection(|conn| {
            let keys = conn.scan_all(&format!("{NAMESPACE}__*"))?;
            let matches = slice_logs(keys, offset, limit);
            if matches.is_empty() {
                return Ok(Logs::empty());
            }
            let mut logs = Vec::new();
            if conn.is_cluster() {
                let values = conn.mget_strings(&matches)?;
                for (key, value) in matches.into_iter().zip(values) {
                    logs.push((key, value.map_or(Value::Null, Value::String)));
                }
            } else {
                for key in matches {
                    let value = conn.get_string(&key)?;
                    logs.push((key, value.map_or(Value::Null, Value::String)));
                }
            }
            Ok(Logs::Map(logs))
        })
    }

    fn cleanup(&mut self, _timestamp: i64) -> Result<bool, AbuseError> {
        Ok(true)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        let key = self.state.parse_key();
        let (window_start, _) = self.config.window();
        self.timestamp = window_start;
        let current = bucket_key(&key, window_start);
        let previous = bucket_key(&key, window_start - self.config.window_size);
        self.pool
            .use_connection(|conn| conn.delete_keys(&[current.as_str(), previous.as_str()]))
    }
}
