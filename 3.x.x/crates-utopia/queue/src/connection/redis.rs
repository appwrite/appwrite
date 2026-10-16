use std::thread;
use std::time::Duration;

use parking_lot::Mutex;
use redis::{Commands, RedisError};
use serde_json::Value;

use super::{Connection, StoredValue};
use crate::error::QueueError;

const CONNECT_MAX_ATTEMPTS: u32 = 5;
const CONNECT_BACKOFF_MS: u64 = 100;
const CONNECT_MAX_BACKOFF_MS: u64 = 3_000;

/// Single-node Redis connection.
///
/// PHP `Utopia\Queue\Connection\Redis`.
pub struct Redis {
    host: String,
    port: u16,
    user: Option<String>,
    password: Option<String>,
    connect_timeout: f64,
    read_timeout: f64,
    conn: Mutex<Option<redis::Connection>>,
}

impl std::fmt::Debug for Redis {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Redis")
            .field("host", &self.host)
            .field("port", &self.port)
            .finish_non_exhaustive()
    }
}

impl Redis {
    pub fn new(host: impl Into<String>, port: u16) -> Self {
        Self {
            host: host.into(),
            port,
            user: None,
            password: None,
            connect_timeout: -1.0,
            read_timeout: -1.0,
            conn: Mutex::new(None),
        }
    }

    pub fn with_auth(mut self, user: Option<String>, password: Option<String>) -> Self {
        self.user = user;
        self.password = password;
        self
    }

    pub fn with_timeouts(mut self, connect_timeout: f64, read_timeout: f64) -> Self {
        self.connect_timeout = connect_timeout;
        self.read_timeout = read_timeout;
        self
    }

    fn url(&self) -> String {
        match (&self.user, &self.password) {
            (Some(user), Some(pass)) => {
                format!("redis://{user}:{pass}@{}:{}/", self.host, self.port)
            }
            (None, Some(pass)) => format!("redis://:{pass}@{}:{}/", self.host, self.port),
            _ => format!("redis://{}:{}/", self.host, self.port),
        }
    }

    fn connect_once(&self) -> Result<redis::Connection, RedisError> {
        let client = redis::Client::open(self.url().as_str())?;
        let conn = if self.connect_timeout >= 0.0 {
            client.get_connection_with_timeout(Duration::from_secs_f64(self.connect_timeout))?
        } else {
            client.get_connection()?
        };
        if self.read_timeout >= 0.0 {
            let _ = conn.set_read_timeout(Some(Duration::from_secs_f64(self.read_timeout)));
        }
        Ok(conn)
    }

    fn with_conn<R>(
        &self,
        f: impl FnOnce(&mut redis::Connection) -> Result<R, RedisError>,
    ) -> Result<R, QueueError> {
        {
            let mut guard = self.conn.lock();
            if let Some(conn) = guard.as_mut() {
                return f(conn).map_err(redis_err);
            }
        }
        let mut last_err: Option<RedisError> = None;
        for attempt in 1..=CONNECT_MAX_ATTEMPTS {
            match self.connect_once() {
                Ok(conn) => {
                    let mut guard = self.conn.lock();
                    *guard = Some(conn);
                    return f(guard.as_mut().expect("just inserted")).map_err(redis_err);
                }
                Err(e) => {
                    last_err = Some(e);
                    if attempt == CONNECT_MAX_ATTEMPTS {
                        break;
                    }
                    let backoff_ms = CONNECT_MAX_BACKOFF_MS
                        .min(CONNECT_BACKOFF_MS.saturating_mul(1u64 << (attempt - 1)));
                    let jitter = fastrand_u64(backoff_ms);
                    thread::sleep(Duration::from_millis(jitter));
                }
            }
        }
        Err(QueueError::redis(format!(
            "Failed to connect to Redis at {}:{} after {CONNECT_MAX_ATTEMPTS} attempts: {}",
            self.host,
            self.port,
            last_err
                .map(|e| e.to_string())
                .unwrap_or_else(|| "unknown".into()),
        )))
    }
}

impl Connection for Redis {
    fn right_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError> {
        let encoded =
            serde_json::to_string(payload).map_err(|e| QueueError::Other(e.to_string()))?;
        self.right_push(queue, &encoded)
    }

    fn right_pop_array(&self, queue: &str, timeout: i64) -> Result<Option<Value>, QueueError> {
        match self.right_pop(queue, timeout)? {
            Some(raw) => Ok(serde_json::from_str(&raw).ok()),
            None => Ok(None),
        }
    }

    fn right_pop_left_push_array(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<Value>, QueueError> {
        match self.right_pop_left_push(queue, destination, timeout)? {
            Some(raw) => Ok(serde_json::from_str(&raw).ok()),
            None => Ok(None),
        }
    }

    fn left_push_array(&self, queue: &str, payload: &Value) -> Result<bool, QueueError> {
        let encoded =
            serde_json::to_string(payload).map_err(|e| QueueError::Other(e.to_string()))?;
        self.left_push(queue, &encoded)
    }

    fn left_pop_array(&self, queue: &str, timeout: i64) -> Result<Option<Value>, QueueError> {
        self.with_conn(|conn| {
            let result: Option<(String, String)> = conn.blpop(queue, timeout as f64)?;
            Ok(result.and_then(|(_, v)| serde_json::from_str(&v).ok()))
        })
    }

    fn right_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError> {
        self.with_conn(|conn| {
            let n: i64 = conn.rpush(queue, payload)?;
            Ok(n > 0)
        })
    }

    fn right_pop(&self, queue: &str, timeout: i64) -> Result<Option<String>, QueueError> {
        self.with_conn(|conn| {
            let result: Option<(String, String)> = conn.brpop(queue, timeout as f64)?;
            Ok(result.map(|(_, v)| v))
        })
    }

    fn right_pop_left_push(
        &self,
        queue: &str,
        destination: &str,
        timeout: i64,
    ) -> Result<Option<String>, QueueError> {
        self.with_conn(|conn| {
            let result: Option<String> = conn.brpoplpush(queue, destination, timeout as f64)?;
            Ok(result)
        })
    }

    fn left_push(&self, queue: &str, payload: &str) -> Result<bool, QueueError> {
        self.with_conn(|conn| {
            let n: i64 = conn.lpush(queue, payload)?;
            Ok(n > 0)
        })
    }

    fn left_pop(&self, queue: &str, timeout: i64) -> Result<Option<String>, QueueError> {
        self.with_conn(|conn| {
            let result: Option<(String, String)> = conn.blpop(queue, timeout as f64)?;
            Ok(result.map(|(_, v)| v))
        })
    }

    fn list_remove(&self, queue: &str, key: &str) -> Result<bool, QueueError> {
        self.with_conn(|conn| {
            let n: i64 = conn.lrem(queue, 1, key)?;
            Ok(n > 0)
        })
    }

    fn list_size(&self, key: &str) -> Result<i64, QueueError> {
        self.with_conn(|conn| conn.llen(key))
    }

    fn list_range(&self, key: &str, total: i64, offset: i64) -> Result<Vec<Value>, QueueError> {
        let start = offset;
        let end = start + total - 1;
        self.with_conn(|conn| {
            let raw: Vec<String> = conn.lrange(key, start as isize, end as isize)?;
            Ok(raw
                .into_iter()
                .map(|s| serde_json::from_str(&s).unwrap_or(Value::String(s)))
                .collect())
        })
    }

    fn remove(&self, key: &str) -> Result<bool, QueueError> {
        self.with_conn(|conn| {
            let n: i64 = conn.del(key)?;
            Ok(n > 0)
        })
    }

    fn set(&self, key: &str, value: &str, ttl: i64) -> Result<bool, QueueError> {
        self.with_conn(|conn| {
            if ttl > 0 {
                conn.set_ex(key, value, ttl as u64)
            } else {
                conn.set(key, value)
            }
        })
    }

    fn get(&self, key: &str) -> Result<Option<StoredValue>, QueueError> {
        self.with_conn(|conn| {
            let raw: Option<String> = conn.get(key)?;
            Ok(raw.map(StoredValue::String))
        })
    }

    fn set_array(&self, key: &str, value: &Value, ttl: i64) -> Result<bool, QueueError> {
        let encoded = serde_json::to_string(value).map_err(|e| QueueError::Other(e.to_string()))?;
        self.set(key, &encoded, ttl)
    }

    fn increment(&self, key: &str) -> Result<i64, QueueError> {
        self.with_conn(|conn| conn.incr(key, 1i64))
    }

    fn decrement(&self, key: &str) -> Result<i64, QueueError> {
        self.with_conn(|conn| conn.decr(key, 1i64))
    }

    fn ping(&self) -> Result<bool, QueueError> {
        match self.with_conn(|conn| {
            let _: String = redis::cmd("PING").query(conn)?;
            Ok(())
        }) {
            Ok(()) => Ok(true),
            Err(_) => Ok(false),
        }
    }

    fn close(&self) {
        *self.conn.lock() = None;
    }
}

pub(crate) fn redis_err(err: RedisError) -> QueueError {
    QueueError::redis(err.to_string())
}

fn fastrand_u64(max_inclusive: u64) -> u64 {
    if max_inclusive == 0 {
        return 0;
    }
    use std::time::{SystemTime, UNIX_EPOCH};
    let nanos = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.subsec_nanos() as u64)
        .unwrap_or(1);
    nanos % (max_inclusive + 1)
}
