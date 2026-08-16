//! Cache handle matching PHP `Utopia\Cache\Cache` `load` / `save` / `purge`.
//!
//! [`MemoryCache`] is the in-crate stand-in. Production callers can pass
//! [`utopia_cache::Cache`], which implements [`CacheStore`].

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::{Duration, Instant};

/// Minimal cache used by Git adapters (PHP `Utopia\Cache\Cache`).
pub trait CacheStore: Send + Sync + std::fmt::Debug {
    /// Load a value if it was saved less than `ttl` seconds ago.
    ///
    /// PHP `Cache::load($key, $ttl)` returns the payload or `false`.
    fn load(&self, key: &str, ttl: i64) -> Option<String>;

    /// Persist a value. PHP `Cache::save($key, $data)`.
    fn save(&self, key: &str, data: &str) -> bool;

    /// Drop a key. PHP `Cache::purge($key)`.
    fn purge(&self, key: &str) -> bool;
}

/// In-memory cache used by tests and as a stand-in for `utopia-cache`.
#[derive(Debug, Default)]
pub struct MemoryCache {
    inner: Mutex<HashMap<String, (String, Instant)>>,
}

impl MemoryCache {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl CacheStore for MemoryCache {
    fn load(&self, key: &str, ttl: i64) -> Option<String> {
        let guard = self.inner.lock().ok()?;
        let (value, saved_at) = guard.get(key)?;
        if ttl >= 0 && saved_at.elapsed() > Duration::from_secs(ttl as u64) {
            return None;
        }
        Some(value.clone())
    }

    fn save(&self, key: &str, data: &str) -> bool {
        if let Ok(mut guard) = self.inner.lock() {
            guard.insert(key.to_string(), (data.to_string(), Instant::now()));
            true
        } else {
            false
        }
    }

    fn purge(&self, key: &str) -> bool {
        if let Ok(mut guard) = self.inner.lock() {
            guard.remove(key).is_some()
        } else {
            false
        }
    }
}

impl CacheStore for Box<dyn CacheStore> {
    fn load(&self, key: &str, ttl: i64) -> Option<String> {
        (**self).load(key, ttl)
    }

    fn save(&self, key: &str, data: &str) -> bool {
        (**self).save(key, data)
    }

    fn purge(&self, key: &str) -> bool {
        (**self).purge(key)
    }
}

impl CacheStore for utopia_cache::Cache {
    fn load(&self, key: &str, ttl: i64) -> Option<String> {
        match utopia_cache::Cache::load(self, key, ttl, "") {
            Ok(utopia_cache::LoadResult::Hit(value)) => match value {
                utopia_cache::CacheValue::String(text)
                | utopia_cache::CacheValue::Array(serde_json::Value::String(text)) => Some(text),
                other => Some(other.into_json().to_string()),
            },
            _ => None,
        }
    }

    fn save(&self, key: &str, data: &str) -> bool {
        matches!(
            utopia_cache::Cache::save(self, key, data.to_string(), ""),
            Ok(utopia_cache::SaveResult::Saved(_))
        )
    }

    fn purge(&self, key: &str) -> bool {
        utopia_cache::Cache::purge(self, key, "").unwrap_or(false)
    }
}
