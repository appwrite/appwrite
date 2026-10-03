use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, Instant};

use parking_lot::Mutex;
use serde_json::Value;

/// Cache backend matching the utopia-php/cache methods used by this library
/// (`load`/`save` map onto `get`/`set`).
pub trait CacheStore: Send + Sync {
    /// Fetch `key` if present and younger than `ttl` seconds.
    fn get(&self, key: &str, ttl: u64) -> Option<Value>;
    /// Store `data` at `key`. Returns whether the write succeeded.
    fn set(&self, key: &str, data: Value) -> bool;
    /// Remove `key`. Returns whether a value was deleted.
    fn delete(&self, key: &str) -> bool;
}

/// In-memory cache adapter.
#[derive(Debug, Default)]
pub struct MemoryCache {
    inner: Mutex<HashMap<String, (Value, Instant)>>,
}

impl MemoryCache {
    /// Create an empty memory cache.
    pub fn new() -> Self {
        Self::default()
    }
}

impl CacheStore for MemoryCache {
    fn get(&self, key: &str, ttl: u64) -> Option<Value> {
        let mut map = self.inner.lock();
        let (value, stored_at) = map.get(key)?.clone();
        if stored_at.elapsed() > Duration::from_secs(ttl) {
            map.remove(key);
            return None;
        }
        Some(value)
    }

    fn set(&self, key: &str, data: Value) -> bool {
        self.inner
            .lock()
            .insert(key.to_string(), (data, Instant::now()));
        true
    }

    fn delete(&self, key: &str) -> bool {
        self.inner.lock().remove(key).is_some()
    }
}

/// No-op cache (PHP `Utopia\Cache\Adapter\None`): writes succeed, reads miss.
#[derive(Debug, Default, Clone, Copy)]
pub struct NoneCache;

impl NoneCache {
    /// Create a no-op cache.
    pub fn new() -> Self {
        Self
    }
}

impl CacheStore for NoneCache {
    fn get(&self, _key: &str, _ttl: u64) -> Option<Value> {
        None
    }

    fn set(&self, _key: &str, _data: Value) -> bool {
        true
    }

    fn delete(&self, _key: &str) -> bool {
        true
    }
}

/// Bridge to [`utopia_cache::Cache`] (PHP wraps `Utopia\Cache\Cache`).
impl CacheStore for utopia_cache::Cache {
    fn get(&self, key: &str, ttl: u64) -> Option<Value> {
        match utopia_cache::Cache::load(self, key, ttl as i64, "") {
            Ok(utopia_cache::LoadResult::Hit(value)) => Some(value.into_json()),
            _ => None,
        }
    }

    fn set(&self, key: &str, data: Value) -> bool {
        matches!(
            utopia_cache::Cache::save(self, key, utopia_cache::CacheValue::from_json(data), ""),
            Ok(utopia_cache::SaveResult::Saved(_))
        )
    }

    fn delete(&self, key: &str) -> bool {
        utopia_cache::Cache::purge(self, key, "").unwrap_or(false)
    }
}

/// Domain-key wrapper around a [`CacheStore`] (PHP `Utopia\Domains\Cache`).
///
/// Keys are stored as `domain:{id}` matching PHP `getKey()`.
#[derive(Clone)]
pub struct Cache {
    store: Arc<dyn CacheStore>,
}

impl std::fmt::Debug for Cache {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Cache").finish_non_exhaustive()
    }
}

impl Cache {
    /// Wrap any [`CacheStore`].
    pub fn new(store: impl CacheStore + 'static) -> Self {
        Self {
            store: Arc::new(store),
        }
    }

    /// Wrap an existing `Arc<dyn CacheStore>`.
    pub fn from_store(store: Arc<dyn CacheStore>) -> Self {
        Self { store }
    }

    fn key(domain: &str) -> String {
        format!("domain:{domain}")
    }

    /// PHP `load($domain, $ttl)`.
    pub fn load(&self, domain: &str, ttl: u64) -> Option<Value> {
        self.store.get(&Self::key(domain), ttl)
    }

    /// PHP `save($domain, $data)`.
    pub fn save(&self, domain: &str, data: Value) -> bool {
        self.store.set(&Self::key(domain), data)
    }

    /// PHP `purge($domain)`.
    pub fn purge(&self, domain: &str) -> bool {
        self.store.delete(&Self::key(domain))
    }
}
