use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, Instant};

use parking_lot::Mutex;

use super::redis_base::BucketState;
use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::time_util::{unix_now, unix_now_f64};

#[derive(Debug, Clone)]
struct Entry {
    state: BucketState,
    expires_at: Instant,
}

/// Shared in-memory token-bucket store.
#[derive(Debug, Clone, Default)]
pub struct MemoryStore {
    inner: Arc<Mutex<HashMap<String, Entry>>>,
}

impl MemoryStore {
    /// Empty store.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    fn get_or_full(&self, key: &str, max_tokens: f64, now: f64) -> BucketState {
        let mut map = self.inner.lock();
        match map.get(key) {
            Some(entry) if entry.expires_at > Instant::now() => entry.state.clone(),
            _ => {
                map.remove(key);
                BucketState {
                    tokens: max_tokens,
                    last_refill: now,
                }
            }
        }
    }

    fn put(&self, key: &str, state: BucketState, ttl: Duration) {
        self.inner.lock().insert(
            key.to_owned(),
            Entry {
                state,
                expires_at: Instant::now() + ttl,
            },
        );
    }

    fn delete(&self, key: &str) {
        self.inner.lock().remove(key);
    }
}

/// In-memory token bucket with the same refill/consume math as the Redis Lua scripts.
#[derive(Debug, Clone)]
pub struct Memory {
    state: AdapterState,
    tokens: i64,
    refill_rate: f64,
    timestamp: i64,
    store: MemoryStore,
}

impl Memory {
    /// Construct with a private store.
    ///
    /// # Errors
    ///
    /// [`AbuseError::InvalidRefillRate`] when `refill_rate <= 0`.
    pub fn new(key: impl Into<String>, tokens: i64, refill_rate: f64) -> Result<Self, AbuseError> {
        Self::with_store(key, tokens, refill_rate, MemoryStore::new())
    }

    /// Construct sharing `store`.
    ///
    /// # Errors
    ///
    /// [`AbuseError::InvalidRefillRate`] when `refill_rate <= 0`.
    pub fn with_store(
        key: impl Into<String>,
        tokens: i64,
        refill_rate: f64,
        store: MemoryStore,
    ) -> Result<Self, AbuseError> {
        if refill_rate <= 0.0 {
            return Err(AbuseError::InvalidRefillRate);
        }
        Ok(Self {
            state: AdapterState::new(key),
            tokens,
            refill_rate,
            timestamp: unix_now(),
            store,
        })
    }

    fn ttl(&self) -> Duration {
        let secs = ((self.tokens as f64 / self.refill_rate).ceil() + 1.0).max(1.0);
        Duration::from_secs_f64(secs)
    }

    fn count(&mut self, key: &str) -> i64 {
        if self.tokens == 0 {
            return 0;
        }
        self.timestamp = unix_now();
        let now = unix_now_f64();
        let max = self.tokens as f64;
        let bucket = self.store.get_or_full(key, max, now);
        let refilled = bucket.refill(max, self.refill_rate, now);
        self.tokens - refilled.tokens.floor() as i64
    }

    /// PHP `remaining()`.
    pub fn remaining(&mut self) -> i64 {
        let key = self.state.parse_key();
        remaining_from(self.tokens, self.count(&key))
    }

    /// PHP `limit()`.
    #[must_use]
    pub fn limit(&self) -> i64 {
        self.tokens
    }

    /// PHP `time()`.
    #[must_use]
    pub fn time(&self) -> i64 {
        self.timestamp
    }
}

impl Adapter for Memory {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.tokens == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        self.timestamp = unix_now();
        let now = unix_now_f64();
        let max = self.tokens as f64;
        let bucket = self.store.get_or_full(&key, max, now);
        let (allowed, next) = bucket.consume(max, self.refill_rate, now);
        self.store.put(&key, next, self.ttl());
        Ok(!allowed)
    }

    fn set_param(&mut self, key: &str, value: &str) -> &mut Self {
        self.state.set_param(key, value);
        self
    }

    fn parse_key(&mut self) -> String {
        self.state.parse_key()
    }

    fn get_logs(&mut self, offset: Option<i64>, limit: Option<i64>) -> Result<Logs, AbuseError> {
        let now = Instant::now();
        let mut pairs: Vec<(String, serde_json::Value)> = self
            .store
            .inner
            .lock()
            .iter()
            .filter(|(_, entry)| entry.expires_at > now)
            .map(|(key, entry)| {
                (
                    format!("abuse__{key}"),
                    serde_json::json!({
                        "tokens": entry.state.tokens.to_string(),
                        "last_refill": entry.state.last_refill.to_string(),
                    }),
                )
            })
            .collect();
        pairs.sort_by(|left, right| left.0.cmp(&right.0));
        let offset = usize::try_from(offset.unwrap_or(0)).unwrap_or(0);
        let take = usize::try_from(limit.unwrap_or(25)).unwrap_or(25);
        Ok(Logs::Map(
            pairs.into_iter().skip(offset).take(take).collect(),
        ))
    }

    fn cleanup(&mut self, _timestamp: i64) -> Result<bool, AbuseError> {
        Ok(true)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        let key = self.state.parse_key();
        self.store.delete(&key);
        Ok(())
    }
}
