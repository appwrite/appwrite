use std::sync::atomic::{AtomicI32, Ordering};
use std::thread;
use std::time::Duration;

use parking_lot::Mutex;

use super::redis::envelope::Envelope;
use super::redis::leasable::{self, effective_hash, is_reserved, LeaseReply, LeaseTransport};
use super::redis::noscript::NoScript;
use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::feature::{clamp_retries, Leasable, Retryable};
use crate::value::{is_empty_key, unix_now, CacheValue, LoadResult, SaveResult};

const CONNECTION_ERRORS: &[&str] = &[
    "went away",
    "socket",
    "read error on connection",
    "connection lost",
    "timed out",
    "timeout",
    "connection refused",
    "no connection",
    "broken pipe",
];

/// PHP `Utopia\Cache\Adapter\Redis`.
pub struct Redis {
    conn: Mutex<redis::Connection>,
    client: redis::Client,
    max_retries: i32,
    retry_delay: i32,
    lease_grace_window: AtomicI32,
}

impl std::fmt::Debug for Redis {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Redis")
            .field("max_retries", &self.max_retries)
            .finish_non_exhaustive()
    }
}

impl Redis {
    /// PHP `__construct(Redis $redis)` - wrap a redis-rs `Client` (reconnects from it).
    pub fn new(client: redis::Client) -> Result<Self, CacheError> {
        let connection = client.get_connection()?;
        Ok(Self {
            conn: Mutex::new(connection),
            client,
            max_retries: 0,
            retry_delay: 1000,
            lease_grace_window: AtomicI32::new(0),
        })
    }

    /// Connect to `host:port` (db 0, no auth).
    pub fn connect(host: &str, port: u16) -> Result<Self, CacheError> {
        let url = format!("redis://{host}:{port}/");
        Self::new(redis::Client::open(url)?)
    }

    pub fn connect_url(url: &str) -> Result<Self, CacheError> {
        Self::new(redis::Client::open(url)?)
    }

    /// PHP `setLeaseGraceWindow`.
    pub fn set_lease_grace_window(&self, milliseconds: i32) -> &Self {
        self.lease_grace_window
            .store(milliseconds.max(0), Ordering::SeqCst);
        self
    }

    /// PHP `getLeaseGraceWindow`.
    #[must_use]
    pub fn get_lease_grace_window(&self) -> i32 {
        self.lease_grace_window.load(Ordering::SeqCst)
    }

    fn reconnect(&self) -> Result<(), CacheError> {
        let conn = self.client.get_connection()?;
        *self.conn.lock() = conn;
        Ok(())
    }

    fn execute<T, F>(&self, mut callback: F) -> Result<T, CacheError>
    where
        F: FnMut(&mut redis::Connection) -> Result<T, CacheError>,
    {
        let max_attempts = 1 + self.max_retries;
        let mut attempts = 0;
        loop {
            let result = {
                let mut conn = self.conn.lock();
                callback(&mut conn)
            };
            match result {
                Ok(v) => return Ok(v),
                Err(err) if is_connection_error(&err.to_string()) => {
                    attempts += 1;
                    if attempts >= max_attempts {
                        return Err(err);
                    }
                    thread::sleep(Duration::from_millis(self.retry_delay.max(0) as u64));
                    let _ = self.reconnect();
                }
                Err(err) => return Err(err),
            }
        }
    }
}

fn is_connection_error(message: &str) -> bool {
    let lower = message.to_lowercase();
    CONNECTION_ERRORS.iter().any(|n| lower.contains(n))
}

impl Retryable for Redis {
    fn set_max_retries(&mut self, max_retries: i32) -> &mut Self {
        self.max_retries = clamp_retries(max_retries);
        self
    }

    fn set_retry_delay(&mut self, retry_delay: i32) -> &mut Self {
        self.retry_delay = retry_delay;
        self
    }

    fn get_max_retries(&self) -> i32 {
        self.max_retries
    }

    fn get_retry_delay(&self) -> i32 {
        self.retry_delay
    }
}

impl LeaseTransport for Redis {
    fn lease_eval_sha(
        &self,
        sha: &str,
        key: &str,
        args: &[String],
    ) -> Result<LeaseReply, CacheError> {
        self.execute(|conn| {
            let mut cmd = redis::cmd("EVALSHA");
            cmd.arg(sha).arg(1).arg(key);
            for arg in args {
                cmd.arg(arg);
            }
            match cmd.query::<i64>(conn) {
                Ok(n) => Ok(LeaseReply::Int(n)),
                Err(err) if noscript_err(&err) => Err(CacheError::Redis(format!("NOSCRIPT {err}"))),
                Err(err) => Err(err.into()),
            }
        })
    }

    fn lease_eval(
        &self,
        script: &str,
        key: &str,
        args: &[String],
    ) -> Result<LeaseReply, CacheError> {
        self.execute(|conn| {
            let mut cmd = redis::cmd("EVAL");
            cmd.arg(script).arg(1).arg(key);
            for arg in args {
                cmd.arg(arg);
            }
            Ok(LeaseReply::Int(cmd.query::<i64>(conn)?))
        })
    }

    fn lease_hget(&self, key: &str, field: &str) -> Result<Option<String>, CacheError> {
        self.execute(|conn| Ok(redis::cmd("HGET").arg(key).arg(field).query(conn)?))
    }
}

fn noscript_err(err: &redis::RedisError) -> bool {
    err.code().is_some_and(NoScript::matches) || NoScript::matches(&err.to_string())
}

impl Adapter for Redis {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError> {
        let hash = effective_hash(key, hash);
        if is_reserved(hash) {
            return Ok(LoadResult::Miss);
        }
        let redis_string: Option<String> =
            self.execute(|conn| Ok(redis::cmd("HGET").arg(key).arg(hash).query(conn)?))?;
        let Some(raw) = redis_string else {
            return Ok(LoadResult::Miss);
        };
        Ok(Envelope::decode(&raw, ttl, unix_now()).map_or(LoadResult::Miss, LoadResult::Hit))
    }

    fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError> {
        if is_empty_key(key) || data.is_php_empty() {
            return Ok(SaveResult::Failed);
        }
        let hash = effective_hash(key, hash);
        if is_reserved(hash) {
            return Ok(SaveResult::Failed);
        }
        let value = match Envelope::encode(data, unix_now()) {
            Ok(v) => v,
            Err(_) => return Ok(SaveResult::Failed),
        };
        match self.execute(|conn| {
            redis::cmd("HSET")
                .arg(key)
                .arg(hash)
                .arg(&value)
                .query::<i64>(conn)
                .map_err(CacheError::from)
        }) {
            Ok(_) => Ok(SaveResult::Saved(data.clone())),
            Err(_) => Ok(SaveResult::Failed),
        }
    }

    fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        let hash = effective_hash(key, hash);
        if is_reserved(hash) {
            return Ok(false);
        }
        let redis_string: Option<String> =
            self.execute(|conn| Ok(redis::cmd("HGET").arg(key).arg(hash).query(conn)?))?;
        let Some(raw) = redis_string else {
            return Ok(false);
        };
        let Some(value) = Envelope::touch(&raw, unix_now()) else {
            return Ok(false);
        };
        let result = self.execute(|conn| {
            redis::cmd("HSET")
                .arg(key)
                .arg(hash)
                .arg(&value)
                .query::<i64>(conn)
                .map_err(CacheError::from)
        });
        Ok(result.is_ok())
    }

    fn list(&self, key: &str) -> Result<Vec<String>, CacheError> {
        let keys: Vec<String> = self
            .execute(|conn| Ok(redis::cmd("HKEYS").arg(key).query(conn)?))
            .unwrap_or_default();
        Ok(keys.into_iter().filter(|f| !is_reserved(f)).collect())
    }

    fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        leasable::purge(self, key, hash, self.get_lease_grace_window())
    }

    fn flush(&self) -> Result<bool, CacheError> {
        self.execute(|conn| {
            redis::cmd("FLUSHDB")
                .query::<String>(conn)
                .map(|s| s == "OK")
                .or(Ok(true))
        })
    }

    fn ping(&self) -> bool {
        let mut conn = self.conn.lock();
        redis::cmd("PING").query::<String>(&mut conn).is_ok()
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        self.execute(|conn| Ok(redis::cmd("DBSIZE").query::<i64>(conn)?))
    }

    fn get_name(&self, _key: Option<&str>) -> String {
        "redis".into()
    }

    fn as_leasable(&self) -> Option<&dyn Leasable> {
        Some(self)
    }
}

impl Leasable for Redis {
    fn get_generation(&self, key: &str) -> Result<String, CacheError> {
        leasable::get_generation(self, key)
    }

    fn save_with_lease(
        &self,
        key: &str,
        data: &CacheValue,
        hash: &str,
        generation: &str,
    ) -> Result<SaveResult, CacheError> {
        leasable::save_with_lease(
            self,
            key,
            data,
            hash,
            generation,
            self.get_lease_grace_window(),
        )
    }
}
