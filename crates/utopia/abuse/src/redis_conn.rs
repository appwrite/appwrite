//! Redis plumbing shared by the Redis stores: replies as phpredis reports
//! them, key scans, and the [`Pool`] the `RedisPool` stores borrow from.

use std::future::Future;
use std::ops::DerefMut;
use std::pin::Pin;
use std::sync::Mutex;

use redis::aio::{ConnectionLike, ConnectionManager};
use redis::{RedisError, RedisResult, Script, Value};
use tokio::sync::{Semaphore, SemaphorePermit};

use crate::{Error, Result};

/// A connection a [`Pool`] lends: to one Redis server, or to a cluster.
///
/// PHP: the `\Redis` or `\RedisCluster` a `Utopia\Pools\Pool` holds.
#[derive(Clone)]
pub enum Connection {
    /// One Redis server.
    Redis(ConnectionManager),
    /// A Redis Cluster.
    #[cfg(feature = "redis-cluster")]
    Cluster(redis::cluster_async::ClusterConnection),
}

impl std::fmt::Debug for Connection {
    /// Writes which kind of connection it is.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Connection::Redis(_) => f.write_str("Connection::Redis"),
            #[cfg(feature = "redis-cluster")]
            Connection::Cluster(_) => f.write_str("Connection::Cluster"),
        }
    }
}

/// Lends [`Connection`]s for the duration of one operation. Implement it to
/// back the `RedisPool` stores with another pool.
///
/// PHP: `Utopia\Pools\Pool`, whose `use()` the `RedisPool` adapters call.
pub trait Pool: Send + Sync {
    /// A borrowed connection, returned to the pool when dropped.
    type Guard<'a>: DerefMut<Target = Connection> + Send
    where
        Self: 'a;

    /// Borrows a connection, waiting for one when all are lent.
    fn connection(&self) -> impl Future<Output = Result<Self::Guard<'_>>> + Send;
}

/// How a [`Stack`] makes a connection.
type Factory = Box<dyn Fn() -> Pin<Box<dyn Future<Output = Result<Connection>> + Send>> + Send + Sync>;

/// A pool of up to `size` connections made on demand and reused last in,
/// first out.
///
/// ```no_run
/// use utopia_abuse::{Connection, Stack, TimeLimit, time_limit};
///
/// # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
/// let client = redis::Client::open("redis://127.0.0.1:6379")?;
/// let pool = Stack::new(2, move || {
///     let client = client.clone();
///     async move { Ok(Connection::Redis(client.get_connection_manager().await?)) }
/// });
/// let limit = TimeLimit::new("ip:{ip}", 10, 60, time_limit::RedisPool::new(pool))?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Adapter\TimeLimit\RedisPool;
/// use Utopia\Pools\Adapter\Stack;
/// use Utopia\Pools\Pool;
///
/// $pool = new Pool(new Stack(), 'abuse', 2, function (): \Redis {
///     $redis = new \Redis();
///     $redis->connect('redis', 6379);
///
///     return $redis;
/// }, timeout: 1.0);
/// $limit = new RedisPool('ip:{ip}', 10, 60, $pool);
/// ```
///
/// PHP: `Utopia\Pools\Pool` over `Utopia\Pools\Adapter\Stack`.
pub struct Stack {
    idle: Mutex<Vec<Connection>>,
    permits: Semaphore,
    make: Factory,
}

impl std::fmt::Debug for Stack {
    /// Writes how many connections are idle.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let idle = self.idle.lock().map(|i| i.len()).unwrap_or(0);
        f.debug_struct("Stack").field("idle", &idle).finish()
    }
}

impl Stack {
    /// A pool of at most `size` connections made by `make`.
    pub fn new<F, Fut>(size: usize, make: F) -> Self
    where
        F: Fn() -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Result<Connection>> + Send + 'static,
    {
        Stack { idle: Mutex::new(Vec::new()), permits: Semaphore::new(size), make: Box::new(move || Box::pin(make())) }
    }
}

/// A connection borrowed from a [`Stack`].
pub struct Lent<'a> {
    connection: Option<Connection>,
    stack: &'a Stack,
    _permit: SemaphorePermit<'a>,
}

impl std::ops::Deref for Lent<'_> {
    type Target = Connection;

    /// The borrowed connection.
    fn deref(&self) -> &Connection {
        self.connection.as_ref().expect("a lent connection is present until dropped")
    }
}

impl DerefMut for Lent<'_> {
    /// The borrowed connection.
    fn deref_mut(&mut self) -> &mut Connection {
        self.connection.as_mut().expect("a lent connection is present until dropped")
    }
}

impl Drop for Lent<'_> {
    /// Returns the connection to the stack.
    fn drop(&mut self) {
        if let Some(connection) = self.connection.take() {
            self.stack.idle.lock().unwrap_or_else(|e| e.into_inner()).push(connection);
        }
    }
}

impl Pool for Stack {
    type Guard<'a> = Lent<'a>;

    /// The connection used last, or a new one when none is idle.
    async fn connection(&self) -> Result<Lent<'_>> {
        let permit = self.permits.acquire().await.map_err(|_| Error::Exception("Pool is closed".into()))?;
        let idle = self.idle.lock().unwrap_or_else(|e| e.into_inner()).pop();
        let connection = match idle {
            Some(c) => c,
            None => (self.make)().await?,
        };
        Ok(Lent { connection: Some(connection), stack: self, _permit: permit })
    }
}

impl<P: Pool + ?Sized> Pool for std::sync::Arc<P> {
    type Guard<'a>
        = P::Guard<'a>
    where
        Self: 'a;

    /// Borrows from the shared pool.
    fn connection(&self) -> impl Future<Output = Result<Self::Guard<'_>>> + Send {
        (**self).connection()
    }
}

/// The error of a failed connection: phpredis throws `RedisException`, or
/// `RedisClusterException` for a cluster.
pub(crate) fn failure(e: RedisError, cluster: bool) -> Error {
    #[cfg(feature = "redis-cluster")]
    if cluster {
        return Error::Cluster(e);
    }
    let _ = cluster;
    Error::Redis(e)
}

/// phpredis answers `false` for an error reply and throws only when the
/// connection fails; `None` is that `false`.
pub(crate) fn reply<T>(result: RedisResult<T>, cluster: bool) -> Result<Option<T>> {
    match result {
        Ok(v) => Ok(Some(v)),
        Err(e) if e.is_io_error() || e.is_connection_refusal() || e.is_connection_dropped() || e.is_timeout() => {
            Err(failure(e, cluster))
        }
        Err(_) => Ok(None),
    }
}

/// An argument as phpredis sends it: integers in decimal, floats as PHP's
/// `(string)` prints them (`precision` 14).
pub(crate) enum Arg {
    /// An integer.
    Int(i64),
    /// A float.
    Float(f64),
}

impl redis::ToRedisArgs for Arg {
    fn write_redis_args<W: ?Sized + redis::RedisWrite>(&self, out: &mut W) {
        match self {
            Arg::Int(i) => out.write_arg(i.to_string().as_bytes()),
            Arg::Float(f) => out.write_arg(php_std::number::to_string(*f).as_bytes()),
        }
    }
}

/// Runs `script` as `$redis->eval($script, [...$keys, ...$argv], count($keys))`
/// does, through `EVALSHA` with an `EVAL` fallback. `None` for `false`: an
/// error reply or nil.
pub(crate) async fn eval<C: ConnectionLike + Send>(
    conn: &mut C,
    script: &Script,
    keys: &[&str],
    argv: &[Arg],
    cluster: bool,
) -> Result<Option<Value>> {
    let mut invocation = script.prepare_invoke();
    for key in keys {
        invocation.key(*key);
    }
    for arg in argv {
        invocation.arg(arg);
    }
    let value: Option<Value> = reply(invocation.invoke_async(conn).await, cluster)?;
    Ok(value.filter(|v| !matches!(v, Value::Nil)))
}

/// `GET key`: the value, `None` for `false` (missing, or an error reply).
pub(crate) async fn get<C: ConnectionLike + Send>(conn: &mut C, key: &str, cluster: bool) -> Result<Option<Vec<u8>>> {
    let value: Option<Option<Vec<u8>>> = reply(redis::cmd("GET").arg(key).query_async(conn).await, cluster)?;
    Ok(value.flatten())
}

/// `DEL keys...`.
pub(crate) async fn del<C: ConnectionLike + Send>(conn: &mut C, keys: &[&str], cluster: bool) -> Result<()> {
    let _: Option<Value> = reply(redis::cmd("DEL").arg(keys).query_async(conn).await, cluster)?;
    Ok(())
}

/// `MGET keys...`: each value, `None` for a missing key.
#[cfg(feature = "redis-cluster")]
pub(crate) async fn mget<C: ConnectionLike + Send>(
    conn: &mut C,
    keys: &[String],
    cluster: bool,
) -> Result<Vec<Option<Vec<u8>>>> {
    let values: Option<Vec<Option<Vec<u8>>>> = reply(redis::cmd("MGET").arg(keys).query_async(conn).await, cluster)?;
    Ok(values.unwrap_or_default())
}

/// `HGETALL key`: the fields in order, `None` for `false`.
pub(crate) async fn hgetall<C: ConnectionLike + Send>(
    conn: &mut C,
    key: &str,
    cluster: bool,
) -> Result<Option<Vec<(Vec<u8>, Vec<u8>)>>> {
    let value: Option<Value> = reply(redis::cmd("HGETALL").arg(key).query_async(conn).await, cluster)?;
    Ok(value.map(|v| {
        let mut flat = bulk(v).into_iter();
        let mut fields = Vec::new();
        while let (Some(k), Some(v)) = (flat.next(), flat.next()) {
            fields.push((k, v));
        }
        fields
    }))
}

/// The bulk strings of an array reply.
fn bulk(value: Value) -> Vec<Vec<u8>> {
    match value {
        Value::Array(items) | Value::Set(items) => items
            .into_iter()
            .filter_map(|v| match v {
                Value::BulkString(b) => Some(b),
                Value::SimpleString(s) => Some(s.into_bytes()),
                _ => None,
            })
            .collect(),
        Value::Map(pairs) => pairs.into_iter().flat_map(|(k, v)| bulk(Value::Array(vec![k, v]))).collect(),
        _ => Vec::new(),
    }
}

/// One `SCAN cursor MATCH pattern [COUNT count]` (phpredis leaves `COUNT`
/// out when it is 0): the next cursor and the keys, `None` for `false`.
pub(crate) fn scan_cmd(cursor: u64, pattern: &str, count: i64) -> redis::Cmd {
    let mut cmd = redis::cmd("SCAN");
    cmd.arg(cursor).arg("MATCH").arg(pattern);
    if count != 0 {
        cmd.arg("COUNT").arg(count);
    }
    cmd
}

/// The cursor and keys of a `SCAN` reply.
pub(crate) fn scan_reply(value: Value) -> (u64, Vec<String>) {
    match value {
        Value::Array(mut parts) if parts.len() == 2 => {
            let keys = bulk(parts.pop().unwrap_or(Value::Nil)).into_iter().map(text).collect();
            let cursor = match parts.pop() {
                Some(Value::BulkString(b)) => std::str::from_utf8(&b).ok().and_then(|s| s.parse().ok()).unwrap_or(0),
                Some(Value::Int(i)) => i as u64,
                _ => 0,
            };
            (cursor, keys)
        }
        _ => (0, Vec::new()),
    }
}

/// One `SCAN` on a connection: the next cursor and the keys, `None` when
/// phpredis would answer `false`. phpredis answers `false` for a negative
/// count without asking the server.
pub(crate) async fn scan<C: ConnectionLike + Send>(
    conn: &mut C,
    cursor: u64,
    pattern: &str,
    count: i64,
    cluster: bool,
) -> Result<Option<(u64, Vec<String>)>> {
    if count < 0 {
        return Ok(None);
    }
    let value: Option<Value> = reply(scan_cmd(cursor, pattern, count).query_async(conn).await, cluster)?;
    Ok(value.map(scan_reply))
}

/// Every key matching `pattern`, scanning 100 at a time until the cursor
/// comes back to 0.
pub(crate) async fn scan_all<C: ConnectionLike + Send>(conn: &mut C, pattern: &str) -> Result<Vec<String>> {
    let mut matches = Vec::new();
    let mut cursor = 0;
    loop {
        let Some((next, keys)) = scan(conn, cursor, pattern, 100, false).await? else { break };
        matches.extend(keys);
        cursor = next;
        if cursor == 0 {
            break;
        }
    }
    Ok(matches)
}

/// A key as text.
pub(crate) fn text(bytes: Vec<u8>) -> String {
    String::from_utf8(bytes).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned())
}

/// PHP `sort($keys)`: the engine's sort with PHP 8 comparison.
pub(crate) fn sort(keys: &mut [String]) {
    php_std::sort::zend_sort(keys, &mut |a: &String, b: &String| {
        let (a, b): (php_std::Value, php_std::Value) = (a.as_str().into(), b.as_str().into());
        a.compare(&b)
    });
}

/// PHP `array_slice($list, $offset, $length)`.
pub(crate) fn slice<T>(list: Vec<T>, offset: i64, length: Option<i64>) -> Vec<T> {
    let n = list.len() as i64;
    let start = if offset < 0 { (n + offset).max(0) } else { offset.min(n) };
    let end = match length {
        None => n,
        Some(l) if l < 0 => (n + l).max(start),
        Some(l) => start.saturating_add(l).min(n),
    };
    if start >= end {
        return Vec::new();
    }
    list.into_iter().skip(start as usize).take((end - start) as usize).collect()
}

/// The primaries of a cluster as `(host, port)`, from `CLUSTER NODES`.
///
/// PHP: `\RedisCluster::_masters()`.
#[cfg(feature = "redis-cluster")]
pub(crate) async fn masters(conn: &mut redis::cluster_async::ClusterConnection) -> Result<Vec<(String, u16)>> {
    let nodes: Option<String> = reply(redis::cmd("CLUSTER").arg("NODES").query_async(conn).await, true)?;
    let mut out = Vec::new();
    for line in nodes.unwrap_or_default().lines() {
        let fields: Vec<&str> = line.split(' ').collect();
        let (Some(address), Some(flags)) = (fields.get(1), fields.get(2)) else { continue };
        if !flags.split(',').any(|f| f == "master") || flags.contains("fail") {
            continue;
        }
        let address = address.split('@').next().unwrap_or_default();
        let Some((host, port)) = address.rsplit_once(':') else { continue };
        if let Ok(port) = port.parse() {
            out.push((host.to_owned(), port));
        }
    }
    Ok(out)
}

/// One `SCAN` on one primary of a cluster.
#[cfg(feature = "redis-cluster")]
pub(crate) async fn scan_node(
    conn: &mut redis::cluster_async::ClusterConnection,
    node: &(String, u16),
    cursor: u64,
    pattern: &str,
    count: i64,
) -> Result<Option<(u64, Vec<String>)>> {
    use redis::cluster_routing::{RoutingInfo, SingleNodeRoutingInfo};

    let routing = RoutingInfo::SingleNode(SingleNodeRoutingInfo::ByAddress { host: node.0.clone(), port: node.1 });
    let value = reply(conn.route_command(&scan_cmd(cursor, pattern, count), routing).await, true)?;
    Ok(value.map(scan_reply))
}

/// The prefix of every key the Redis stores write.
pub(crate) const NAMESPACE: &str = "abuse";

/// `abuse__*`.
pub(crate) fn pattern() -> String {
    format!("{NAMESPACE}__*")
}

/// One counter in Redis, as the logs list it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Counter {
    /// The Redis key, `abuse__<key>__<window start>`.
    pub key: String,
    /// The count as Redis holds it, `None` when the key is gone.
    ///
    /// PHP: `false`.
    pub value: Option<Vec<u8>>,
}

/// Every counter on one server, sorted by key, `limit` from `offset`, each
/// read with `GET`.
pub(crate) async fn counters<C: ConnectionLike + Send>(conn: &mut C, offset: i64, limit: i64) -> Result<Vec<Counter>> {
    let mut matches = scan_all(conn, &pattern()).await?;
    sort(&mut matches);
    let mut logs = Vec::new();
    for key in slice(matches, offset, Some(limit)) {
        let value = get(conn, &key, false).await?;
        logs.push(Counter { key, value });
    }
    Ok(logs)
}

/// The counters on every primary, sorted, `limit` from `offset`. With
/// `early`, a primary's scan stops once `offset + limit` keys are found.
#[cfg(feature = "redis-cluster")]
pub(crate) async fn cluster_counters(
    conn: &mut redis::cluster_async::ClusterConnection,
    offset: i64,
    limit: i64,
    early: bool,
) -> Result<Vec<Counter>> {
    let mut matches: Vec<String> = Vec::new();
    for node in masters(conn).await? {
        let mut cursor = 0;
        loop {
            if let Some((next, keys)) = scan_node(conn, &node, cursor, &pattern(), 100).await? {
                matches.extend(keys);
                cursor = next;
            } else {
                cursor = 0;
            }
            let enough =
                early && i128::try_from(matches.len()).unwrap_or(i128::MAX) >= i128::from(offset) + i128::from(limit);
            if cursor == 0 || enough {
                break;
            }
        }
    }
    sort(&mut matches);
    let matches = slice(matches, offset, Some(limit));
    if matches.is_empty() {
        return Ok(Vec::new());
    }
    let values = mget(conn, &matches, true).await?;
    Ok(combine(matches, values))
}

/// `array_combine($keys, $values)`.
#[cfg(feature = "redis-cluster")]
fn combine(keys: Vec<String>, values: Vec<Option<Vec<u8>>>) -> Vec<Counter> {
    keys.into_iter()
        .zip(values.into_iter().chain(std::iter::repeat(None)))
        .map(|(key, value)| Counter { key, value })
        .collect()
}
