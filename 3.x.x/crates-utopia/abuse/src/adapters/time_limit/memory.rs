use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, Instant};

use parking_lot::Mutex;

use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::time_util::{align_timestamp, unix_now};

#[derive(Debug, Clone)]
struct Entry {
    count: i64,
    expires_at: Instant,
}

/// Shared in-memory time-limit store (Redis/Database stand-in).
#[derive(Debug, Clone, Default)]
pub struct MemoryStore {
    inner: Arc<Mutex<HashMap<(String, i64), Entry>>>,
}

impl MemoryStore {
    /// Empty store.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    fn get(&self, key: &str, timestamp: i64) -> i64 {
        let mut map = self.inner.lock();
        match map.get(&(key.to_owned(), timestamp)) {
            Some(entry) if entry.expires_at > Instant::now() => entry.count,
            Some(_) => {
                map.remove(&(key.to_owned(), timestamp));
                0
            }
            None => 0,
        }
    }

    fn hit(&self, key: &str, timestamp: i64, ttl: Duration) -> i64 {
        let mut map = self.inner.lock();
        let now = Instant::now();
        let entry = map
            .entry((key.to_owned(), timestamp))
            .and_modify(|item| {
                if item.expires_at <= now {
                    item.count = 0;
                }
            })
            .or_insert(Entry {
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

    fn set(&self, key: &str, timestamp: i64, value: i64, ttl: Duration) {
        let mut map = self.inner.lock();
        map.insert(
            (key.to_owned(), timestamp),
            Entry {
                count: value,
                expires_at: Instant::now() + ttl,
            },
        );
    }

    fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Logs {
        let now = Instant::now();
        let mut pairs: Vec<(String, serde_json::Value)> = self
            .inner
            .lock()
            .iter()
            .filter(|(_, entry)| entry.expires_at > now)
            .map(|((key, timestamp), entry)| {
                (
                    format!("abuse__{key}__{timestamp}"),
                    serde_json::Value::from(entry.count),
                )
            })
            .collect();
        pairs.sort_by(|left, right| left.0.cmp(&right.0));
        let offset = usize::try_from(offset.unwrap_or(0)).unwrap_or(0);
        let take = usize::try_from(limit.unwrap_or(25)).unwrap_or(25);
        Logs::Map(pairs.into_iter().skip(offset).take(take).collect())
    }
}

/// In-memory time-limit adapter with the same check / remaining / reset math as Redis.
#[derive(Debug, Clone)]
pub struct Memory {
    state: AdapterState,
    limit: i64,
    timestamp: i64,
    ttl: Duration,
    count: Option<i64>,
    store: MemoryStore,
}

impl Memory {
    /// Construct an adapter with a private store.
    #[must_use]
    pub fn new(key: impl Into<String>, limit: i64, seconds: i64) -> Self {
        Self::with_store(key, limit, seconds, MemoryStore::new())
    }

    /// Construct an adapter that shares `store` (like many Redis adapters on one server).
    #[must_use]
    pub fn with_store(
        key: impl Into<String>,
        limit: i64,
        seconds: i64,
        store: MemoryStore,
    ) -> Self {
        let now = unix_now();
        Self {
            state: AdapterState::new(key),
            limit,
            timestamp: align_timestamp(now, seconds),
            ttl: Duration::from_secs(u64::try_from(seconds.max(0)).unwrap_or(0)),
            count: None,
            store,
        }
    }

    /// Shared store.
    #[must_use]
    pub fn store(&self) -> &MemoryStore {
        &self.store
    }

    fn count(&mut self, key: &str, timestamp: i64) -> i64 {
        if self.limit == 0 {
            return 0;
        }
        if let Some(count) = self.count {
            return count;
        }
        let count = self.store.get(key, timestamp);
        self.count = Some(count);
        count
    }

    fn hit(&mut self, key: &str, timestamp: i64) {
        if self.limit == 0 {
            return;
        }
        let _ = self.store.hit(key, timestamp, self.ttl);
        self.count = Some(self.count.unwrap_or(0) + 1);
    }

    fn set(&mut self, key: &str, timestamp: i64, value: i64) {
        self.store.set(key, timestamp, value, self.ttl);
        self.count = Some(value);
    }

    /// PHP `remaining()`.
    pub fn remaining(&mut self) -> i64 {
        let key = self.state.parse_key();
        let count = self.count(&key, self.timestamp);
        remaining_from(self.limit, count)
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

impl Adapter for Memory {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.limit == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        let timestamp = self.timestamp;
        if self.limit > self.count(&key, timestamp) {
            self.hit(&key, timestamp);
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
        Ok(self.store.logs(offset, limit))
    }

    fn cleanup(&mut self, _timestamp: i64) -> Result<bool, AbuseError> {
        Ok(true)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        let key = self.state.parse_key();
        self.set(&key, self.timestamp, 0);
        Ok(())
    }
}
