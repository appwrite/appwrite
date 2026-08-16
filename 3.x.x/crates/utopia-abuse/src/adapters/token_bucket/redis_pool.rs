use super::bucket_key;
use super::redis_base::{BucketConfig, LIMIT_CHECK_SCRIPT, TOKENS_SCRIPT};
use super::NAMESPACE;
use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::redis_ops::{bulk_values, slice_logs, value_as_i64, value_as_string};
use crate::redis_pool::Pool;
use crate::time_util::{unix_now, unix_now_f64};

/// PHP `Utopia\Abuse\Adapters\TokenBucket\RedisPool`.
pub struct RedisPool {
    state: AdapterState,
    config: BucketConfig,
    timestamp: i64,
    pool: Pool,
}

impl std::fmt::Debug for RedisPool {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("RedisPool")
            .field("tokens", &self.config.tokens)
            .finish_non_exhaustive()
    }
}

impl RedisPool {
    /// PHP `new RedisPool($key, $tokens, $refillRate, $pool)`.
    ///
    /// # Errors
    ///
    /// [`AbuseError::InvalidRefillRate`].
    pub fn new(
        key: impl Into<String>,
        tokens: i64,
        refill_rate: f64,
        pool: Pool,
    ) -> Result<Self, AbuseError> {
        Ok(Self {
            state: AdapterState::new(key),
            config: BucketConfig::init(tokens, refill_rate)?,
            timestamp: unix_now(),
            pool,
        })
    }

    /// PHP `NAMESPACE`.
    pub const NAMESPACE: &'static str = NAMESPACE;

    fn count(&mut self, key: &str) -> Result<i64, AbuseError> {
        if self.config.tokens == 0 {
            return Ok(0);
        }
        self.timestamp = unix_now();
        let tokens = self.config.tokens;
        let refill = self.config.refill_rate;
        let bucket = bucket_key(key);
        let raw = self.pool.use_connection(|conn| {
            conn.eval_script(
                TOKENS_SCRIPT,
                &[bucket],
                &[
                    tokens.to_string(),
                    refill.to_string(),
                    unix_now_f64().to_string(),
                ],
            )
        })?;
        let balance: f64 = value_as_string(&raw).parse().unwrap_or(tokens as f64);
        Ok(tokens - balance.floor() as i64)
    }

    /// PHP `remaining()`.
    ///
    /// # Errors
    ///
    /// Redis / pool failures.
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

impl Adapter for RedisPool {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.config.tokens == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        self.timestamp = unix_now();
        let tokens = self.config.tokens;
        let refill = self.config.refill_rate;
        let bucket = bucket_key(&key);
        let result = self.pool.use_connection(|conn| {
            conn.eval_script(
                LIMIT_CHECK_SCRIPT,
                &[bucket],
                &[
                    tokens.to_string(),
                    refill.to_string(),
                    unix_now_f64().to_string(),
                ],
            )
        })?;
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
            for key in matches {
                let hash = conn.hash_get_all(&key)?;
                logs.push((key, serde_json::json!(hash)));
            }
            Ok(Logs::Map(logs))
        })
    }

    fn cleanup(&mut self, _timestamp: i64) -> Result<bool, AbuseError> {
        Ok(true)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        let key = bucket_key(&self.state.parse_key());
        self.pool
            .use_connection(|conn| conn.delete_keys(&[key.as_str()]))
    }
}
