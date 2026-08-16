use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, Instant};

use parking_lot::Mutex;

use super::bucket_key;
use super::redis_base::{count_estimate, estimate, WindowConfig};
use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;

#[derive(Debug, Clone)]
struct Entry {
    count: i64,
    expires_at: Instant,
}

/// Shared in-memory sliding-window store.
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

    fn get(&self, key: &str) -> i64 {
        let mut map = self.inner.lock();
        match map.get(key) {
            Some(entry) if entry.expires_at > Instant::now() => entry.count,
            Some(_) => {
                map.remove(key);
                0
            }
            None => 0,
        }
    }

    fn incr(&self, key: &str, ttl: Duration) -> i64 {
        let mut map = self.inner.lock();
        let now = Instant::now();
        let entry = map.entry(key.to_owned()).or_insert(Entry {
            count: 0,
            expires_at: now + ttl,
        });
        if entry.expires_at <= now {
            entry.count = 0;
        }
        entry.count += 1;
        entry.expires_at = now + ttl;
        entry.count
    }

    fn delete(&self, keys: &[&str]) {
        let mut map = self.inner.lock();
        for key in keys {
            map.remove(*key);
        }
    }
}

/// In-memory sliding window with the same weighted estimate as the Redis Lua script.
#[derive(Debug, Clone)]
pub struct Memory {
    state: AdapterState,
    config: WindowConfig,
    timestamp: i64,
    store: MemoryStore,
}

impl Memory {
    /// Construct with a private store.
    ///
    /// # Errors
    ///
    /// Window size / TTL guards (same as Redis adapters).
    pub fn new(
        key: impl Into<String>,
        limit: i64,
        window_size: i64,
        ttl: i64,
    ) -> Result<Self, AbuseError> {
        Self::with_store(key, limit, window_size, ttl, MemoryStore::new())
    }

    /// Construct sharing `store`.
    ///
    /// # Errors
    ///
    /// Window size / TTL guards.
    pub fn with_store(
        key: impl Into<String>,
        limit: i64,
        window_size: i64,
        ttl: i64,
        store: MemoryStore,
    ) -> Result<Self, AbuseError> {
        let config = WindowConfig::init(limit, window_size, ttl)?;
        let (timestamp, _) = config.window();
        Ok(Self {
            state: AdapterState::new(key),
            config,
            timestamp,
            store,
        })
    }

    fn ttl(&self) -> Duration {
        Duration::from_secs(u64::try_from(self.config.ttl.max(0)).unwrap_or(0))
    }

    fn count(&mut self, key: &str) -> i64 {
        if self.config.limit == 0 {
            return 0;
        }
        let (window_start, elapsed) = self.config.window();
        self.timestamp = window_start;
        let current = self.store.get(&bucket_key(key, window_start));
        let previous = self
            .store
            .get(&bucket_key(key, window_start - self.config.window_size));
        count_estimate(current, previous, elapsed)
    }

    /// PHP `remaining()`.
    pub fn remaining(&mut self) -> i64 {
        let key = self.state.parse_key();
        remaining_from(self.config.limit, self.count(&key))
    }

    /// PHP `limit()`.
    #[must_use]
    pub fn limit(&self) -> i64 {
        self.config.limit
    }

    /// PHP `time()` - recomputed from the clock.
    pub fn time(&mut self) -> i64 {
        let (timestamp, _) = self.config.window();
        self.timestamp = timestamp;
        timestamp
    }
}

impl Adapter for Memory {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.config.limit == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        let (timestamp, elapsed) = self.config.window();
        self.timestamp = timestamp;
        let current_key = bucket_key(&key, timestamp);
        let previous_key = bucket_key(&key, timestamp - self.config.window_size);
        let current = self.store.get(&current_key);
        let previous = self.store.get(&previous_key);
        let estimated = estimate(current, previous, elapsed);
        if estimated >= self.config.limit as f64 {
            return Ok(true);
        }
        let _ = self.store.incr(&current_key, self.ttl());
        Ok(false)
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
            .map(|(key, entry)| (key.clone(), serde_json::Value::from(entry.count)))
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
        let (window_start, _) = self.config.window();
        self.timestamp = window_start;
        self.store.delete(&[
            &bucket_key(&key, window_start),
            &bucket_key(&key, window_start - self.config.window_size),
        ]);
        Ok(())
    }
}
