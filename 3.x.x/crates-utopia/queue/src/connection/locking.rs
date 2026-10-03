use std::sync::Arc;

use serde_json::Value;

use super::{Connection, StoredValue};
use crate::error::QueueError;
use crate::lock::{Lock, MutexLock};

/// Wraps any [`Connection`] and serializes every command behind a single lock.
///
/// PHP `Utopia\Queue\Connection\Locking`. `ACQUIRE_TIMEOUT = -1` (wait forever).
pub struct Locking<L: Lock = MutexLock> {
    connection: Arc<dyn Connection>,
    lock: L,
}

impl<L: Lock> std::fmt::Debug for Locking<L> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Locking").finish_non_exhaustive()
    }
}

impl Locking<MutexLock> {
    pub fn new(connection: impl Connection + 'static) -> Self {
        Self::with_lock(connection, MutexLock::new())
    }
}

impl<L: Lock> Locking<L> {
    pub const ACQUIRE_TIMEOUT: f64 = -1.0;

    pub fn with_lock(connection: impl Connection + 'static, lock: L) -> Self {
        Self {
            connection: Arc::new(connection),
            lock,
        }
    }

    pub fn from_parts(connection: Arc<dyn Connection>, lock: L) -> Self {
        Self { connection, lock }
    }

    fn synchronize<R>(&self, command: impl FnOnce() -> R) -> R {
        self.lock
            .with_lock(command, Self::ACQUIRE_TIMEOUT)
            .expect("ACQUIRE_TIMEOUT=-1 waits forever")
    }
}

impl<L: Lock> Connection for Locking<L> {
    fn right_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError> {
        self.synchronize(|| self.connection.right_push_array(queue, payload))
    }

    fn right_pop_array(&self, queue: &str, timeout: i64) -> Result<Option<Value>, QueueError> {
        self.synchronize(|| self.connection.right_pop_array(queue, timeout))
    }

    fn right_pop_left_push_array(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<Value>, QueueError> {
        self.synchronize(|| {
            self.connection
                .right_pop_left_push_array(queue, destination, timeout)
        })
    }

    fn left_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError> {
        self.synchronize(|| self.connection.left_push_array(queue, payload))
    }

    fn left_pop_array(&self, queue: &str, timeout: i64) -> Result<Option<Value>, QueueError> {
        self.synchronize(|| self.connection.left_pop_array(queue, timeout))
    }

    fn right_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError> {
        self.synchronize(|| self.connection.right_push(queue, payload))
    }

    fn right_pop(&self, queue: &str, timeout: i64) -> Result<Option<String>, QueueError> {
        self.synchronize(|| self.connection.right_pop(queue, timeout))
    }

    fn right_pop_left_push(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<String>, QueueError> {
        self.synchronize(|| {
            self.connection
                .right_pop_left_push(queue, destination, timeout)
        })
    }

    fn left_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError> {
        self.synchronize(|| self.connection.left_push(queue, payload))
    }

    fn left_pop(&self, queue: &str, timeout: i64) -> Result<Option<String>, QueueError> {
        self.synchronize(|| self.connection.left_pop(queue, timeout))
    }

    fn list_remove(&self, queue: &str, key: &str) -> Result<bool, QueueError> {
        self.synchronize(|| self.connection.list_remove(queue, key))
    }

    fn list_size(&self, key: &str) -> Result<i64, QueueError> {
        self.synchronize(|| self.connection.list_size(key))
    }

    fn list_range(&self, key: &str, total: i64, offset: i64) -> Result<Vec<Value>, QueueError> {
        self.synchronize(|| self.connection.list_range(key, total, offset))
    }

    fn remove(&self, key: &str) -> Result<bool, QueueError> {
        self.synchronize(|| self.connection.remove(key))
    }

    fn set(&self, key: &str, value: &str, ttl: i64) -> Result<bool, QueueError> {
        self.synchronize(|| self.connection.set(key, value, ttl))
    }

    fn get(&self, key: &str) -> Result<Option<StoredValue>, QueueError> {
        self.synchronize(|| self.connection.get(key))
    }

    fn set_array(&self, key: &str, value: &Value, ttl: i64) -> Result<bool, QueueError> {
        self.synchronize(|| self.connection.set_array(key, value, ttl))
    }

    fn increment(&self, key: &str) -> Result<i64, QueueError> {
        self.synchronize(|| self.connection.increment(key))
    }

    fn decrement(&self, key: &str) -> Result<i64, QueueError> {
        self.synchronize(|| self.connection.decrement(key))
    }

    fn ping(&self) -> Result<bool, QueueError> {
        self.synchronize(|| self.connection.ping())
    }

    fn close(&self) {
        self.synchronize(|| self.connection.close());
    }
}
