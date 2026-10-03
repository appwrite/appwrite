//! PHP `Utopia\CircuitBreaker\Adapter`.

use std::collections::HashMap;
use std::sync::Arc;

use parking_lot::Mutex;

use crate::error::CircuitBreakerError;

mod redis;
mod table;

pub use redis::Redis;
pub use table::Table;

/// PHP `Utopia\CircuitBreaker\Adapter`.
pub trait Adapter: Send + Sync {
    fn get(&self, key: &str) -> Result<Option<CacheValue>, CircuitBreakerError>;
    fn set(&self, key: &str, value: CacheValue) -> Result<(), CircuitBreakerError>;
    fn increment(&self, key: &str, by: i32) -> Result<i32, CircuitBreakerError>;
    fn delete(&self, key: &str) -> Result<(), CircuitBreakerError>;
}

/// PHP `int|string` cache values.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum CacheValue {
    Int(i32),
    String(String),
}

impl CacheValue {
    #[must_use]
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Self::String(s) => Some(s),
            Self::Int(_) => None,
        }
    }

    #[must_use]
    pub fn as_int(&self) -> Option<i32> {
        match self {
            Self::Int(v) => Some(*v),
            Self::String(s) => s.parse().ok(),
        }
    }
}

impl From<i32> for CacheValue {
    fn from(value: i32) -> Self {
        Self::Int(value)
    }
}

impl From<String> for CacheValue {
    fn from(value: String) -> Self {
        Self::String(value)
    }
}

impl From<&str> for CacheValue {
    fn from(value: &str) -> Self {
        Self::String(value.into())
    }
}

/// In-memory adapter used by PHP unit tests (anonymous class).
#[derive(Debug, Default)]
pub struct Memory {
    values: Mutex<HashMap<String, CacheValue>>,
    pub writes: Mutex<Vec<(String, String, Option<CacheValue>)>>,
    record_writes: bool,
}

impl Memory {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    #[must_use]
    pub fn recording() -> Self {
        Self {
            record_writes: true,
            ..Self::default()
        }
    }

    #[must_use]
    pub fn with_values(values: HashMap<String, CacheValue>) -> Self {
        Self {
            values: Mutex::new(values),
            writes: Mutex::new(Vec::new()),
            record_writes: false,
        }
    }

    fn record(&self, op: &str, key: &str, value: Option<CacheValue>) {
        if self.record_writes {
            self.writes.lock().push((op.into(), key.into(), value));
        }
    }
}

impl Adapter for Memory {
    fn get(&self, key: &str) -> Result<Option<CacheValue>, CircuitBreakerError> {
        Ok(self.values.lock().get(key).cloned())
    }

    fn set(&self, key: &str, value: CacheValue) -> Result<(), CircuitBreakerError> {
        self.record("set", key, Some(value.clone()));
        self.values.lock().insert(key.into(), value);
        Ok(())
    }

    fn increment(&self, key: &str, by: i32) -> Result<i32, CircuitBreakerError> {
        self.record("increment", key, Some(CacheValue::Int(by)));
        let mut values = self.values.lock();
        let next = values.get(key).and_then(CacheValue::as_int).unwrap_or(0) + by;
        values.insert(key.into(), CacheValue::Int(next));
        Ok(next)
    }

    fn delete(&self, key: &str) -> Result<(), CircuitBreakerError> {
        self.record("delete", key, None);
        self.values.lock().remove(key);
        Ok(())
    }
}

impl Adapter for Arc<Memory> {
    fn get(&self, key: &str) -> Result<Option<CacheValue>, CircuitBreakerError> {
        (**self).get(key)
    }
    fn set(&self, key: &str, value: CacheValue) -> Result<(), CircuitBreakerError> {
        (**self).set(key, value)
    }
    fn increment(&self, key: &str, by: i32) -> Result<i32, CircuitBreakerError> {
        (**self).increment(key, by)
    }
    fn delete(&self, key: &str) -> Result<(), CircuitBreakerError> {
        (**self).delete(key)
    }
}
