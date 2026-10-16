use parking_lot::Mutex;
use redis::cluster::ClusterConnection;

use super::bucket_key;
use super::redis_base::{BucketConfig, LIMIT_CHECK_SCRIPT, TOKENS_SCRIPT};
use super::NAMESPACE;
use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::redis_ops::{
    bulk_values, slice_logs, value_as_i64, value_as_string, ClusterConnectionExt,
};
use crate::time_util::{unix_now, unix_now_f64};

/// PHP `Utopia\Abuse\Adapters\TokenBucket\RedisCluster`.
pub struct RedisCluster<C: ClusterConnectionExt = ClusterConnection> {
    state: AdapterState,
    config: BucketConfig,
    timestamp: i64,
    conn: Mutex<C>,
}

impl<C: ClusterConnectionExt> std::fmt::Debug for RedisCluster<C> {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("RedisCluster")
            .field("tokens", &self.config.tokens)
            .finish_non_exhaustive()
    }
}

impl RedisCluster<ClusterConnection> {
    /// PHP `new RedisCluster($key, $tokens, $refillRate, $redis)`.
    ///
    /// # Errors
    ///
    /// [`AbuseError::InvalidRefillRate`].
    pub fn new(
        key: impl Into<String>,
        tokens: i64,
        refill_rate: f64,
        connection: ClusterConnection,
    ) -> Result<Self, AbuseError> {
        Self::with_connection(key, tokens, refill_rate, connection)
    }

    /// Open from node URLs.
    ///
    /// # Errors
    ///
    /// Redis or refill-rate errors.
    pub fn from_nodes(
        key: impl Into<String>,
        tokens: i64,
        refill_rate: f64,
        nodes: &[String],
    ) -> Result<Self, AbuseError> {
        let client = redis::cluster::ClusterClient::new(nodes.to_vec())?;
        Self::new(key, tokens, refill_rate, client.get_connection()?)
    }
}

impl<C: ClusterConnectionExt> RedisCluster<C> {
    /// Build against any cluster connection.
    ///
    /// # Errors
    ///
    /// [`AbuseError::InvalidRefillRate`].
    pub fn with_connection(
        key: impl Into<String>,
        tokens: i64,
        refill_rate: f64,
        connection: C,
    ) -> Result<Self, AbuseError> {
        Ok(Self {
            state: AdapterState::new(key),
            config: BucketConfig::init(tokens, refill_rate)?,
            timestamp: unix_now(),
            conn: Mutex::new(connection),
        })
    }

    /// PHP `NAMESPACE`.
    pub const NAMESPACE: &'static str = NAMESPACE;

    fn count(&mut self, key: &str) -> Result<i64, AbuseError> {
        if self.config.tokens == 0 {
            return Ok(0);
        }
        self.timestamp = unix_now();
        let raw = self.conn.lock().eval_script(
            TOKENS_SCRIPT,
            &[bucket_key(key)],
            &[
                self.config.tokens.to_string(),
                self.config.refill_rate.to_string(),
                unix_now_f64().to_string(),
            ],
        )?;
        let balance: f64 = value_as_string(&raw)
            .parse()
            .unwrap_or(self.config.tokens as f64);
        Ok(self.config.tokens - balance.floor() as i64)
    }

    /// PHP `remaining()`.
    ///
    /// # Errors
    ///
    /// Redis failures.
    pub fn remaining(&mut self) -> Result<i64, AbuseError> {
        let key = self.state.parse_key();
        Ok(remaining_from(self.config.tokens, self.count(&key)?))
    }

    /// PHP `limit()`.
    #[must_use]
    pub fn limit(&self) -> i64 {
        self.config.tokens
    }

    /// PHP `time()`.
    #[must_use]
    pub fn time(&self) -> i64 {
        self.timestamp
    }
}

impl<C: ClusterConnectionExt> Adapter for RedisCluster<C> {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.config.tokens == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        self.timestamp = unix_now();
        let result = self.conn.lock().eval_script(
            LIMIT_CHECK_SCRIPT,
            &[bucket_key(&key)],
            &[
                self.config.tokens.to_string(),
                self.config.refill_rate.to_string(),
                unix_now_f64().to_string(),
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
        let mut logs = Vec::new();
        for key in matches {
            let hash = self.conn.lock().hash_get_all(&key)?;
            logs.push((key, serde_json::json!(hash)));
        }
        Ok(Logs::Map(logs))
    }

    fn cleanup(&mut self, _timestamp: i64) -> Result<bool, AbuseError> {
        Ok(true)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        let key = bucket_key(&self.state.parse_key());
        self.conn.lock().delete_keys(&[key.as_str()])
    }
}
