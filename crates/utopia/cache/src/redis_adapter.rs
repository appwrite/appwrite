//! [`Redis`]: entries as fields of Redis hashes, over a redis-rs connection.

use std::future::Future;

use indexmap::IndexMap;
use php_std::types::Value;
use redis::aio::{ConnectionLike, ConnectionManager};

use crate::adapter::{Adapter, Fields, Retry, blank, coerce, empty, field, now};
use crate::lease::{self, Transport, is_reserved};
use crate::resp::Reply;
use crate::{Codec, Envelope, Error, Json, Result};

/// Entries as fields of Redis hashes: `key` names the hash and `hash` the
/// field (the key itself by default), each value an [`Envelope`]. Supports
/// batches of fields, generation leases ([`crate::lease`]) and retries of
/// commands that failed on the connection.
///
/// Like the PHP extension it ports, a server error reply makes a command
/// return its failure value (a miss, `false`) rather than an error; only a
/// failed connection is an error.
///
/// ```no_run
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Redis};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let client = redis::Client::open("redis://localhost:6379")?;
/// let cache = Redis::new(client.get_connection_manager().await?);
/// cache.save("docs:user:1", &Value::from("Ada"), "", 0).await?;
/// assert_eq!(cache.load("docs:user:1", 60, "").await?, Some(Value::from("Ada")));
/// cache.purge("docs:user:1", "").await?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Redis;
///
/// $client = new \Redis();
/// $client->connect('redis', 6379);
/// $cache = new Redis($client);
/// $cache->save('docs:user:1', 'Ada');
/// echo $cache->load('docs:user:1', 60), "\n"; // Ada
/// $client->del('docs:user:1');
/// ```
#[derive(Clone)]
pub struct Redis<C = ConnectionManager, K = Json> {
    connection: C,
    envelope: Envelope<K>,
    retry: Retry,
    grace_ms: i64,
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static> Redis<C, Json> {
    /// Entries over `connection`, stored as JSON.
    pub fn new(connection: C) -> Self {
        Self::with_codec(connection, Json)
    }
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static, K: Codec> Redis<C, K> {
    /// Entries over `connection`, stored with `codec`.
    pub fn with_codec(connection: C, codec: K) -> Self {
        Redis { connection, envelope: Envelope::new(codec), retry: Retry::default(), grace_ms: 0 }
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

    /// Refuses leased saves for `ms` milliseconds after a purge; `0` (the
    /// default) disables the tombstone.
    ///
    /// PHP: `setLeaseGraceWindow`.
    pub fn with_lease_grace_window(mut self, ms: i64) -> Self {
        self.grace_ms = ms.max(0);
        self
    }

    /// The grace window in milliseconds.
    ///
    /// PHP: `getLeaseGraceWindow`.
    pub fn lease_grace_window(&self) -> i64 {
        self.grace_ms
    }

    /// The connection.
    pub fn connection(&self) -> &C {
        &self.connection
    }

    /// Runs a command, retrying connection failures. A server error reply is
    /// `Ok(Reply::Error)`.
    async fn execute(&self, cmd: &redis::Cmd) -> Result<Reply> {
        let mut attempts = 0;
        loop {
            let mut connection = self.connection.clone();
            match cmd.query_async::<redis::Value>(&mut connection).await {
                Ok(value) => return Ok(reply(value)),
                Err(e) if !connection_error(&e) => return Ok(Reply::Error(server_error(&e).into_bytes())),
                Err(e) => {
                    attempts += 1;
                    if attempts > self.retry.max_retries() {
                        return Err(Error::Redis(e.to_string()));
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

    async fn hset(&self, key: &str, field: &str, value: &[u8]) -> Result<Reply> {
        self.execute(redis::cmd("HSET").arg(key).arg(field).arg(value)).await
    }
}

/// Connection-level failures (retried, then raised); anything else is a
/// server reply.
fn connection_error(e: &redis::RedisError) -> bool {
    e.is_io_error() || e.is_connection_dropped() || e.is_connection_refusal() || e.is_timeout()
}

fn server_error(e: &redis::RedisError) -> String {
    match (e.code(), e.detail()) {
        (Some(code), Some(detail)) => format!("{code} {detail}"),
        (Some(code), None) => code.to_owned(),
        _ => e.to_string(),
    }
}

pub(crate) fn reply(value: redis::Value) -> Reply {
    match value {
        redis::Value::Nil => Reply::Nil,
        redis::Value::Int(i) => Reply::Int(i),
        redis::Value::BulkString(b) => Reply::Str(b),
        redis::Value::SimpleString(s) => Reply::Str(s.into_bytes()),
        redis::Value::Okay => Reply::Str(b"OK".to_vec()),
        redis::Value::Array(items) | redis::Value::Set(items) => Reply::Array(items.into_iter().map(reply).collect()),
        redis::Value::Map(pairs) => Reply::Array(pairs.into_iter().flat_map(|(k, v)| [reply(k), reply(v)]).collect()),
        redis::Value::Double(d) => Reply::Str(d.to_string().into_bytes()),
        redis::Value::Boolean(b) => Reply::Int(i64::from(b)),
        redis::Value::VerbatimString { text, .. } => Reply::Str(text.into_bytes()),
        redis::Value::Attribute { data, .. } => reply(*data),
        redis::Value::Push { data, .. } => Reply::Array(data.into_iter().map(reply).collect()),
        redis::Value::ServerError(e) => {
            Reply::Error(format!("{} {}", e.code(), e.details().unwrap_or("")).into_bytes())
        }
        other => Reply::Str(format!("{other:?}").into_bytes()),
    }
}

/// Field names of a flat `[field, value, ...]` reply, as strings.
pub(crate) fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static, K: Codec> Transport for Redis<C, K> {
    /// Runs a cached script by digest; [`Error::NoScript`] when the server
    /// has no script for `sha`.
    async fn eval_sha(&self, sha: &str, key: &str, args: &[&[u8]]) -> Result<Reply> {
        let mut cmd = redis::cmd("EVALSHA");
        cmd.arg(sha).arg(1).arg(key);
        for arg in args {
            cmd.arg(*arg);
        }
        match self.execute(&cmd).await? {
            Reply::Error(e) if crate::resp::is_noscript(&text(&e)) => Err(Error::NoScript(text(&e))),
            other => Ok(other),
        }
    }

    /// Runs a script, sending its body.
    async fn eval(&self, script: &str, key: &str, args: &[&[u8]]) -> Result<Reply> {
        let mut cmd = redis::cmd("EVAL");
        cmd.arg(script).arg(1).arg(key);
        for arg in args {
            cmd.arg(*arg);
        }
        self.execute(&cmd).await
    }

    /// The value of one field of a hash, `None` when missing.
    fn hget(&self, key: &str, field: &str) -> impl Future<Output = Result<Option<Vec<u8>>>> + Send {
        Redis::hget(self, key, field)
    }
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static, K: Codec> Adapter for Redis<C, K> {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<Value>> {
        let hash = field(key, hash);
        if is_reserved(hash) {
            return Ok(None);
        }
        Ok(match self.hget(key, hash).await? {
            Some(stored) => self.envelope.decode(&stored, ttl, now()),
            None => None,
        })
    }

    async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> Result<bool> {
        let data = coerce(data, "Utopia\\Cache\\Adapter\\Redis::save(): Argument #2 ($data)")?;
        let data = data.as_ref();
        if blank(key) || empty(data) {
            return Ok(false);
        }
        let hash = field(key, hash);
        if is_reserved(hash) {
            return Ok(false);
        }
        let Ok(value) = self.envelope.encode(data, now()) else { return Ok(false) };
        if self.hset(key, hash, &value).await.is_err() {
            return Ok(false);
        }
        if ttl > 0 && self.execute(redis::cmd("EXPIRE").arg(key).arg(ttl)).await.is_err() {
            return Ok(false);
        }
        Ok(true)
    }

    async fn touch(&self, key: &str, hash: &str) -> Result<bool> {
        let hash = field(key, hash);
        if is_reserved(hash) {
            return Ok(false);
        }
        let Some(stored) = self.hget(key, hash).await? else { return Ok(false) };
        let Some(value) = self.envelope.touch(&stored, now()) else { return Ok(false) };
        Ok(!matches!(self.hset(key, hash, &value).await?, Reply::Error(_)))
    }

    async fn list(&self, key: &str) -> Result<Vec<String>> {
        Ok(match self.execute(redis::cmd("HKEYS").arg(key)).await? {
            Reply::Array(fields) => {
                fields.into_iter().filter_map(|f| f.as_bytes().map(text)).filter(|f| !is_reserved(f)).collect()
            }
            _ => Vec::new(),
        })
    }

    fn purge(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
        lease::purge(self, self.grace_ms, key, hash)
    }

    async fn flush(&self) -> Result<bool> {
        Ok(self.execute(&redis::cmd("FLUSHDB")).await?.truthy())
    }

    async fn ping(&self) -> Result<bool> {
        let mut connection = self.connection.clone();
        Ok(match redis::cmd("PING").query_async::<redis::Value>(&mut connection).await {
            Ok(_) => true,
            Err(e) => !connection_error(&e),
        })
    }

    async fn size(&self) -> Result<i64> {
        Ok(match self.execute(&redis::cmd("DBSIZE")).await? {
            Reply::Int(size) => size,
            _ => 0,
        })
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "redis"
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
            let Some(v) = v else { continue };
            if is_reserved(&f) {
                continue;
            }
            if let Some(decoded) = self.envelope.decode(&v, ttl, now) {
                result.insert(f, decoded);
            }
        }
        Ok(result)
    }

    async fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> Result<bool> {
        let mut cmd = redis::cmd("HMSET");
        cmd.arg(key);
        let mut any = false;
        for (f, value) in data {
            if is_reserved(f) {
                continue;
            }
            cmd.arg(f).arg(self.envelope.encode(value, now())?);
            any = true;
        }
        if !any {
            return Ok(false);
        }
        if self.execute(&cmd).await.is_err() {
            return Ok(false);
        }
        if ttl > 0 && self.execute(redis::cmd("EXPIRE").arg(key).arg(ttl)).await.is_err() {
            return Ok(false);
        }
        Ok(true)
    }

    fn leasable(&self) -> bool {
        true
    }

    fn generation(&self, key: &str) -> impl Future<Output = Result<String>> + Send {
        lease::generation(self, key)
    }

    async fn save_with_lease(&self, key: &str, data: &Value, hash: &str, generation: &str) -> Result<bool> {
        lease::save_with_lease(self, &self.envelope, self.grace_ms, key, data, hash, generation).await
    }
}
