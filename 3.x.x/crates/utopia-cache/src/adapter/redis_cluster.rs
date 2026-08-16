use std::thread;
use std::time::Duration;

use parking_lot::Mutex;
use redis::cluster::ClusterClient;
use redis::cluster::ClusterConnection;

use super::redis::envelope::Envelope;
use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::feature::{clamp_retries, Retryable};
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
    "couldn't map cluster keyspace",
    "can't communicate with any node",
    "clusterdown",
    "is not covered by any node",
];

/// PHP `Utopia\Cache\Adapter\RedisCluster`.
pub struct RedisCluster {
    conn: Mutex<ClusterConnection>,
    seeds: Vec<String>,
    name: Option<String>,
    timeout: f64,
    read_timeout: f64,
    max_retries: i32,
    retry_delay: i32,
}

impl std::fmt::Debug for RedisCluster {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RedisCluster")
            .field("seeds", &self.seeds)
            .field("timeout", &self.timeout)
            .field("read_timeout", &self.read_timeout)
            .finish_non_exhaustive()
    }
}

impl RedisCluster {
    pub fn new(connection: ClusterConnection, seeds: Vec<String>) -> Self {
        Self {
            conn: Mutex::new(connection),
            seeds,
            name: None,
            timeout: 1.5,
            read_timeout: 1.5,
            max_retries: 0,
            retry_delay: 1000,
        }
    }

    pub fn connect(seeds: Vec<String>) -> Result<Self, CacheError> {
        let client = ClusterClient::new(seeds.clone())?;
        let connection = client.get_connection()?;
        Ok(Self::new(connection, seeds))
    }

    #[must_use]
    pub fn get_cluster_name(&self) -> Option<&str> {
        self.name.as_deref()
    }

    #[must_use]
    pub fn get_seeds(&self) -> &[String] {
        &self.seeds
    }

    fn reconnect(&self) -> Result<(), CacheError> {
        let client = ClusterClient::new(self.seeds.clone())?;
        *self.conn.lock() = client.get_connection()?;
        Ok(())
    }

    fn execute<T, F>(&self, mut callback: F) -> Result<T, CacheError>
    where
        F: FnMut(&mut ClusterConnection) -> Result<T, CacheError>,
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

impl Retryable for RedisCluster {
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

impl Adapter for RedisCluster {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError> {
        let hash = if is_empty_key(hash) { key } else { hash };
        let redis_string: Option<String> =
            self.execute(|conn| Ok(redis::cmd("HGET").arg(key).arg(hash).query(conn)?))?;
        Ok(redis_string.map_or(LoadResult::Miss, |raw| {
            Envelope::decode(&raw, ttl, unix_now()).map_or(LoadResult::Miss, LoadResult::Hit)
        }))
    }

    fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError> {
        if is_empty_key(key) || data.is_php_empty() {
            return Ok(SaveResult::Failed);
        }
        let hash = if is_empty_key(hash) { key } else { hash };
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
        let hash = if is_empty_key(hash) { key } else { hash };
        let redis_string: Option<String> =
            self.execute(|conn| Ok(redis::cmd("HGET").arg(key).arg(hash).query(conn)?))?;
        let Some(raw) = redis_string else {
            return Ok(false);
        };
        let Some(value) = Envelope::touch(&raw, unix_now()) else {
            return Ok(false);
        };
        Ok(self
            .execute(|conn| {
                redis::cmd("HSET")
                    .arg(key)
                    .arg(hash)
                    .arg(&value)
                    .query::<i64>(conn)
                    .map_err(CacheError::from)
            })
            .is_ok())
    }

    fn list(&self, key: &str) -> Result<Vec<String>, CacheError> {
        self.execute(|conn| Ok(redis::cmd("HKEYS").arg(key).query(conn)?))
    }

    fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        if !is_empty_key(hash) {
            return self.execute(|conn| {
                Ok(redis::cmd("HDEL").arg(key).arg(hash).query::<i64>(conn)? != 0)
            });
        }
        self.execute(|conn| Ok(redis::cmd("DEL").arg(key).query::<i64>(conn)? != 0))
    }

    fn flush(&self) -> Result<bool, CacheError> {
        self.execute(|conn| {
            redis::cmd("FLUSHDB").query::<String>(conn)?;
            Ok(true)
        })
    }

    fn ping(&self) -> bool {
        self.execute(|conn| {
            redis::cmd("PING").query::<String>(conn)?;
            Ok(true)
        })
        .unwrap_or(false)
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        self.execute(|conn| Ok(redis::cmd("DBSIZE").query::<i64>(conn).unwrap_or(0)))
    }

    fn get_name(&self, _key: Option<&str>) -> String {
        "redis-cluster".into()
    }
}

impl RedisCluster {
    pub fn with_timeouts(mut self, timeout: f64, read_timeout: f64) -> Self {
        self.timeout = timeout;
        self.read_timeout = read_timeout;
        self
    }

    pub fn with_name(mut self, name: impl Into<String>) -> Self {
        self.name = Some(name.into());
        self
    }
}
