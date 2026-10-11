//! Token buckets in Redis: one hash per key, `abuse__<key>`, holding the
//! balance (`tokens`) and when it was last refilled (`last_refill`).

use std::sync::{Arc, LazyLock};

use redis::Script;
use redis::aio::{ConnectionLike, ConnectionManager};

use super::{Rule, Store};
use crate::redis_conn::{self, Arg, Connection, Pool};
use crate::{Error, Result};

static LIMIT_CHECK: LazyLock<Script> = LazyLock::new(|| {
    Script::new(
        "local key = KEYS[1]
local max_tokens = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

local data = redis.call('HMGET', key, 'tokens', 'last_refill')
local tokens = tonumber(data[1]) or max_tokens
local last_refill = tonumber(data[2]) or now

local elapsed = now - last_refill
if elapsed < 0 then elapsed = 0 end
tokens = math.min(max_tokens, tokens + elapsed * refill_rate)

local available = tokens

if tokens >= 1 then
  tokens = tokens - 1
end

redis.call('HSET', key, 'tokens', tostring(tokens), 'last_refill', tostring(now))
redis.call('EXPIRE', key, math.ceil(max_tokens / refill_rate) + 1)

return tostring(available)",
    )
});

static TOKENS: LazyLock<Script> = LazyLock::new(|| {
    Script::new(
        "local key = KEYS[1]
local max_tokens = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

local data = redis.call('HMGET', key, 'tokens', 'last_refill')
local tokens = tonumber(data[1]) or max_tokens
local last_refill = tonumber(data[2]) or now

local elapsed = now - last_refill
if elapsed < 0 then elapsed = 0 end
tokens = math.min(max_tokens, tokens + elapsed * refill_rate)

return tostring(tokens)",
    )
});

/// One bucket in Redis, as the logs list it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Bucket {
    /// The Redis key, `abuse__<key>`.
    pub key: String,
    /// The hash's fields (`tokens`, `last_refill`) and values, as Redis
    /// holds them.
    pub fields: Vec<(String, Vec<u8>)>,
}

/// `abuse__<key>`.
fn bucket_key(key: &str) -> String {
    format!("{}__{key}", redis_conn::NAMESPACE)
}

/// `(float)` of what a script returned, or the `RuntimeException` PHP
/// throws when it is not a number.
fn balance(value: Option<redis::Value>) -> Result<f64> {
    match value {
        Some(redis::Value::Int(i)) => Ok(i as f64),
        Some(redis::Value::BulkString(b)) if php_std::value::numeric_bytes_ex(&b).is_some() => {
            Ok(php_std::format::str_to_float(&b))
        }
        _ => Err(Error::Runtime("Redis script failed.".into())),
    }
}

async fn run<C: ConnectionLike + Send>(
    conn: &mut C,
    script: &Script,
    key: &str,
    now: f64,
    rule: Rule,
    cluster: bool,
) -> Result<f64> {
    let argv = [Arg::Int(rule.tokens), Arg::Float(rule.refill_rate), Arg::Float(now)];
    balance(redis_conn::eval(conn, script, &[&bucket_key(key)], &argv, cluster).await?)
}

/// Every bucket, sorted by key, `limit` from `offset`: on one server, or on
/// every primary of a cluster.
async fn buckets<C: ConnectionLike + Send>(
    conn: &mut C,
    keys: Vec<String>,
    offset: i64,
    limit: i64,
    cluster: bool,
) -> Result<Vec<Bucket>> {
    let mut matches = keys;
    redis_conn::sort(&mut matches);
    let mut logs = Vec::new();
    for key in redis_conn::slice(matches, offset, Some(limit)) {
        let Some(hash) = redis_conn::hgetall(conn, &key, cluster).await? else { continue };
        let mut fields: Vec<(String, Vec<u8>)> = Vec::with_capacity(hash.len());
        for (field, value) in hash {
            let field = redis_conn::text(field);
            match fields.iter_mut().find(|(f, _)| *f == field) {
                Some(slot) => slot.1 = value,
                None => fields.push((field, value)),
            }
        }
        logs.push(Bucket { key, fields });
    }
    Ok(logs)
}

#[cfg(feature = "redis-cluster")]
async fn cluster_keys(conn: &mut redis::cluster_async::ClusterConnection) -> Result<Vec<String>> {
    let mut matches = Vec::new();
    for node in redis_conn::masters(conn).await? {
        let mut cursor = 0;
        loop {
            let Some((next, keys)) = redis_conn::scan_node(conn, &node, cursor, &redis_conn::pattern(), 100).await?
            else {
                break;
            };
            matches.extend(keys);
            cursor = next;
            if cursor == 0 {
                break;
            }
        }
    }
    Ok(matches)
}

/// Buckets in one Redis server.
///
/// ```no_run
/// use utopia_abuse::{Adapter, TokenBucket, token_bucket};
///
/// # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
/// let conn = redis::Client::open("redis://127.0.0.1:6379")?.get_connection_manager().await?;
/// let limit = TokenBucket::new("ip:{ip}", 10, 1.0, token_bucket::Redis::new(conn))?;
/// let verdict = limit.with_param("{ip}", "203.0.113.7").check().await?;
/// println!("{} tokens left", verdict.remaining);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Adapter\TokenBucket\Redis;
///
/// $redis = new \Redis();
/// $redis->connect('redis', 6379);
/// $limit = new Redis('ip:{ip}', 10, 1.0, $redis);
/// echo $limit->withParam('{ip}', '203.0.113.7')->check()->remaining, " tokens left\n";
/// ```
///
/// PHP: `Utopia\Abuse\Adapter\TokenBucket\Redis`.
#[derive(Clone)]
pub struct Redis<C = ConnectionManager> {
    conn: C,
}

impl<C> std::fmt::Debug for Redis<C> {
    /// Writes the store's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("token_bucket::Redis")
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Redis<C> {
    /// Buckets over `conn`.
    pub fn new(conn: C) -> Self {
        Redis { conn }
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Store for Redis<C> {
    type Log = Bucket;

    /// Runs the refill-and-take script.
    async fn hit(&self, key: &str, now: f64, rule: Rule) -> Result<f64> {
        run(&mut self.conn.clone(), &LIMIT_CHECK, key, now, rule, false).await
    }

    /// Runs the refill script, which writes nothing.
    async fn count(&self, key: &str, now: f64, rule: Rule) -> Result<f64> {
        run(&mut self.conn.clone(), &TOKENS, key, now, rule, false).await
    }

    /// `DEL` of the bucket.
    async fn clear(&self, key: &str) -> Result<()> {
        redis_conn::del(&mut self.conn.clone(), &[&bucket_key(key)], false).await
    }

    /// Every bucket, sorted by key, `limit` (25) from `offset` (0).
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Bucket>> {
        let mut conn = self.conn.clone();
        let keys = redis_conn::scan_all(&mut conn, &redis_conn::pattern()).await?;
        buckets(&mut conn, keys, offset.unwrap_or(0), limit.unwrap_or(25), false).await
    }

    /// Nothing to do: buckets expire once full again.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}

/// Buckets in a Redis Cluster.
///
/// PHP: `Utopia\Abuse\Adapter\TokenBucket\RedisCluster`.
#[cfg(feature = "redis-cluster")]
#[derive(Clone)]
pub struct RedisCluster {
    conn: redis::cluster_async::ClusterConnection,
}

#[cfg(feature = "redis-cluster")]
impl std::fmt::Debug for RedisCluster {
    /// Writes the store's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("token_bucket::RedisCluster")
    }
}

#[cfg(feature = "redis-cluster")]
impl RedisCluster {
    /// Buckets over a cluster connection.
    pub fn new(conn: redis::cluster_async::ClusterConnection) -> Self {
        RedisCluster { conn }
    }
}

#[cfg(feature = "redis-cluster")]
impl Store for RedisCluster {
    type Log = Bucket;

    /// Runs the refill-and-take script.
    async fn hit(&self, key: &str, now: f64, rule: Rule) -> Result<f64> {
        run(&mut self.conn.clone(), &LIMIT_CHECK, key, now, rule, true).await
    }

    /// Runs the refill script, which writes nothing.
    async fn count(&self, key: &str, now: f64, rule: Rule) -> Result<f64> {
        run(&mut self.conn.clone(), &TOKENS, key, now, rule, true).await
    }

    /// `DEL` of the bucket.
    async fn clear(&self, key: &str) -> Result<()> {
        redis_conn::del(&mut self.conn.clone(), &[&bucket_key(key)], true).await
    }

    /// The buckets of every primary, sorted by key, `limit` (25) from
    /// `offset` (0).
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Bucket>> {
        let mut conn = self.conn.clone();
        let keys = cluster_keys(&mut conn).await?;
        buckets(&mut conn, keys, offset.unwrap_or(0), limit.unwrap_or(25), true).await
    }

    /// Nothing to do: buckets expire once full again.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}

/// Buckets in Redis or a Redis Cluster, through connections borrowed from a
/// [`Pool`] for each operation.
///
/// PHP: `Utopia\Abuse\Adapter\TokenBucket\RedisPool`.
pub struct RedisPool<P> {
    pool: Arc<P>,
}

impl<P> Clone for RedisPool<P> {
    /// Shares the pool.
    fn clone(&self) -> Self {
        RedisPool { pool: self.pool.clone() }
    }
}

impl<P> std::fmt::Debug for RedisPool<P> {
    /// Writes the store's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("token_bucket::RedisPool")
    }
}

impl<P: Pool> RedisPool<P> {
    /// Buckets over connections from `pool`.
    pub fn new(pool: P) -> Self {
        RedisPool { pool: Arc::new(pool) }
    }

    /// Buckets over connections from a shared `pool`.
    pub fn shared(pool: Arc<P>) -> Self {
        RedisPool { pool }
    }
}

use crate::time_limit::with_conn;

impl<P: Pool + 'static> Store for RedisPool<P> {
    type Log = Bucket;

    /// Runs the refill-and-take script.
    async fn hit(&self, key: &str, now: f64, rule: Rule) -> Result<f64> {
        with_conn!(self.pool, |conn, cluster| run(conn, &LIMIT_CHECK, key, now, rule, cluster).await)
    }

    /// Runs the refill script, which writes nothing.
    async fn count(&self, key: &str, now: f64, rule: Rule) -> Result<f64> {
        with_conn!(self.pool, |conn, cluster| run(conn, &TOKENS, key, now, rule, cluster).await)
    }

    /// `DEL` of the bucket.
    async fn clear(&self, key: &str) -> Result<()> {
        with_conn!(self.pool, |conn, cluster| redis_conn::del(conn, &[&bucket_key(key)], cluster).await)
    }

    /// Every bucket, sorted by key, `limit` (25) from `offset` (0).
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Bucket>> {
        let (offset, limit) = (offset.unwrap_or(0), limit.unwrap_or(25));
        let mut guard = self.pool.connection().await?;
        match &mut *guard {
            Connection::Redis(conn) => {
                let keys = redis_conn::scan_all(conn, &redis_conn::pattern()).await?;
                buckets(conn, keys, offset, limit, false).await
            }
            #[cfg(feature = "redis-cluster")]
            Connection::Cluster(conn) => {
                let keys = cluster_keys(conn).await?;
                buckets(conn, keys, offset, limit, true).await
            }
        }
    }

    /// Nothing to do: buckets expire once full again.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}
