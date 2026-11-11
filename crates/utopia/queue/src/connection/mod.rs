//! Redis connections the Redis broker runs on: a single server
//! ([`Redis`]), a cluster ([`RedisCluster`]), and a wrapper that serialises
//! commands from concurrent tasks ([`Locking`]).
//!
//! A [`Connection`] answers commands the way phpredis does: a command the
//! server refuses (`WRONGTYPE`, `OOM`) is not an error but a "false" reply,
//! which each method maps as PHP does (a push reports `false`, a read
//! nothing); transport failures and refused scripts are errors.

mod locking;
mod redis;
#[cfg(feature = "cluster")]
mod redis_cluster;

use std::future::Future;
use std::pin::Pin;

use php_std::json::{self, Flags};
use php_std::{Array, Value};

use crate::error::{Error, Result};

pub use self::locking::Locking;
pub use self::redis::Redis;
pub(crate) use self::redis::jitter;
#[cfg(feature = "cluster")]
pub use self::redis_cluster::RedisCluster;

/// A boxed, sendable future.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// A Redis reply.
pub type Reply = ::redis::Value;

/// A connection to Redis: the commands the Redis broker and its callers use.
///
/// Implementors provide [`Connection::query`], [`Connection::execute`],
/// [`Connection::ping`] and [`Connection::close`]; every other method is
/// built on `query`, so a wrapper that overrides those four (as
/// [`Locking`] does) covers them all.
pub trait Connection: Send + Sync {
    /// Runs one command. `Ok(None)` is phpredis' `false`: the server refused
    /// the command. `idempotent` commands may be replayed once after a
    /// transport failure; others never are.
    fn query(&self, cmd: ::redis::Cmd, idempotent: bool) -> BoxFuture<'_, Result<Option<Reply>>>;

    /// Runs a Lua script atomically. Every key it touches must be in `keys`.
    /// A script that fails, or returns an error, is an error.
    fn execute<'a>(&'a self, script: &'a str, keys: &'a [Vec<u8>], args: &'a [Vec<u8>])
    -> BoxFuture<'a, Result<Reply>>;

    /// Whether the server answers.
    fn ping(&self) -> BoxFuture<'_, bool>;

    /// Closes the connection; the next command opens a new one.
    fn close(&self) -> BoxFuture<'_, ()>;

    /// `RPUSH queue json_encode(payload)`.
    fn right_push_array<'a>(&'a self, queue: &'a [u8], payload: &'a Array) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move { push(self, "RPUSH", queue, &[json_encode(payload)]).await })
    }

    /// `RPUSH queue p1 p2 ...` in one command; `true` without a command for none.
    fn right_push_many<'a>(&'a self, queue: &'a [u8], payloads: &'a [Vec<u8>]) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move { if payloads.is_empty() { Ok(true) } else { push(self, "RPUSH", queue, payloads).await } })
    }

    /// `BRPOP queue timeout`, JSON-decoded.
    fn right_pop_array<'a>(&'a self, queue: &'a [u8], timeout: i64) -> BoxFuture<'a, Result<Option<Value>>> {
        Box::pin(async move { Ok(self.right_pop(queue, timeout).await?.and_then(|raw| json_decode(&raw))) })
    }

    /// `BRPOPLPUSH queue destination timeout`, JSON-decoded.
    fn right_pop_left_push_array<'a>(
        &'a self,
        queue: &'a [u8],
        destination: &'a [u8],
        timeout: i64,
    ) -> BoxFuture<'a, Result<Option<Value>>> {
        Box::pin(async move {
            Ok(self.right_pop_left_push(queue, destination, timeout).await?.and_then(|raw| json_decode(&raw)))
        })
    }

    /// `LPUSH queue json_encode(payload)`.
    fn left_push_array<'a>(&'a self, queue: &'a [u8], payload: &'a Array) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move { push(self, "LPUSH", queue, &[json_encode(payload)]).await })
    }

    /// `LPUSH queue p1 p2 ...` in one command; `true` without a command for none.
    fn left_push_many<'a>(&'a self, queue: &'a [u8], payloads: &'a [Vec<u8>]) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move { if payloads.is_empty() { Ok(true) } else { push(self, "LPUSH", queue, payloads).await } })
    }

    /// `BLPOP queue timeout`, JSON-decoded.
    fn left_pop_array<'a>(&'a self, queue: &'a [u8], timeout: i64) -> BoxFuture<'a, Result<Option<Value>>> {
        Box::pin(async move { Ok(self.left_pop(queue, timeout).await?.and_then(|raw| json_decode(&raw))) })
    }

    /// `RPUSH queue payload`.
    fn right_push<'a>(&'a self, queue: &'a [u8], payload: &'a [u8]) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move { push(self, "RPUSH", queue, &[payload.to_vec()]).await })
    }

    /// `BRPOP queue timeout`: the popped payload, or `None` on timeout.
    fn right_pop<'a>(&'a self, queue: &'a [u8], timeout: i64) -> BoxFuture<'a, Result<Option<Vec<u8>>>> {
        Box::pin(async move { blocking_pop(self, "BRPOP", queue, timeout).await })
    }

    /// `BLMPOP timeout 1 queue RIGHT COUNT count`: up to `count` payloads in
    /// pop order, waiting up to `timeout` seconds for the first only.
    fn right_pop_many<'a>(&'a self, queue: &'a [u8], count: i64, timeout: i64) -> BoxFuture<'a, Result<Vec<Vec<u8>>>> {
        Box::pin(async move {
            if count < 1 {
                return Ok(Vec::new());
            }
            let mut cmd = ::redis::cmd("BLMPOP");
            cmd.arg(timeout as f64).arg(1).arg(queue).arg("RIGHT").arg("COUNT").arg(count);
            Ok(match self.query(cmd, false).await? {
                Some(Reply::Array(mut parts)) if parts.len() == 2 => match parts.pop() {
                    Some(Reply::Array(items)) => items.into_iter().filter_map(bulk).collect(),
                    _ => Vec::new(),
                },
                _ => Vec::new(),
            })
        })
    }

    /// `BRPOPLPUSH queue destination timeout`.
    fn right_pop_left_push<'a>(
        &'a self,
        queue: &'a [u8],
        destination: &'a [u8],
        timeout: i64,
    ) -> BoxFuture<'a, Result<Option<Vec<u8>>>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("BRPOPLPUSH");
            cmd.arg(queue).arg(destination).arg(timeout);
            Ok(self.query(cmd, false).await?.and_then(bulk).filter(|b| truthy(b)))
        })
    }

    /// `LPUSH queue payload`.
    fn left_push<'a>(&'a self, queue: &'a [u8], payload: &'a [u8]) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move { push(self, "LPUSH", queue, &[payload.to_vec()]).await })
    }

    /// `BLPOP queue timeout`.
    fn left_pop<'a>(&'a self, queue: &'a [u8], timeout: i64) -> BoxFuture<'a, Result<Option<Vec<u8>>>> {
        Box::pin(async move { blocking_pop(self, "BLPOP", queue, timeout).await })
    }

    /// `LREM queue 1 value`: whether one was removed.
    fn list_remove<'a>(&'a self, queue: &'a [u8], value: &'a [u8]) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("LREM");
            cmd.arg(queue).arg(1).arg(value);
            Ok(int_truthy(self.query(cmd, true).await?))
        })
    }

    /// `LLEN key`.
    fn list_size<'a>(&'a self, key: &'a [u8]) -> BoxFuture<'a, Result<i64>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("LLEN");
            cmd.arg(key);
            int_reply(self.query(cmd, true).await?, "listSize")
        })
    }

    /// `LRANGE key offset offset+total-1`.
    fn list_range<'a>(&'a self, key: &'a [u8], total: i64, offset: i64) -> BoxFuture<'a, Result<Vec<Vec<u8>>>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("LRANGE");
            cmd.arg(key).arg(offset).arg(offset + total - 1);
            match self.query(cmd, true).await? {
                Some(Reply::Array(items)) => Ok(items.into_iter().filter_map(bulk).collect()),
                Some(_) => Ok(Vec::new()),
                None => Err(return_type("listRange", "array", "bool")),
            }
        })
    }

    /// `DEL key`: whether it existed.
    fn remove<'a>(&'a self, key: &'a [u8]) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("DEL");
            cmd.arg(key);
            Ok(int_truthy(self.query(cmd, true).await?))
        })
    }

    /// `SET key value`, or `SETEX key ttl value` for a positive `ttl`.
    fn set<'a>(&'a self, key: &'a [u8], value: &'a [u8], ttl: i64) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let cmd = if ttl > 0 {
                let mut c = ::redis::cmd("SETEX");
                c.arg(key).arg(ttl).arg(value);
                c
            } else {
                let mut c = ::redis::cmd("SET");
                c.arg(key).arg(value);
                c
            };
            Ok(matches!(self.query(cmd, true).await?, Some(Reply::Okay)))
        })
    }

    /// `SET key value NX [EX ttl]`: whether this caller now owns the key.
    fn set_not_exists<'a>(&'a self, key: &'a [u8], value: &'a [u8], ttl: i64) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("SET");
            cmd.arg(key).arg(value).arg("NX");
            if ttl > 0 {
                cmd.arg("EX").arg(ttl);
            }
            Ok(matches!(self.query(cmd, true).await?, Some(Reply::Okay)))
        })
    }

    /// `GET key`: `None` when missing.
    fn get<'a>(&'a self, key: &'a [u8]) -> BoxFuture<'a, Result<Option<Vec<u8>>>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("GET");
            cmd.arg(key);
            Ok(self.query(cmd, true).await?.and_then(bulk))
        })
    }

    /// `SET key json_encode(value)` (with a TTL when positive).
    fn set_array<'a>(&'a self, key: &'a [u8], value: &'a Array, ttl: i64) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let encoded = json_encode(value);
            self.set(key, &encoded, ttl).await
        })
    }

    /// `INCR key`.
    fn increment<'a>(&'a self, key: &'a [u8]) -> BoxFuture<'a, Result<i64>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("INCR");
            cmd.arg(key);
            int_reply(self.query(cmd, false).await?, "increment")
        })
    }

    /// `INCRBY key by`.
    fn increment_by<'a>(&'a self, key: &'a [u8], by: i64) -> BoxFuture<'a, Result<i64>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("INCRBY");
            cmd.arg(key).arg(by);
            int_reply(self.query(cmd, false).await?, "incrementBy")
        })
    }

    /// `DECR key`.
    fn decrement<'a>(&'a self, key: &'a [u8]) -> BoxFuture<'a, Result<i64>> {
        Box::pin(async move {
            let mut cmd = ::redis::cmd("DECR");
            cmd.arg(key);
            int_reply(self.query(cmd, false).await?, "decrement")
        })
    }
}

impl<C: Connection + ?Sized> Connection for std::sync::Arc<C> {
    fn query(&self, cmd: ::redis::Cmd, idempotent: bool) -> BoxFuture<'_, Result<Option<Reply>>> {
        (**self).query(cmd, idempotent)
    }

    fn execute<'a>(
        &'a self,
        script: &'a str,
        keys: &'a [Vec<u8>],
        args: &'a [Vec<u8>],
    ) -> BoxFuture<'a, Result<Reply>> {
        (**self).execute(script, keys, args)
    }

    fn ping(&self) -> BoxFuture<'_, bool> {
        (**self).ping()
    }

    fn close(&self) -> BoxFuture<'_, ()> {
        (**self).close()
    }
}

/// `json_encode($value)` without flags: `false` (pushed as an empty string by
/// phpredis) when the value cannot be encoded.
fn json_encode(value: &Array) -> Vec<u8> {
    json::encode(&Value::Array(value.clone()), Flags::NONE, json::DEFAULT_DEPTH)
        .map(String::into_bytes)
        .unwrap_or_default()
}

/// `json_decode($raw, true) ?? false`.
fn json_decode(raw: &[u8]) -> Option<Value> {
    json::decode(raw, Some(true), json::DEFAULT_DEPTH, Flags::NONE).ok().filter(|v| !matches!(v, Value::Null))
}

async fn push<C: Connection + ?Sized>(conn: &C, command: &str, queue: &[u8], payloads: &[Vec<u8>]) -> Result<bool> {
    let mut cmd = ::redis::cmd(command);
    cmd.arg(queue);
    for p in payloads {
        cmd.arg(p.as_slice());
    }
    Ok(int_truthy(conn.query(cmd, false).await?))
}

async fn blocking_pop<C: Connection + ?Sized>(
    conn: &C,
    command: &str,
    queue: &[u8],
    timeout: i64,
) -> Result<Option<Vec<u8>>> {
    let mut cmd = ::redis::cmd(command);
    cmd.arg(queue).arg(timeout);
    Ok(match conn.query(cmd, false).await? {
        Some(Reply::Array(mut parts)) if parts.len() == 2 => parts.pop().and_then(bulk),
        _ => None,
    })
}

/// A bulk or simple string reply's bytes.
pub(crate) fn bulk(reply: Reply) -> Option<Vec<u8>> {
    match reply {
        Reply::BulkString(b) => Some(b),
        Reply::SimpleString(s) => Some(s.into_bytes()),
        Reply::Okay => Some(b"OK".to_vec()),
        Reply::Int(i) => Some(i.to_string().into_bytes()),
        _ => None,
    }
}

/// PHP's truthiness of a string (`"0"` and `""` are false).
fn truthy(b: &[u8]) -> bool {
    !(b.is_empty() || b == b"0")
}

fn int_truthy(reply: Option<Reply>) -> bool {
    matches!(reply, Some(Reply::Int(i)) if i != 0)
}

fn return_type(method: &str, expected: &str, given: &str) -> Error {
    Error::Type(format!(
        "Utopia\\Queue\\Connection\\Redis::{method}(): Return value must be of type {expected}, {given} returned"
    ))
}

fn int_reply(reply: Option<Reply>, _method: &str) -> Result<i64> {
    match reply {
        Some(Reply::Int(i)) => Ok(i),
        // A refused command (false) is coerced to 0 by the weak-mode return type.
        _ => Ok(0),
    }
}

/// phpredis' error text for a server error reply: `CODE detail`.
pub(crate) fn server_message(e: &::redis::RedisError) -> String {
    match (e.code(), e.detail()) {
        (Some(code), Some(detail)) => format!("{code} {detail}"),
        (Some(code), None) => code.to_owned(),
        (None, Some(detail)) => detail.to_owned(),
        (None, None) => e.to_string(),
    }
}

/// Whether phpredis raises an error reply as an exception rather than
/// returning `false`: everything but `ERR` (except `ERR AUTH`), `NOSCRIPT`,
/// `NOQUORUM`, `NOGOODSLAVE`, `WRONGTYPE`, `BUSYGROUP` and `NOGROUP`, at any
/// depth of the reply.
pub(crate) fn raises(message: &str) -> bool {
    let starts = |p: &str| message.starts_with(p);
    if starts("ERR") && !starts("ERR AUTH") {
        return false;
    }
    !["NOSCRIPT", "NOQUORUM", "NOGOODSLAVE", "WRONGTYPE", "BUSYGROUP", "NOGROUP"].iter().any(|p| starts(p))
}

/// The first error inside a reply that phpredis would raise.
pub(crate) fn raised(reply: &Reply) -> Option<String> {
    match reply {
        Reply::ServerError(e) => {
            let message = match e.details() {
                Some(d) => format!("{} {d}", e.code()),
                None => e.code().to_owned(),
            };
            raises(&message).then_some(message)
        }
        Reply::Array(items) => items.iter().find_map(raised),
        _ => None,
    }
}

/// Sends a command and returns the reply as the server sent it: an error
/// nested in an array stays in place (phpredis reads it as `false`), only a
/// top-level error reply is an error.
pub(crate) async fn send<C: ::redis::aio::ConnectionLike>(
    conn: &mut C,
    cmd: &::redis::Cmd,
) -> ::redis::RedisResult<Reply> {
    match conn.req_packed_command(cmd).await? {
        Reply::ServerError(e) => Err(e.into()),
        other => Ok(other),
    }
}

/// A transport failure (the socket is gone) rather than a refused command.
pub(crate) fn is_transport(e: &::redis::RedisError) -> bool {
    e.is_io_error()
        || e.is_connection_dropped()
        || e.is_connection_refusal()
        || e.is_timeout()
        || e.is_unrecoverable_error()
}

/// A connection the caller already holds: a redis-rs connection manager,
/// which reconnects by itself. Scripts run with `EVALSHA`, loaded on a
/// `NOSCRIPT` reply.
impl Connection for ::redis::aio::ConnectionManager {
    fn query(&self, cmd: ::redis::Cmd, _idempotent: bool) -> BoxFuture<'_, Result<Option<Reply>>> {
        Box::pin(async move {
            let mut conn = self.clone();
            match send(&mut conn, &cmd).await {
                Ok(reply) => Ok(Some(reply)),
                Err(e) if is_transport(&e) => Err(Error::Redis("Connection lost".into())),
                Err(_) => Ok(None),
            }
        })
    }

    fn execute<'a>(
        &'a self,
        script: &'a str,
        keys: &'a [Vec<u8>],
        args: &'a [Vec<u8>],
    ) -> BoxFuture<'a, Result<Reply>> {
        Box::pin(async move {
            let mut conn = self.clone();
            let hash = self::redis::script_hash(script);
            let mut eval = ::redis::cmd("EVALSHA");
            eval.arg(&hash).arg(keys.len());
            for k in keys {
                eval.arg(k.as_slice());
            }
            for a in args {
                eval.arg(a.as_slice());
            }
            let reply = match send(&mut conn, &eval).await {
                Ok(reply) => reply,
                Err(e) if e.kind() == ::redis::ErrorKind::NoScriptError => {
                    ::redis::cmd("SCRIPT")
                        .arg("LOAD")
                        .arg(script)
                        .query_async::<Reply>(&mut conn)
                        .await
                        .map_err(|e| Error::Redis(server_message(&e)))?;
                    send(&mut conn, &eval).await.map_err(|e| Error::Redis(server_message(&e)))?
                }
                Err(e) => return Err(Error::Redis(server_message(&e))),
            };
            raised(&reply).map_or(Ok(reply), |m| Err(Error::Redis(m)))
        })
    }

    fn ping(&self) -> BoxFuture<'_, bool> {
        Box::pin(async move {
            let mut conn = self.clone();
            ::redis::cmd("PING").query_async::<Reply>(&mut conn).await.is_ok()
        })
    }

    fn close(&self) -> BoxFuture<'_, ()> {
        Box::pin(async {})
    }
}
