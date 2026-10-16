//! PHP `Utopia\Cache\Adapter` and `Utopia\Cache\Adapter\*`.

use crate::error::CacheError;
use crate::feature::{Leasable, Telemetry};
use crate::value::{CacheValue, LoadResult, SaveResult};

/// PHP `Utopia\Cache\Adapter`.
pub trait Adapter: Send + Sync {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError>;
    fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError>;
    fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError>;
    fn list(&self, key: &str) -> Result<Vec<String>, CacheError>;
    fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError>;
    fn flush(&self) -> Result<bool, CacheError>;
    fn ping(&self) -> bool;
    fn get_size(&self) -> Result<i64, CacheError>;
    fn get_name(&self, key: Option<&str>) -> String;

    fn as_leasable(&self) -> Option<&dyn Leasable> {
        Option::None
    }

    fn as_telemetry_mut(&mut self) -> Option<&mut dyn Telemetry> {
        Option::None
    }
}

mod circuit_breaker;
mod filesystem;
mod json;
mod memcached;
mod memory;
mod none;
mod pool;
pub mod redis;
#[cfg(feature = "redis")]
mod redis_adapter;
#[cfg(feature = "redis")]
mod redis_cluster;
mod sharding;

pub use circuit_breaker::CircuitBreaker;
pub use filesystem::Filesystem;
pub use json::Json;
pub use memcached::{Hazelcast, Memcached};
pub use memory::Memory;
pub use none::None;
pub use pool::{AdapterPool, MemoryPool, Pool};
pub use sharding::Sharding;

#[cfg(feature = "redis")]
pub use redis_adapter::Redis;
#[cfg(feature = "redis")]
pub use redis_cluster::RedisCluster;
