use parking_lot::Mutex;
use redis::cluster::ClusterConnection;
use serde_json::Value;

use super::bucket_key;
use super::redis_base::{count_estimate, WindowConfig, LIMIT_CHECK_SCRIPT};
use super::NAMESPACE;
use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::redis_ops::{bulk_values, slice_logs, value_as_i64, ClusterConnectionExt};

/// PHP `Utopia\Abuse\Adapters\SlidingWindow\RedisCluster`.
pub struct RedisCluster<C: ClusterConnectionExt = ClusterConnection> {
    state: AdapterState,
    config: WindowConfig,
    timestamp: i64,
    conn: Mutex<C>,
}

impl<C: ClusterConnectionExt> std::fmt::Debug for RedisCluster<C> {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("RedisCluster")
            .field("limit", &self.config.limit)
            .finish_non_exhaustive()
    }
}

impl RedisCluster<ClusterConnection> {
    /// PHP `new RedisCluster($key, $limit, $windowSize, $ttl, $redis)`.
    ///
    /// # Errors
    ///
    /// Window size / TTL guards.
    pub fn new(
        key: impl Into<String>,
        limit: i64,
        window_size: i64,
        ttl: i64,
        connection: ClusterConnection,
    ) -> Result<Self, AbuseError> {
        Self::with_connection(key, limit, window_size, ttl, connection)
    }

    /// Open from node URLs.
    ///
    /// # Errors
    ///
    /// Redis or window-config errors.
    pub fn from_nodes(
        key: impl Into<String>,
        limit: i64,
        window_size: i64,
        ttl: i64,
        nodes: &[String],
    ) -> Result<Self, AbuseError> {
        let client = redis::cluster::ClusterClient::new(nodes.to_vec())?;
        Self::new(key, limit, window_size, ttl, client.get_connection()?)
    }
}

impl<C: ClusterConnectionExt> RedisCluster<C> {
    /// Build against any cluster connection.
    ///
    /// # Errors
    ///
    /// Window size / TTL guards.
    pub fn with_connection(
        key: impl Into<String>,
        limit: i64,
        window_size: i64,
        ttl: i64,
        connection: C,
    ) -> Result<Self, AbuseError> {
        let config = WindowConfig::init(limit, window_size, ttl)?;
        let (timestamp, _) = config.window();
        Ok(Self {
            state: AdapterState::new(key),
            config,
            timestamp,
            conn: Mutex::new(connection),
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
        let current_raw = self
            .conn
            .lock()
            .get_string(&bucket_key(key, window_start))?;
        let previous_raw = self
            .conn
            .lock()
            .get_string(&bucket_key(key, window_start - self.config.window_size))?;
        let current = current_raw
            .as_deref()
            .and_then(|text| text.parse().ok())
            .unwrap_or(0);
        let previous = previous_raw
            .as_deref()
            .and_then(|text| text.parse().ok())
            .unwrap_or(0);
        Ok(count_estimate(current, previous, elapsed))
    }

    /// PHP `remaining()`.
    ///
    /// # Errors
    ///
    /// Redis failures.
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

impl<C: ClusterConnectionExt> Adapter for RedisCluster<C> {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.config.limit == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        let (timestamp, elapsed) = self.config.window();
        self.timestamp = timestamp;
        let result = self.conn.lock().eval_script(
            LIMIT_CHECK_SCRIPT,
            &[
                bucket_key(&key, timestamp),
                bucket_key(&key, timestamp - self.config.window_size),
            ],
            &[
                self.config.limit.to_string(),
                elapsed.to_string(),
                self.config.ttl.to_string(),
            ],
        )?;
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
        let keys = self.conn.lock().scan_pattern(&format!("{NAMESPACE}__*"))?;
        let matches = slice_logs(keys, offset, limit);
        if matches.is_empty() {
            return Ok(Logs::empty());
        }
        let values = self.conn.lock().mget_strings(&matches)?;
        let logs = matches
            .into_iter()
            .zip(values)
            .map(|(key, value)| (key, value.map_or(Value::Null, Value::String)))
            .collect();
        Ok(Logs::Map(logs))
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
        self.conn
            .lock()
            .delete_keys(&[current.as_str(), previous.as_str()])
    }
}
