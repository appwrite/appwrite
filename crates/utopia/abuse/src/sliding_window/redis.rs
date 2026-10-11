//! Sliding-window buckets in Redis: one string per key and window,
//! `abuse__{<key>}__<window start>`. The hash tag keeps a key's buckets in
//! one cluster slot.

use std::sync::{Arc, LazyLock};

use redis::Script;
use redis::aio::{ConnectionLike, ConnectionManager};

use super::{Rule, Store};
use crate::redis_conn::{self, Arg, Connection, Counter, Pool};
use crate::{Error, Result};

static LIMIT_CHECK: LazyLock<Script> = LazyLock::new(|| {
    Script::new(
        "local current_key = KEYS[1]
local previous_key = KEYS[2]
local max_requests = tonumber(ARGV[1])
local elapsed = tonumber(ARGV[2])
local ttl = tonumber(ARGV[3])

local previous_count = tonumber(redis.call('GET', previous_key) or '0') or 0
local current_count = tonumber(redis.call('GET', current_key) or '0') or 0

local estimated = previous_count * (1 - elapsed) + current_count

if estimated < max_requests then
  redis.call('INCR', current_key)
  redis.call('EXPIRE', current_key, ttl)
end

return math.floor(estimated)",
    )
});

/// `abuse__{<key>}__<window>`.
fn bucket_key(key: &str, window: i64) -> String {
    format!("{}__{{{key}}}__{window}", redis_conn::NAMESPACE)
}

/// The previous window's start.
fn previous(window: i64, rule: Rule) -> i64 {
    window.wrapping_sub(rule.window)
}

/// A count as PHP reads it: `is_numeric($v) ? (int) $v : 0`.
fn to_count(value: Option<Vec<u8>>) -> i64 {
    match value {
        Some(v) if php_std::value::numeric_bytes_ex(&v).is_some() => php_std::format::str_to_int(&v),
        _ => 0,
    }
}

async fn hit<C: ConnectionLike + Send>(
    conn: &mut C,
    key: &str,
    window: i64,
    elapsed: f64,
    rule: Rule,
    ttl: i64,
    cluster: bool,
) -> Result<i64> {
    let (current, previous) = (bucket_key(key, window), bucket_key(key, previous(window, rule)));
    let argv = [Arg::Int(rule.limit), Arg::Float(elapsed), Arg::Int(ttl)];
    match redis_conn::eval(conn, &LIMIT_CHECK, &[&current, &previous], &argv, cluster).await? {
        Some(redis::Value::Int(i)) => Ok(i),
        Some(redis::Value::BulkString(b)) if php_std::value::numeric_bytes_ex(&b).is_some() => {
            Ok(php_std::format::str_to_int(&b))
        }
        _ => Err(Error::Runtime("Redis script failed.".into())),
    }
}

async fn count<C: ConnectionLike + Send>(
    conn: &mut C,
    key: &str,
    window: i64,
    elapsed: f64,
    rule: Rule,
    cluster: bool,
) -> Result<i64> {
    let current = to_count(redis_conn::get(conn, &bucket_key(key, window), cluster).await?);
    let previous = to_count(redis_conn::get(conn, &bucket_key(key, previous(window, rule)), cluster).await?);
    Ok(php_std::format::dval_to_lval((current as f64 + previous as f64 * (1.0 - elapsed)).floor()))
}

async fn clear<C: ConnectionLike + Send>(
    conn: &mut C,
    key: &str,
    window: i64,
    rule: Rule,
    cluster: bool,
) -> Result<()> {
    let (current, previous) = (bucket_key(key, window), bucket_key(key, previous(window, rule)));
    redis_conn::del(conn, &[&current, &previous], cluster).await
}

/// The `ttl` rule: buckets must outlive the window after theirs.
fn validate(ttl: i64, rule: Rule) -> Result<()> {
    if i128::from(ttl) < i128::from(rule.window) * 2 {
        return Err(Error::InvalidArgument(
            "ttl must be at least twice the windowSize so the previous window bucket outlives the current window"
                .into(),
        ));
    }
    Ok(())
}

/// Buckets in one Redis server, each living `ttl` seconds.
///
/// ```no_run
/// use utopia_abuse::{Adapter, SlidingWindow, sliding_window};
///
/// # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
/// let conn = redis::Client::open("redis://127.0.0.1:6379")?.get_connection_manager().await?;
/// let limit = SlidingWindow::new("ip:{ip}", 100, 60, sliding_window::Redis::new(conn, 120))?;
/// let verdict = limit.with_param("{ip}", "203.0.113.7").check().await?;
/// println!("{} left", verdict.remaining);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Adapter\SlidingWindow\Redis;
///
/// $redis = new \Redis();
/// $redis->connect('redis', 6379);
/// $limit = new Redis('ip:{ip}', 100, 60, 120, $redis);
/// echo $limit->withParam('{ip}', '203.0.113.7')->check()->remaining, " left\n";
/// ```
///
/// PHP: `Utopia\Abuse\Adapter\SlidingWindow\Redis`; its `$ttl` is the
/// store's.
#[derive(Clone)]
pub struct Redis<C = ConnectionManager> {
    conn: C,
    ttl: i64,
}

impl<C> std::fmt::Debug for Redis<C> {
    /// Writes the bucket lifetime.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("sliding_window::Redis").field("ttl", &self.ttl).finish()
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Redis<C> {
    /// Buckets over `conn` that live `ttl` seconds, at least twice the
    /// window (checked when the limit is built).
    pub fn new(conn: C, ttl: i64) -> Self {
        Redis { conn, ttl }
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Store for Redis<C> {
    type Log = Counter;

    /// Fails with [`Error::InvalidArgument`] when `ttl` is under twice the
    /// window.
    fn validate(&self, rule: Rule) -> Result<()> {
        validate(self.ttl, rule)
    }

    /// Runs the limit script on both buckets.
    async fn hit(&self, key: &str, window: i64, elapsed: f64, rule: Rule) -> Result<i64> {
        hit(&mut self.conn.clone(), key, window, elapsed, rule, self.ttl, false).await
    }

    /// `GET` of both buckets.
    async fn count(&self, key: &str, window: i64, elapsed: f64, rule: Rule) -> Result<i64> {
        count(&mut self.conn.clone(), key, window, elapsed, rule, false).await
    }

    /// `DEL` of both buckets.
    async fn clear(&self, key: &str, window: i64, rule: Rule) -> Result<()> {
        clear(&mut self.conn.clone(), key, window, rule, false).await
    }

    /// Every bucket, sorted by key, `limit` (25) from `offset` (0).
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Counter>> {
        redis_conn::counters(&mut self.conn.clone(), offset.unwrap_or(0), limit.unwrap_or(25)).await
    }

    /// Nothing to do: buckets expire.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}

/// Buckets in a Redis Cluster, each living `ttl` seconds.
///
/// PHP: `Utopia\Abuse\Adapter\SlidingWindow\RedisCluster`.
#[cfg(feature = "redis-cluster")]
#[derive(Clone)]
pub struct RedisCluster {
    conn: redis::cluster_async::ClusterConnection,
    ttl: i64,
}

#[cfg(feature = "redis-cluster")]
impl std::fmt::Debug for RedisCluster {
    /// Writes the bucket lifetime.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("sliding_window::RedisCluster").field("ttl", &self.ttl).finish()
    }
}

#[cfg(feature = "redis-cluster")]
impl RedisCluster {
    /// Buckets over a cluster connection that live `ttl` seconds.
    pub fn new(conn: redis::cluster_async::ClusterConnection, ttl: i64) -> Self {
        RedisCluster { conn, ttl }
    }
}

#[cfg(feature = "redis-cluster")]
impl Store for RedisCluster {
    type Log = Counter;

    /// Fails with [`Error::InvalidArgument`] when `ttl` is under twice the
    /// window.
    fn validate(&self, rule: Rule) -> Result<()> {
        validate(self.ttl, rule)
    }

    /// Runs the limit script on both buckets (one slot).
    async fn hit(&self, key: &str, window: i64, elapsed: f64, rule: Rule) -> Result<i64> {
        hit(&mut self.conn.clone(), key, window, elapsed, rule, self.ttl, true).await
    }

    /// `GET` of both buckets.
    async fn count(&self, key: &str, window: i64, elapsed: f64, rule: Rule) -> Result<i64> {
        count(&mut self.conn.clone(), key, window, elapsed, rule, true).await
    }

    /// `DEL` of both buckets.
    async fn clear(&self, key: &str, window: i64, rule: Rule) -> Result<()> {
        clear(&mut self.conn.clone(), key, window, rule, true).await
    }

    /// The buckets of every primary, sorted by key, `limit` (25) from
    /// `offset` (0).
    ///
    /// PHP: stops scanning a primary once `$offset + $limit` keys are found.
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Counter>> {
        redis_conn::cluster_counters(&mut self.conn.clone(), offset.unwrap_or(0), limit.unwrap_or(25), true).await
    }

    /// Nothing to do: buckets expire.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}

/// Buckets in Redis or a Redis Cluster, through connections borrowed from a
/// [`Pool`] for each operation.
///
/// PHP: `Utopia\Abuse\Adapter\SlidingWindow\RedisPool`.
pub struct RedisPool<P> {
    pool: Arc<P>,
    ttl: i64,
}

impl<P> Clone for RedisPool<P> {
    /// Shares the pool.
    fn clone(&self) -> Self {
        RedisPool { pool: self.pool.clone(), ttl: self.ttl }
    }
}

impl<P> std::fmt::Debug for RedisPool<P> {
    /// Writes the bucket lifetime.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("sliding_window::RedisPool").field("ttl", &self.ttl).finish()
    }
}

impl<P: Pool> RedisPool<P> {
    /// Buckets over connections from `pool` that live `ttl` seconds.
    pub fn new(pool: P, ttl: i64) -> Self {
        RedisPool { pool: Arc::new(pool), ttl }
    }

    /// Buckets over connections from a shared `pool`.
    pub fn shared(pool: Arc<P>, ttl: i64) -> Self {
        RedisPool { pool, ttl }
    }
}

use crate::time_limit::with_conn;

impl<P: Pool + 'static> Store for RedisPool<P> {
    type Log = Counter;

    /// Fails with [`Error::InvalidArgument`] when `ttl` is under twice the
    /// window.
    fn validate(&self, rule: Rule) -> Result<()> {
        validate(self.ttl, rule)
    }

    /// Runs the limit script on both buckets.
    async fn hit(&self, key: &str, window: i64, elapsed: f64, rule: Rule) -> Result<i64> {
        with_conn!(self.pool, |conn, cluster| hit(conn, key, window, elapsed, rule, self.ttl, cluster).await)
    }

    /// `GET` of both buckets.
    async fn count(&self, key: &str, window: i64, elapsed: f64, rule: Rule) -> Result<i64> {
        with_conn!(self.pool, |conn, cluster| count(conn, key, window, elapsed, rule, cluster).await)
    }

    /// `DEL` of both buckets.
    async fn clear(&self, key: &str, window: i64, rule: Rule) -> Result<()> {
        with_conn!(self.pool, |conn, cluster| clear(conn, key, window, rule, cluster).await)
    }

    /// Every bucket, sorted by key, `limit` (25) from `offset` (0).
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Counter>> {
        let (offset, limit) = (offset.unwrap_or(0), limit.unwrap_or(25));
        let mut guard = self.pool.connection().await?;
        match &mut *guard {
            Connection::Redis(conn) => redis_conn::counters(conn, offset, limit).await,
            #[cfg(feature = "redis-cluster")]
            Connection::Cluster(conn) => redis_conn::cluster_counters(conn, offset, limit, false).await,
        }
    }

    /// Nothing to do: buckets expire.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}
