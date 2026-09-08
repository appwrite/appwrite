//! Time-limit adapters (`Utopia\Abuse\Adapters\TimeLimit\*`).

pub mod appwrite;
mod database;
mod memory;
mod none;
mod redis;
mod redis_cluster;
mod redis_pool;

pub use database::Database;
pub use memory::{Memory, MemoryStore};
pub use none::None;
pub use redis::Redis;
pub use redis_cluster::RedisCluster;
pub use redis_pool::RedisPool;

/// Redis key namespace (`TimeLimit\Redis::NAMESPACE`).
pub const NAMESPACE: &str = "abuse";
/// Collection / table name (`TimeLimit\Database::COLLECTION`).
pub const COLLECTION: &str = "abuse";

pub(crate) fn redis_key(key: &str, timestamp: i64) -> String {
    format!("{NAMESPACE}__{key}__{timestamp}")
}
