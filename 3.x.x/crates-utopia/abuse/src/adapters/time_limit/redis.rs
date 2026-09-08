use parking_lot::Mutex;
use redis::Connection;
use serde_json::Value;

use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::redis_ops::{get_string, incr_expire, scan_once, set_expire};
use crate::time_util::{align_timestamp, unix_now};

use super::redis_key;
use super::NAMESPACE;

/// PHP `Utopia\Abuse\Adapters\TimeLimit\Redis`.
pub struct Redis {
    state: AdapterState,
    limit: i64,
    timestamp: i64,
    ttl: i64,
    count: Option<i64>,
    conn: Mutex<Connection>,
}

impl std::fmt::Debug for Redis {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("Redis")
            .field("limit", &self.limit)
            .field("timestamp", &self.timestamp)
            .finish_non_exhaustive()
    }
}

impl Redis {
    /// PHP `new Redis($key, $limit, $seconds, $redis)`.
    #[must_use]
    pub fn new(key: impl Into<String>, limit: i64, seconds: i64, connection: Connection) -> Self {
        let now = unix_now();
        Self {
            state: AdapterState::new(key),
            limit,
            timestamp: align_timestamp(now, seconds),
            ttl: seconds,
            count: None,
            conn: Mutex::new(connection),
        }
    }

    /// Connect via `REDIS_URL`-style URL.
    ///
    /// # Errors
    ///
    /// Redis connection failures.
    pub fn from_url(
        key: impl Into<String>,
        limit: i64,
        seconds: i64,
        url: &str,
    ) -> Result<Self, AbuseError> {
        let client = redis::Client::open(url)?;
        Ok(Self::new(key, limit, seconds, client.get_connection()?))
    }

    /// PHP `NAMESPACE`.
    pub const NAMESPACE: &'static str = NAMESPACE;

    fn count(&mut self, key: &str, timestamp: i64) -> Result<i64, AbuseError> {
        if self.limit == 0 {
            return Ok(0);
        }
        if let Some(count) = self.count {
            return Ok(count);
        }
        let redis_key = redis_key(key, timestamp);
        let raw = get_string(&mut *self.conn.lock(), &redis_key)?;
        let count = raw
            .as_deref()
            .and_then(|text| text.parse().ok())
            .unwrap_or(0);
        self.count = Some(count);
        Ok(count)
    }

    fn hit(&mut self, key: &str, timestamp: i64) -> Result<(), AbuseError> {
        if self.limit == 0 {
            return Ok(());
        }
        let redis_key = redis_key(key, timestamp);
        incr_expire(&mut *self.conn.lock(), &redis_key, self.ttl)?;
        self.count = Some(self.count.unwrap_or(0) + 1);
        Ok(())
    }

    fn set(&mut self, key: &str, timestamp: i64, value: i64) -> Result<(), AbuseError> {
        let redis_key = redis_key(key, timestamp);
        set_expire(
            &mut *self.conn.lock(),
            &redis_key,
            &value.to_string(),
            self.ttl,
        )?;
        self.count = Some(value);
        Ok(())
    }

    /// PHP `remaining()`.
    ///
    /// # Errors
    ///
    /// Redis failures.
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

impl Adapter for Redis {
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

    fn get_logs(&mut self, _offset: Option<i64>, limit: Option<i64>) -> Result<Logs, AbuseError> {
        let count = limit.unwrap_or(25);
        let keys = scan_once(&mut *self.conn.lock(), &format!("{NAMESPACE}__*"), count)?;
        if keys.is_empty() {
            return Ok(Logs::empty());
        }
        let mut logs = Vec::new();
        for key in keys {
            let value = get_string(&mut *self.conn.lock(), &key)?;
            logs.push((key, value.map_or(Value::Null, Value::String)));
        }
        Ok(Logs::Map(logs))
    }

    fn cleanup(&mut self, _timestamp: i64) -> Result<bool, AbuseError> {
        Ok(true)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        let key = self.state.parse_key();
        self.set(&key, self.timestamp, 0)
    }
}
