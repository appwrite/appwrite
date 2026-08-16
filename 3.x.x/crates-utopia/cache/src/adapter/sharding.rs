use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::feature::Leasable;
use crate::value::{CacheValue, LoadResult, SaveResult};

/// PHP `Utopia\Cache\Adapter\Sharding`.
///
/// Shard index = PHP `crc32($key) % count` (IEEE CRC-32, unsigned).
pub struct Sharding {
    adapters: Vec<Box<dyn Adapter>>,
}

impl std::fmt::Debug for Sharding {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Sharding")
            .field("count", &self.adapters.len())
            .finish()
    }
}

impl Sharding {
    /// PHP `__construct(array $adapters)`. Throws when empty.
    pub fn new(adapters: Vec<Box<dyn Adapter>>) -> Result<Self, CacheError> {
        if adapters.is_empty() {
            return Err(CacheError::NoAdapters);
        }
        Ok(Self { adapters })
    }

    #[must_use]
    pub fn shard_index(key: &str, count: usize) -> usize {
        let hash = crc32fast::hash(key.as_bytes());
        (hash as usize) % count
    }

    fn get_adapter(&self, key: &str) -> &dyn Adapter {
        let index = Self::shard_index(key, self.adapters.len());
        &*self.adapters[index]
    }
}

impl Adapter for Sharding {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError> {
        self.get_adapter(key).load(key, ttl, hash)
    }

    fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError> {
        self.get_adapter(key).save(key, data, hash)
    }

    fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        self.get_adapter(key).touch(key, hash)
    }

    fn list(&self, key: &str) -> Result<Vec<String>, CacheError> {
        self.get_adapter(key).list(key)
    }

    fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        self.get_adapter(key).purge(key, hash)
    }

    fn flush(&self) -> Result<bool, CacheError> {
        let mut result = true;
        for adapter in &self.adapters {
            result = adapter.flush()? && result;
        }
        Ok(result)
    }

    fn ping(&self) -> bool {
        self.adapters.iter().all(|a| a.ping())
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        let mut size = 0_i64;
        for adapter in &self.adapters {
            size += adapter.get_size()?;
        }
        Ok(size)
    }

    fn get_name(&self, key: Option<&str>) -> String {
        match key {
            None => self.adapters[0].get_name(None),
            Some(k) => self.get_adapter(k).get_name(Some(k)),
        }
    }

    fn as_leasable(&self) -> Option<&dyn Leasable> {
        Some(self)
    }
}

impl Leasable for Sharding {
    fn get_generation(&self, key: &str) -> Result<String, CacheError> {
        match self.get_adapter(key).as_leasable() {
            Some(l) => l.get_generation(key),
            None => Ok("0".into()),
        }
    }

    fn save_with_lease(
        &self,
        key: &str,
        data: &CacheValue,
        hash: &str,
        generation: &str,
    ) -> Result<SaveResult, CacheError> {
        match self.get_adapter(key).as_leasable() {
            Some(l) => l.save_with_lease(key, data, hash, generation),
            None => self.get_adapter(key).save(key, data, hash),
        }
    }
}
