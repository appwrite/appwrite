use std::collections::HashMap;

use parking_lot::Mutex;

use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::value::{is_empty_key, unix_now, CacheValue, LoadResult, SaveResult};

struct Entry {
    time: i64,
    data: CacheValue,
}

/// PHP `Utopia\Cache\Adapter\Memory`.
#[derive(Default)]
pub struct Memory {
    store: Mutex<HashMap<String, Entry>>,
}

impl std::fmt::Debug for Memory {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Memory")
            .field("len", &self.store.lock().len())
            .finish()
    }
}

impl Memory {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Adapter for Memory {
    fn load(&self, key: &str, ttl: i64, _hash: &str) -> Result<LoadResult, CacheError> {
        if is_empty_key(key) {
            return Ok(LoadResult::Miss);
        }
        let store = self.store.lock();
        match store.get(key) {
            Some(saved) if saved.time + ttl > unix_now() => Ok(LoadResult::Hit(saved.data.clone())),
            _ => Ok(LoadResult::Miss),
        }
    }

    fn save(&self, key: &str, data: &CacheValue, _hash: &str) -> Result<SaveResult, CacheError> {
        if is_empty_key(key) || data.is_php_empty() {
            return Ok(SaveResult::Failed);
        }
        self.store.lock().insert(
            key.to_owned(),
            Entry {
                time: unix_now(),
                data: data.clone(),
            },
        );
        Ok(SaveResult::Saved(data.clone()))
    }

    fn touch(&self, key: &str, _hash: &str) -> Result<bool, CacheError> {
        if is_empty_key(key) {
            return Ok(false);
        }
        let mut store = self.store.lock();
        match store.get_mut(key) {
            Some(saved) => {
                saved.time = unix_now();
                Ok(true)
            }
            None => Ok(false),
        }
    }

    fn list(&self, _key: &str) -> Result<Vec<String>, CacheError> {
        Ok(Vec::new())
    }

    fn purge(&self, key: &str, _hash: &str) -> Result<bool, CacheError> {
        if is_empty_key(key) {
            return Ok(false);
        }
        Ok(self.store.lock().remove(key).is_some())
    }

    fn flush(&self) -> Result<bool, CacheError> {
        self.store.lock().clear();
        Ok(true)
    }

    fn ping(&self) -> bool {
        true
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        Ok(self.store.lock().len() as i64)
    }

    fn get_name(&self, _key: Option<&str>) -> String {
        "memory".into()
    }
}
