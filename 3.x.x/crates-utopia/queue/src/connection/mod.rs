use serde_json::Value;

use crate::error::QueueError;

/// Redis-like list and string operations used by [`crate::broker::Redis`].
///
/// PHP `Utopia\Queue\Connection`. Empty pops return `None` (PHP `false`).
pub trait Connection: Send + Sync {
    fn right_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError>;
    fn right_pop_array(&self, queue: &str, timeout: i64) -> Result<Option<Value>, QueueError>;
    fn right_pop_left_push_array(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<Value>, QueueError>;
    fn left_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError>;
    fn left_pop_array(&self, queue: &str, timeout: i64) -> Result<Option<Value>, QueueError>;
    fn right_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError>;
    fn right_pop(&self, queue: &str, timeout: i64) -> Result<Option<String>, QueueError>;
    fn right_pop_left_push(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<String>, QueueError>;
    fn left_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError>;
    fn left_pop(&self, queue: &str, timeout: i64) -> Result<Option<String>, QueueError>;
    fn list_remove(&self, queue: &str, key: &str) -> Result<bool, QueueError>;
    fn list_size(&self, key: &str) -> Result<i64, QueueError>;
    fn list_range(&self, key: &str, total: i64, offset: i64) -> Result<Vec<Value>, QueueError>;
    fn remove(&self, key: &str) -> Result<bool, QueueError>;
    fn set(&self, key: &str, value: &str, ttl: i64) -> Result<bool, QueueError>;
    fn get(&self, key: &str) -> Result<Option<StoredValue>, QueueError>;
    fn set_array(&self, key: &str, value: &Value, ttl: i64) -> Result<bool, QueueError>;
    fn increment(&self, key: &str) -> Result<i64, QueueError>;
    fn decrement(&self, key: &str) -> Result<i64, QueueError>;
    fn ping(&self) -> Result<bool, QueueError>;
    fn close(&self);
}

/// Value returned by [`Connection::get`] (PHP `array|string|null`).
#[derive(Debug, Clone, PartialEq)]
pub enum StoredValue {
    String(String),
    Array(Value),
}

impl StoredValue {
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Self::String(s) => Some(s),
            Self::Array(_) => None,
        }
    }

    pub fn into_json(self) -> Option<Value> {
        match self {
            Self::Array(v) => Some(v),
            Self::String(s) => serde_json::from_str(&s).ok(),
        }
    }
}

pub mod in_memory;
pub mod locking;
#[cfg(feature = "redis")]
pub mod redis;
#[cfg(feature = "redis")]
pub mod redis_cluster;

pub use in_memory::InMemoryConnection;
pub use locking::Locking;
#[cfg(feature = "redis")]
pub use redis::Redis;
#[cfg(feature = "redis")]
pub use redis_cluster::RedisCluster;
