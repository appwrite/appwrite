//! PHP `Utopia\CircuitBreaker\Adapter\Redis`.

use super::{Adapter, CacheValue};
use crate::error::CircuitBreakerError;

/// Redis-compatible commands used by the adapter.
pub trait RedisCommands: Send + Sync {
    fn get(&self, key: &str) -> Result<Option<String>, String>;
    fn set(&self, key: &str, value: &str) -> Result<bool, String>;
    fn incr_by(&self, key: &str, by: i32) -> Result<i32, String>;
    fn del(&self, key: &str) -> Result<(), String>;
}

/// PHP `Utopia\CircuitBreaker\Adapter\Redis`.
pub struct Redis<R: RedisCommands> {
    redis: R,
    prefix: String,
}

impl<R: RedisCommands> std::fmt::Debug for Redis<R> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Redis")
            .field("prefix", &self.prefix)
            .finish_non_exhaustive()
    }
}

impl<R: RedisCommands> Redis<R> {
    pub fn new(redis: R, prefix: impl Into<String>) -> Self {
        Self {
            redis,
            prefix: prefix.into(),
        }
    }

    fn key(&self, key: &str) -> String {
        format!("{}{key}", self.prefix)
    }
}

impl<R: RedisCommands> Adapter for Redis<R> {
    fn get(&self, key: &str) -> Result<Option<CacheValue>, CircuitBreakerError> {
        match self.redis.get(&self.key(key)) {
            Ok(None) => Ok(None),
            Ok(Some(value)) => {
                if let Ok(int) = value.parse::<i32>() {
                    Ok(Some(CacheValue::Int(int)))
                } else {
                    Ok(Some(CacheValue::String(value)))
                }
            }
            Err(err) => Err(CircuitBreakerError::Adapter(err)),
        }
    }

    fn set(&self, key: &str, value: CacheValue) -> Result<(), CircuitBreakerError> {
        let stored = match value {
            CacheValue::Int(v) => v.to_string(),
            CacheValue::String(v) => v,
        };
        match self.redis.set(&self.key(key), &stored) {
            Ok(true) => Ok(()),
            Ok(false) => Err(CircuitBreakerError::Adapter(format!(
                "Failed to set cache key \"{key}\"."
            ))),
            Err(err) => Err(CircuitBreakerError::Adapter(err)),
        }
    }

    fn increment(&self, key: &str, by: i32) -> Result<i32, CircuitBreakerError> {
        self.redis
            .incr_by(&self.key(key), by)
            .map_err(CircuitBreakerError::Adapter)
    }

    fn delete(&self, key: &str) -> Result<(), CircuitBreakerError> {
        self.redis
            .del(&self.key(key))
            .map_err(CircuitBreakerError::Adapter)
    }
}

#[cfg(feature = "redis")]
impl RedisCommands for redis::Client {
    fn get(&self, key: &str) -> Result<Option<String>, String> {
        let mut conn = self.get_connection().map_err(|err| err.to_string())?;
        redis::cmd("GET")
            .arg(key)
            .query(&mut conn)
            .map_err(|err| err.to_string())
    }

    fn set(&self, key: &str, value: &str) -> Result<bool, String> {
        let mut conn = self.get_connection().map_err(|err| err.to_string())?;
        redis::cmd("SET")
            .arg(key)
            .arg(value)
            .query::<String>(&mut conn)
            .map(|v| v.eq_ignore_ascii_case("OK"))
            .map_err(|err| err.to_string())
    }

    fn incr_by(&self, key: &str, by: i32) -> Result<i32, String> {
        let mut conn = self.get_connection().map_err(|err| err.to_string())?;
        redis::cmd("INCRBY")
            .arg(key)
            .arg(by)
            .query(&mut conn)
            .map_err(|err| err.to_string())
    }

    fn del(&self, key: &str) -> Result<(), String> {
        let mut conn = self.get_connection().map_err(|err| err.to_string())?;
        redis::cmd("DEL")
            .arg(key)
            .query::<i32>(&mut conn)
            .map(|_| ())
            .map_err(|err| err.to_string())
    }
}
