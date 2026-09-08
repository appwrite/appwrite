//! Token-bucket adapters (`Utopia\Abuse\Adapters\TokenBucket\*`).

mod memory;
mod none;
mod redis;
mod redis_base;
mod redis_cluster;
mod redis_pool;

pub use memory::{Memory, MemoryStore};
pub use none::None;
pub use redis::Redis;
pub use redis_cluster::RedisCluster;
pub use redis_pool::RedisPool;

/// Redis key namespace (`TokenBucket\RedisBase::NAMESPACE`).
pub const NAMESPACE: &str = "abuse";

pub(crate) fn bucket_key(key: &str) -> String {
    format!("{NAMESPACE}__{key}")
}
