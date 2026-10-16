use serde_json::Value;

use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::redis_ops::slice_logs;
use crate::redis_pool::Pool;
use crate::time_util::{align_timestamp, unix_now};

use super::redis_key;
use super::NAMESPACE;

/// PHP `Utopia\Abuse\Adapters\TimeLimit\RedisPool`.
pub struct RedisPool {
    state: AdapterState,
    limit: i64,
    timestamp: i64,
    ttl: i64,
    count: Option<i64>,
    pool: Pool,
}

impl std::fmt::Debug for RedisPool {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("RedisPool")
            .field("limit", &self.limit)
            .field("timestamp", &self.timestamp)
            .finish_non_exhaustive()
    }
}

impl RedisPool {
    /// PHP `new RedisPool($key, $limit, $seconds, $pool)`.
    #[must_use]
    pub fn new(key: impl Into<String>, limit: i64, seconds: i64, pool: Pool) -> Self {
        let now = unix_now();
        Self {
            state: AdapterState::new(key),
            limit,
            timestamp: align_timestamp(now, seconds),
            ttl: seconds,
            count: None,
            pool,
        }
    }

    fn count(&mut self, key: &str, timestamp: i64) -> Result<i64, AbuseError> {
        if self.limit == 0 {
            return Ok(0);
        }
        if let Some(count) = self.count {
            return Ok(count);
        }
        let redis_key = redis_key(key, timestamp);
        let count = self.pool.use_connection(|conn| {
            let raw = conn.get_string(&redis_key)?;
            Ok(raw
                .as_deref()
                .and_then(|text| text.parse::<i64>().ok())
                .unwrap_or(0))
        })?;
        self.count = Some(count);
        Ok(count)
    }

    fn hit(&mut self, key: &str, timestamp: i64) -> Result<(), AbuseError> {
        if self.limit == 0 {
            return Ok(());
        }
        let redis_key = redis_key(key, timestamp);
        let ttl = self.ttl;
        self.pool
            .use_connection(|conn| conn.incr_expire_checked(&redis_key, ttl))?;
        self.count = Some(self.count.unwrap_or(0) + 1);
        Ok(())
    }

    fn set(&mut self, key: &str, timestamp: i64, value: i64) -> Result<(), AbuseError> {
        let redis_key = redis_key(key, timestamp);
        let ttl = self.ttl;
        self.pool
            .use_connection(|conn| conn.set_expire_checked(&redis_key, &value.to_string(), ttl))?;
        self.count = Some(value);
        Ok(())
    }

    /// PHP `remaining()`.
    ///
    /// # Errors
    ///
    /// Redis / pool failures.
    pub fn remaining(&mut self) -> Result<i64, AbuseError> {
        let key = self.state.parse_key();
        let count = self.count(&key, self.timestamp)?;
        Ok(remaining_from(self.limit, count))
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

impl Adapter for RedisPool {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.limit == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        let timestamp = self.timestamp;
        if self.limit > self.count(&key, timestamp)? {
            self.hit(&key, timestamp)?;
            return Ok(false);
        }
        Ok(true)
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
        self.set(&key, self.timestamp, 0)
    }
}
