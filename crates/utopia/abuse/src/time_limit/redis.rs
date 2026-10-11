//! Fixed-window counters in Redis: one string per key and window,
//! `abuse__<key>__<window start>`, expiring with the window.

use std::sync::{Arc, LazyLock};

use redis::Script;
use redis::aio::{ConnectionLike, ConnectionManager};

use super::{Rule, Store};
use crate::Result;
use crate::redis_conn::{self, Arg, Connection, Counter, Pool};

/// The prefix of every key the Redis stores write.
///
/// PHP: `RedisBase::NAMESPACE`.
pub const NAMESPACE: &str = redis_conn::NAMESPACE;

/// KEYS[1] window counter. ARGV[1] limit, ARGV[2] ttl seconds. Returns the
/// count before this call.
static HIT: LazyLock<Script> = LazyLock::new(|| {
    Script::new(
        "local c = tonumber(redis.call('GET', KEYS[1]) or '0') or 0
if c >= tonumber(ARGV[1]) then return c end
redis.call('INCR', KEYS[1])
redis.call('EXPIRE', KEYS[1], ARGV[2])
return c",
    )
});

/// KEYS[1] window counter. ARGV[1] value, ARGV[2] ttl seconds.
static SET: LazyLock<Script> = LazyLock::new(|| {
    Script::new(
        "redis.call('SET', KEYS[1], ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[2])
return 1",
    )
});

/// `abuse__<key>__<window>`.
fn window_key(key: &str, window: i64) -> String {
    let mut out = String::with_capacity(NAMESPACE.len() + key.len() + 24);
    out.push_str(NAMESPACE);
    out.push_str("__");
    out.push_str(key);
    out.push_str("__");
    out.push_str(itoa(window).as_str());
    out
}

fn itoa(i: i64) -> String {
    i.to_string()
}

/// A count as PHP reads it: `is_numeric($v) ? (int) $v : 0`.
fn to_count(value: &[u8]) -> i64 {
    if php_std::value::numeric_bytes_ex(value).is_some() { php_std::format::str_to_int(value) } else { 0 }
}

/// `(int)` of what the hit script returned, or the `RuntimeException` PHP
/// throws when it is not a number.
fn used(value: Option<redis::Value>) -> Result<i64> {
    match value {
        Some(redis::Value::Int(i)) => Ok(i),
        Some(redis::Value::BulkString(b)) if php_std::value::numeric_bytes_ex(&b).is_some() => {
            Ok(php_std::format::str_to_int(&b))
        }
        _ => Err(crate::Error::Runtime("Redis script failed.".into())),
    }
}

async fn hit<C: ConnectionLike + Send>(conn: &mut C, key: &str, window: i64, rule: Rule, cluster: bool) -> Result<i64> {
    let key = window_key(key, window);
    used(redis_conn::eval(conn, &HIT, &[&key], &[Arg::Int(rule.limit), Arg::Int(rule.seconds)], cluster).await?)
}

async fn count<C: ConnectionLike + Send>(conn: &mut C, key: &str, window: i64, cluster: bool) -> Result<i64> {
    Ok(redis_conn::get(conn, &window_key(key, window), cluster).await?.map_or(0, |v| to_count(&v)))
}

async fn set<C: ConnectionLike + Send>(
    conn: &mut C,
    key: &str,
    window: i64,
    value: i64,
    rule: Rule,
    cluster: bool,
) -> Result<()> {
    let key = window_key(key, window);
    redis_conn::eval(conn, &SET, &[&key], &[Arg::Int(value), Arg::Int(rule.seconds)], cluster).await?;
    Ok(())
}

fn pattern() -> String {
    redis_conn::pattern()
}

/// Counters in one Redis server.
///
/// ```no_run
/// use utopia_abuse::{Adapter, TimeLimit, time_limit};
///
/// # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
/// let conn = redis::Client::open("redis://127.0.0.1:6379")?.get_connection_manager().await?;
/// let limit = TimeLimit::new("ip:{ip}", 60, 60, time_limit::Redis::new(conn))?.with_param("{ip}", "203.0.113.7");
/// if limit.check().await?.limited {
///     println!("slow down");
/// }
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Adapter\TimeLimit\Redis;
///
/// $redis = new \Redis();
/// $redis->connect('redis', 6379);
/// $limit = new Redis('ip:{ip}', 60, 60, $redis)->withParam('{ip}', '203.0.113.7');
/// if ($limit->check()->limited) {
///     echo "slow down\n";
/// }
/// ```
///
/// PHP: `Utopia\Abuse\Adapter\TimeLimit\Redis`.
#[derive(Clone)]
pub struct Redis<C = ConnectionManager> {
    conn: C,
}

impl<C> std::fmt::Debug for Redis<C> {
    /// Writes the store's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("time_limit::Redis")
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Redis<C> {
    /// Counters over `conn`.
    pub fn new(conn: C) -> Self {
        Redis { conn }
    }
}

impl<C: ConnectionLike + Clone + Send + Sync> Store for Redis<C> {
    type Log = Counter;

    /// Runs the hit script on the window's counter.
    async fn hit(&self, key: &str, window: i64, rule: Rule) -> Result<i64> {
        hit(&mut self.conn.clone(), key, window, rule, false).await
    }

    /// `GET` of the window's counter.
    async fn count(&self, key: &str, window: i64) -> Result<i64> {
        count(&mut self.conn.clone(), key, window, false).await
    }

    /// `SET` and `EXPIRE` of the window's counter, in one script.
    async fn set(&self, key: &str, window: i64, value: i64, rule: Rule) -> Result<()> {
        set(&mut self.conn.clone(), key, window, value, rule, false).await
    }

    /// The counters one `SCAN` of `limit` keys finds, in the order Redis
    /// returns them; `offset` is ignored.
    ///
    /// PHP: this store's `getLogs()` scans once, with `COUNT $limit` (none
    /// when `$limit` is null or 0), and ignores `$offset`.
    async fn logs(&self, _offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Counter>> {
        let mut conn = self.conn.clone();
        let Some((_, keys)) = redis_conn::scan(&mut conn, 0, &pattern(), limit.unwrap_or(0), false).await? else {
            return Ok(Vec::new());
        };
        let mut logs = Vec::with_capacity(keys.len());
        for key in keys {
            let value = redis_conn::get(&mut conn, &key, false).await?;
            logs.push(Counter { key, value });
        }
        Ok(logs)
    }

    /// Nothing to do: counters expire.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}

/// Counters in a Redis Cluster.
///
/// PHP: `Utopia\Abuse\Adapter\TimeLimit\RedisCluster`.
#[cfg(feature = "redis-cluster")]
#[derive(Clone)]
pub struct RedisCluster {
    conn: redis::cluster_async::ClusterConnection,
}

#[cfg(feature = "redis-cluster")]
impl std::fmt::Debug for RedisCluster {
    /// Writes the store's name.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("time_limit::RedisCluster")
    }
}

#[cfg(feature = "redis-cluster")]
impl RedisCluster {
    /// Counters over a cluster connection.
    pub fn new(conn: redis::cluster_async::ClusterConnection) -> Self {
        RedisCluster { conn }
    }
}

#[cfg(feature = "redis-cluster")]
impl Store for RedisCluster {
    type Log = Counter;

    /// Runs the hit script on the window's counter.
    async fn hit(&self, key: &str, window: i64, rule: Rule) -> Result<i64> {
        hit(&mut self.conn.clone(), key, window, rule, true).await
    }

    /// `GET` of the window's counter.
    async fn count(&self, key: &str, window: i64) -> Result<i64> {
        count(&mut self.conn.clone(), key, window, true).await
    }

    /// `SET` and `EXPIRE` of the window's counter, in one script.
    async fn set(&self, key: &str, window: i64, value: i64, rule: Rule) -> Result<()> {
        set(&mut self.conn.clone(), key, window, value, rule, true).await
    }

    /// The counters of every primary, sorted by key, `limit` (25) from
    /// `offset` (0).
    ///
    /// PHP: stops scanning a primary once `$offset + $limit` keys are found.
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Counter>> {
        redis_conn::cluster_counters(&mut self.conn.clone(), offset.unwrap_or(0), limit.unwrap_or(25), true).await
    }

    /// Nothing to do: counters expire.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}

/// Counters in Redis or a Redis Cluster, through connections borrowed from
/// a [`Pool`] for each operation.
///
/// PHP: `Utopia\Abuse\Adapter\TimeLimit\RedisPool`.
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
        f.write_str("time_limit::RedisPool")
    }
}

impl<P: Pool> RedisPool<P> {
    /// Counters over connections from `pool`.
    pub fn new(pool: P) -> Self {
        RedisPool { pool: Arc::new(pool) }
    }

    /// Counters over connections from a shared `pool`.
    pub fn shared(pool: Arc<P>) -> Self {
        RedisPool { pool }
    }
}

/// Runs `$body` with the borrowed connection as `conn` and whether it is a
/// cluster as `cluster`.
macro_rules! with_conn {
    ($pool:expr, |$conn:ident, $cluster:ident| $body:expr) => {{
        let mut guard = $pool.connection().await?;
        match &mut *guard {
            Connection::Redis(c) => {
                let $conn = c;
                let $cluster = false;
                $body
            }
            #[cfg(feature = "redis-cluster")]
            Connection::Cluster(c) => {
                let $conn = c;
                let $cluster = true;
                $body
            }
        }
    }};
}
pub(crate) use with_conn;

impl<P: Pool + 'static> Store for RedisPool<P> {
    type Log = Counter;

    /// Runs the hit script on the window's counter.
    async fn hit(&self, key: &str, window: i64, rule: Rule) -> Result<i64> {
        with_conn!(self.pool, |conn, cluster| hit(conn, key, window, rule, cluster).await)
    }

    /// `GET` of the window's counter.
    async fn count(&self, key: &str, window: i64) -> Result<i64> {
        with_conn!(self.pool, |conn, cluster| count(conn, key, window, cluster).await)
    }

    /// `SET` and `EXPIRE` of the window's counter, in one script.
    async fn set(&self, key: &str, window: i64, value: i64, rule: Rule) -> Result<()> {
        with_conn!(self.pool, |conn, cluster| set(conn, key, window, value, rule, cluster).await)
    }

    /// Every counter, sorted by key, `limit` (25) from `offset` (0).
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<Counter>> {
        let (offset, limit) = (offset.unwrap_or(0), limit.unwrap_or(25));
        let mut guard = self.pool.connection().await?;
        match &mut *guard {
            Connection::Redis(conn) => redis_conn::counters(conn, offset, limit).await,
            #[cfg(feature = "redis-cluster")]
            Connection::Cluster(conn) => redis_conn::cluster_counters(conn, offset, limit, false).await,
        }
    }

    /// Nothing to do: counters expire.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}
