//! [`RedisCluster`]: entries as fields of hashes in a Redis Cluster.

use indexmap::IndexMap;
use php_std::types::Value;
use redis::aio::ConnectionLike;
use redis::cluster_async::ClusterConnection;

use crate::adapter::{Adapter, Fields, Retry, blank, coerce, empty, field, now};
use crate::redis_adapter::{reply, text};
use crate::resp::Reply;
use crate::{Codec, Envelope, Error, Json, Result};

/// Entries as fields of hashes in a Redis Cluster, stored like [`crate::Redis`]
/// stores them (an [`Envelope`] per field) but without leases or reserved
/// fields. Flush, ping and size cover every primary.
///
/// ```no_run
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, RedisCluster};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let seeds = vec!["redis://127.0.0.1:7000".to_owned()];
/// let connection = redis::cluster::ClusterClient::new(seeds.clone())?.get_async_connection().await?;
/// let cache = RedisCluster::new(connection, seeds, None);
/// cache.save("user:1", &Value::from("Ada"), "", 0).await?;
/// assert_eq!(cache.load("user:1", 60, "").await?, Some(Value::from("Ada")));
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
#[derive(Clone)]
pub struct RedisCluster<C = ClusterConnection, K = Json> {
    connection: C,
    seeds: Vec<String>,
    name: Option<String>,
    envelope: Envelope<K>,
    retry: Retry,
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static> RedisCluster<C, Json> {
    /// Entries over a cluster `connection` made from `seeds`, stored as JSON.
    pub fn new(connection: C, seeds: Vec<String>, name: Option<String>) -> Self {
        Self::with_codec(connection, seeds, name, Json)
    }
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static, K: Codec> RedisCluster<C, K> {
    /// Entries over a cluster `connection`, stored with `codec`.
    pub fn with_codec(connection: C, seeds: Vec<String>, name: Option<String>, codec: K) -> Self {
        RedisCluster { connection, seeds, name, envelope: Envelope::new(codec), retry: Retry::default() }
    }

    /// Retries commands that failed on the connection up to `retries` times
    /// (clamped to `0..=10`).
    ///
    /// PHP: `setMaxRetries`.
    pub fn with_max_retries(mut self, retries: i64) -> Self {
        self.retry = self.retry.with_max_retries(retries);
        self
    }

    /// Waits `delay_ms` milliseconds between attempts.
    ///
    /// PHP: `setRetryDelay`.
    pub fn with_retry_delay(mut self, delay_ms: i64) -> Self {
        self.retry = self.retry.with_delay_ms(delay_ms);
        self
    }

    /// The retry settings.
    ///
    /// PHP: `getMaxRetries`, `getRetryDelay`.
    pub fn retry(&self) -> Retry {
        self.retry
    }

    /// The cluster's name.
    ///
    /// PHP: `getClusterName`.
    pub fn cluster_name(&self) -> Option<&str> {
        self.name.as_deref()
    }

    /// The seed nodes.
    ///
    /// PHP: `getSeeds`.
    pub fn seeds(&self) -> &[String] {
        &self.seeds
    }

    async fn execute(&self, cmd: &redis::Cmd) -> Result<Reply> {
        let mut attempts = 0;
        loop {
            let mut connection = self.connection.clone();
            match cmd.query_async::<redis::Value>(&mut connection).await {
                Ok(value) => return Ok(reply(value)),
                Err(e) if !cluster_failure(&e) => return Ok(Reply::Error(e.to_string().into_bytes())),
                Err(e) => {
                    attempts += 1;
                    if attempts > self.retry.max_retries() {
                        return Err(Error::RedisCluster(e.to_string()));
                    }
                    self.retry.pause().await;
                }
            }
        }
    }

    async fn hget(&self, key: &str, field: &str) -> Result<Option<Vec<u8>>> {
        Ok(match self.execute(redis::cmd("HGET").arg(key).arg(field)).await? {
            Reply::Str(s) => Some(s),
            _ => None,
        })
    }
}

fn cluster_failure(e: &redis::RedisError) -> bool {
    e.is_io_error() || e.is_connection_dropped() || e.is_connection_refusal() || e.is_timeout() || e.is_cluster_error()
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static, K: Codec> Adapter for RedisCluster<C, K> {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<Value>> {
        let hash = field(key, hash);
        Ok(match self.hget(key, hash).await? {
            Some(stored) => self.envelope.decode(&stored, ttl, now()),
            None => None,
        })
    }

    async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> Result<bool> {
        let data = coerce(data, "Utopia\\Cache\\Adapter\\RedisCluster::save(): Argument #2 ($data)")?;
        if blank(key) || empty(&data) {
            return Ok(false);
        }
        let hash = field(key, hash);
        let Ok(value) = self.envelope.encode(&data, now()) else { return Ok(false) };
        if self.execute(redis::cmd("HSET").arg(key).arg(hash).arg(value)).await.is_err() {
            return Ok(false);
        }
        if ttl > 0 && self.execute(redis::cmd("EXPIRE").arg(key).arg(ttl)).await.is_err() {
            return Ok(false);
        }
        Ok(true)
    }

    async fn touch(&self, key: &str, hash: &str) -> Result<bool> {
        let hash = field(key, hash);
        let Some(stored) = self.hget(key, hash).await? else { return Ok(false) };
        let Some(value) = self.envelope.touch(&stored, now()) else { return Ok(false) };
        Ok(!matches!(self.execute(redis::cmd("HSET").arg(key).arg(hash).arg(value)).await?, Reply::Error(_)))
    }

    async fn list(&self, key: &str) -> Result<Vec<String>> {
        Ok(match self.execute(redis::cmd("HKEYS").arg(key)).await? {
            Reply::Array(fields) => fields.into_iter().filter_map(|f| f.as_bytes().map(text)).collect(),
            _ => Vec::new(),
        })
    }

    async fn purge(&self, key: &str, hash: &str) -> Result<bool> {
        let cmd = if blank(hash) {
            redis::cmd("DEL").arg(key).clone()
        } else {
            redis::cmd("HDEL").arg(key).arg(hash).clone()
        };
        Ok(self.execute(&cmd).await?.truthy())
    }

    async fn flush(&self) -> Result<bool> {
        self.execute(&redis::cmd("FLUSHDB")).await?;
        Ok(true)
    }

    async fn ping(&self) -> Result<bool> {
        Ok(self.execute(&redis::cmd("PING")).await.is_ok())
    }

    async fn size(&self) -> Result<i64> {
        Ok(match self.execute(&redis::cmd("DBSIZE")).await? {
            Reply::Int(size) => size,
            _ => 0,
        })
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "redis-cluster"
    }

    fn batchable(&self) -> bool {
        true
    }

    async fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> Result<Fields> {
        let now = now();
        let mut raw: IndexMap<String, Option<Vec<u8>>> = IndexMap::new();
        if fields.is_empty() {
            if let Reply::Array(flat) = self.execute(redis::cmd("HGETALL").arg(key)).await? {
                let mut flat = flat.into_iter();
                while let (Some(f), Some(v)) = (flat.next(), flat.next()) {
                    raw.insert(f.as_bytes().map(text).unwrap_or_default(), v.as_bytes().map(<[u8]>::to_vec));
                }
            }
        } else {
            let mut cmd = redis::cmd("HMGET");
            cmd.arg(key);
            for f in fields {
                cmd.arg(*f);
            }
            if let Reply::Array(values) = self.execute(&cmd).await? {
                for (f, v) in fields.iter().zip(values) {
                    raw.insert((*f).to_owned(), v.as_bytes().map(<[u8]>::to_vec));
                }
            }
        }
        let mut result = Fields::new();
        for (f, v) in raw {
            if let Some(decoded) = v.and_then(|v| self.envelope.decode(&v, ttl, now)) {
                result.insert(f, decoded);
            }
        }
        Ok(result)
    }

    async fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> Result<bool> {
        let mut cmd = redis::cmd("HMSET");
        cmd.arg(key);
        for (f, value) in data {
            let Ok(encoded) = self.envelope.encode(value, now()) else { return Ok(false) };
            cmd.arg(f).arg(encoded);
        }
        if data.is_empty() || self.execute(&cmd).await.is_err() {
            return Ok(false);
        }
        if ttl > 0 && self.execute(redis::cmd("EXPIRE").arg(key).arg(ttl)).await.is_err() {
            return Ok(false);
        }
        Ok(true)
    }

    async fn load_bytes(&self, key: &str, hash: &str) -> Result<Option<Vec<u8>>> {
        if blank(key) {
            return Ok(None);
        }
        self.hget(key, field(key, hash)).await
    }

    async fn save_bytes(&self, key: &str, bytes: &[u8], hash: &str, ttl: i64) -> Result<bool> {
        if blank(key) {
            return Ok(false);
        }
        // A failed write is not stored, not an error. Unlike `save`, which
        // keeps PHP's answer (phpredis' `false` on an error reply is ignored),
        // an error reply (`WRONGTYPE`, `OOM`) is a failed write too.
        if !matches!(self.execute(redis::cmd("HSET").arg(key).arg(field(key, hash)).arg(bytes)).await, Ok(reply) if !matches!(reply, Reply::Error(_)))
        {
            return Ok(false);
        }
        if ttl > 0
            && !matches!(self.execute(redis::cmd("EXPIRE").arg(key).arg(ttl)).await, Ok(reply) if !matches!(reply, Reply::Error(_)))
        {
            return Ok(false);
        }
        Ok(true)
    }

    /// No leases on a cluster: a plain save, as `save_with_lease`.
    async fn save_bytes_with_lease(&self, key: &str, bytes: &[u8], hash: &str, _generation: &str) -> Result<bool> {
        self.save_bytes(key, bytes, hash, 0).await
    }
}
