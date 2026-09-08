//! Sliding-window adapters (`Utopia\Abuse\Adapters\SlidingWindow\*`).

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

/// Redis key namespace (`SlidingWindow\RedisBase::NAMESPACE`).
pub const NAMESPACE: &str = "abuse";

pub(crate) fn bucket_key(key: &str, timestamp: i64) -> String {
    format!("{NAMESPACE}__{{{key}}}__{timestamp}")
}
