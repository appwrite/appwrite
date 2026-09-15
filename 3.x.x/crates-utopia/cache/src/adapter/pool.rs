use std::sync::Arc;

use utopia_pools::{Pool as UtopiaPool, Recover, RecoverCall, Stack};

use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::feature::Leasable;
use crate::value::{CacheValue, LoadResult, SaveResult};

/// Local wrapper so [`Recover`] can be implemented for pooled adapters.
#[derive(Clone)]
pub struct PooledAdapter(pub Arc<dyn Adapter>);

impl std::fmt::Debug for PooledAdapter {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_tuple("PooledAdapter").finish()
    }
}

impl Recover for PooledAdapter {
    fn reset(&mut self) -> RecoverCall {
        RecoverCall::Succeeded
    }
}

impl Adapter for PooledAdapter {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError> {
        self.0.load(key, ttl, hash)
    }

    fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError> {
        self.0.save(key, data, hash)
    }

    fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        self.0.touch(key, hash)
    }

    fn list(&self, key: &str) -> Result<Vec<String>, CacheError> {
        self.0.list(key)
    }

    fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        self.0.purge(key, hash)
    }

    fn flush(&self) -> Result<bool, CacheError> {
        self.0.flush()
    }

    fn ping(&self) -> bool {
        self.0.ping()
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        self.0.get_size()
    }

    fn get_name(&self, key: Option<&str>) -> String {
        self.0.get_name(key)
    }

    fn as_leasable(&self) -> Option<&dyn Leasable> {
        self.0.as_leasable()
    }
}

/// PHP `Utopia\Pools\Pool` of cache adapters, used by unit tests and [`Pool`].
pub type AdapterPool = UtopiaPool<PooledAdapter>;

/// Builds a [`utopia_pools::Pool`] wrapping one adapter instance (Arc).
///
/// PHP tests construct `new Utopia\Pools\Pool(new Stack(), …, fn(): Adapter => …)`.
#[derive(Debug)]
pub struct MemoryPool;

impl MemoryPool {
    #[must_use]
    pub fn single(adapter: impl Adapter + 'static) -> AdapterPool {
        let shared: Arc<dyn Adapter> = Arc::new(adapter);
        UtopiaPool::new(
            Stack::new(),
            "test",
            1,
            move || PooledAdapter(Arc::clone(&shared)),
            0.0,
        )
        .expect("cache adapter pool")
    }
}

/// PHP `Utopia\Cache\Adapter\Pool`.
pub struct Pool {
    pool: AdapterPool,
}

impl std::fmt::Debug for Pool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pool").finish_non_exhaustive()
    }
}

impl Pool {
    /// PHP `__construct(Pool $pool)`. Validates the pool holds adapters.
    pub fn new(pool: AdapterPool) -> Result<Self, CacheError> {
        pool.use_sync(|adapter| adapter.get_name(None))
            .map_err(|err| CacheError::message(err.to_string()))?;
        Ok(Self { pool })
    }

    fn delegate<R>(
        &self,
        f: impl FnOnce(&PooledAdapter) -> Result<R, CacheError>,
    ) -> Result<R, CacheError> {
        match self.pool.use_sync(|adapter| f(adapter)) {
            Ok(result) => result,
            Err(err) => Err(CacheError::message(err.to_string())),
        }
    }
}

impl Adapter for Pool {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError> {
        self.delegate(|a| a.load(key, ttl, hash))
    }

    fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError> {
        self.delegate(|a| a.save(key, data, hash))
    }

    fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        self.delegate(|a| a.touch(key, hash))
    }

    fn list(&self, key: &str) -> Result<Vec<String>, CacheError> {
        self.delegate(|a| a.list(key))
    }

    fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        self.delegate(|a| a.purge(key, hash))
    }

    fn flush(&self) -> Result<bool, CacheError> {
        self.delegate(|a| a.flush())
    }

    fn ping(&self) -> bool {
        self.pool.use_sync(|a| a.ping()).unwrap_or(false)
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        self.delegate(|a| a.get_size())
    }

    fn get_name(&self, key: Option<&str>) -> String {
        self.pool
            .use_sync(|a| a.get_name(key))
            .unwrap_or_else(|_| "pool".into())
    }

    fn as_leasable(&self) -> Option<&dyn Leasable> {
        Some(self)
    }
}

impl Leasable for Pool {
    fn get_generation(&self, key: &str) -> Result<String, CacheError> {
        self.delegate(|a| match a.as_leasable() {
            Some(leasable) => leasable.get_generation(key),
            None => Ok("0".into()),
        })
    }

    fn save_with_lease(
        &self,
        key: &str,
        data: &CacheValue,
        hash: &str,
        generation: &str,
    ) -> Result<SaveResult, CacheError> {
        self.delegate(|a| match a.as_leasable() {
            Some(leasable) => leasable.save_with_lease(key, data, hash, generation),
            None => a.save(key, data, hash),
        })
    }
}
