//! One Redis server.

use std::collections::HashSet;
use std::sync::Mutex as StdMutex;
use std::time::Duration;

use redis::aio::MultiplexedConnection;
use redis::{AsyncConnectionConfig, ConnectionAddr, ConnectionInfo, ProtocolVersion, RedisConnectionInfo};
use sha1::{Digest, Sha1};
use tokio::sync::Mutex;
use utopia_dsn::Dsn;

use super::{BoxFuture, Connection, Reply, is_transport, server_message};
use crate::error::{Error, Result};

const CONNECT_MAX_ATTEMPTS: u32 = 5;
const CONNECT_BACKOFF_MS: u64 = 100;
const CONNECT_MAX_BACKOFF_MS: u64 = 3_000;

/// A connection to one Redis server, opened on first use and reopened after
/// the socket is lost.
///
/// Connecting is retried five times with jittered exponential backoff. A
/// command that fails because the socket went away drops the connection; a
/// command safe to repeat (reads, `SET`, `DEL`, `LREM`) is run once more on
/// a fresh one, others (pops, pushes, counters) report the error, because the
/// server may already have applied them.
///
/// ```no_run
/// use utopia_queue::connection::{Connection, Redis};
///
/// # async fn run() -> Result<(), utopia_queue::Error> {
/// let redis = Redis::new("localhost", 6379);
/// redis.left_push(b"jobs", b"payload").await?;
/// assert_eq!(redis.list_size(b"jobs").await?, 1);
/// # Ok(()) }
/// ```
///
/// ```php
/// use Utopia\Queue\Connection\Redis;
///
/// $redis = new Redis('redis', 6379);
/// $redis->leftPush('compat-doc-jobs', 'payload');
/// echo $redis->listSize('compat-doc-jobs'), "\n"; // 1
/// $redis->remove('compat-doc-jobs');
/// ```
pub struct Redis {
    host: String,
    port: u16,
    user: Option<String>,
    password: Option<String>,
    connect_timeout: f64,
    read_timeout: f64,
    conn: Mutex<Option<MultiplexedConnection>>,
    scripts: StdMutex<HashSet<String>>,
}

impl Redis {
    /// A connection to `host:port` without credentials or timeouts.
    pub fn new(host: impl Into<String>, port: u16) -> Self {
        Self::with(host, port, None, None, -1.0, -1.0)
    }

    /// A connection with credentials and timeouts (seconds; negative for none).
    /// A password without a user authenticates the default user; no password
    /// sends no `AUTH`.
    pub fn with(
        host: impl Into<String>,
        port: u16,
        user: Option<String>,
        password: Option<String>,
        connect_timeout: f64,
        read_timeout: f64,
    ) -> Self {
        Self {
            host: host.into(),
            port,
            user,
            password,
            connect_timeout,
            read_timeout,
            conn: Mutex::new(None),
            scripts: StdMutex::new(HashSet::new()),
        }
    }

    /// `redis://[user]:[password]@host[:port]`, credentials URL-decoded.
    pub fn from_dsn(dsn: &Dsn, connect_timeout: f64, read_timeout: f64) -> Self {
        let text = |b: Option<&[u8]>| b.map(|b| String::from_utf8_lossy(b).into_owned());
        Self::with(
            dsn.host(),
            dsn.port().unwrap_or(6379),
            text(dsn.user()),
            text(dsn.password()),
            connect_timeout,
            read_timeout,
        )
    }

    fn info(&self) -> ConnectionInfo {
        let has_password = self.password.as_deref().is_some_and(|p| !p.is_empty());
        let has_user = self.user.as_deref().is_some_and(|u| !u.is_empty());
        ConnectionInfo {
            addr: ConnectionAddr::Tcp(self.host.clone(), self.port),
            redis: RedisConnectionInfo {
                db: 0,
                username: if has_password && has_user { self.user.clone() } else { None },
                password: if has_password { self.password.clone() } else { None },
                protocol: ProtocolVersion::RESP2,
            },
        }
    }

    async fn open(&self) -> Result<MultiplexedConnection> {
        let mut config = AsyncConnectionConfig::new();
        if self.connect_timeout > 0.0 {
            config = config.set_connection_timeout(Duration::from_secs_f64(self.connect_timeout));
        }
        if self.read_timeout >= 0.0 {
            config = config.set_response_timeout(Duration::from_secs_f64(self.read_timeout.max(0.001)));
        }
        let mut attempt = 1;
        loop {
            let result = match redis::Client::open(self.info()) {
                Ok(client) => client.get_multiplexed_async_connection_with_config(&config).await,
                Err(e) => Err(e),
            };
            match result {
                Ok(conn) => return Ok(conn),
                Err(e) if attempt >= CONNECT_MAX_ATTEMPTS => {
                    return Err(Error::Redis(format!(
                        "Failed to connect to Redis at {}:{} after {} attempts: {}",
                        self.host,
                        self.port,
                        CONNECT_MAX_ATTEMPTS,
                        connect_message(&e)
                    )));
                }
                Err(_) => {
                    let backoff = CONNECT_MAX_BACKOFF_MS.min(CONNECT_BACKOFF_MS * (1 << (attempt - 1)));
                    tokio::time::sleep(Duration::from_millis(jitter(backoff))).await;
                    attempt += 1;
                }
            }
        }
    }

    async fn connection(&self) -> Result<MultiplexedConnection> {
        let mut guard = self.conn.lock().await;
        if let Some(conn) = guard.as_ref() {
            return Ok(conn.clone());
        }
        let conn = self.open().await?;
        *guard = Some(conn.clone());
        Ok(conn)
    }

    async fn drop_connection(&self) {
        *self.conn.lock().await = None;
        if let Ok(mut s) = self.scripts.lock() {
            s.clear();
        }
    }

    async fn run(&self, cmd: &redis::Cmd) -> std::result::Result<Reply, redis::RedisError> {
        let mut conn = match self.connection().await {
            Ok(c) => c,
            Err(Error::Redis(m)) => return Err(redis::RedisError::from((redis::ErrorKind::IoError, "connect", m))),
            Err(e) => return Err(redis::RedisError::from((redis::ErrorKind::IoError, "connect", e.to_string()))),
        };
        match super::send(&mut conn, cmd).await {
            // The socket was already closed before the command could leave
            // (the server dropped an idle connection): like phpredis' check
            // for EOF before writing, reconnect and send it, once.
            Err(e) if gone_before_sent(&e) => {
                self.drop_connection().await;
                let mut conn = self
                    .connection()
                    .await
                    .map_err(|e| redis::RedisError::from((redis::ErrorKind::IoError, "connect", e.to_string())))?;
                super::send(&mut conn, cmd).await
            }
            other => other,
        }
    }
}

/// A closed connection found before the request was written.
fn gone_before_sent(e: &redis::RedisError) -> bool {
    e.kind() == redis::ErrorKind::IoError && e.to_string().to_ascii_lowercase().contains("broken pipe")
}

fn connect_message(e: &redis::RedisError) -> String {
    if e.is_io_error() || e.is_connection_refusal() {
        return "Connection refused".to_owned();
    }
    server_message(e)
}

/// `mt_rand(0, max)`.
pub(crate) fn jitter(max: u64) -> u64 {
    let mut b = [0u8; 8];
    let _ = getrandom::fill(&mut b);
    u64::from_le_bytes(b) % (max + 1)
}

/// The SHA-1 a script is loaded under.
pub(crate) fn script_hash(script: &str) -> String {
    hex::encode(Sha1::digest(script.as_bytes()))
}

impl Connection for Redis {
    fn query(&self, cmd: redis::Cmd, idempotent: bool) -> BoxFuture<'_, Result<Option<Reply>>> {
        Box::pin(async move {
            let mut retried = false;
            loop {
                match self.run(&cmd).await {
                    Ok(reply) => match super::raised(&reply) {
                        Some(message) => return Err(Error::Redis(message)),
                        None => return Ok(Some(reply)),
                    },
                    Err(e) if is_transport(&e) => {
                        self.drop_connection().await;
                        if !idempotent || retried {
                            return Err(Error::Redis(transport_message(&e)));
                        }
                        retried = true;
                    }
                    // A refusal phpredis raises (NOAUTH, a script's own error), or `false`.
                    Err(e) if super::raises(&server_message(&e)) => return Err(Error::Redis(server_message(&e))),
                    Err(_) => return Ok(None),
                }
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
            let hash = script_hash(script);
            let loaded = self.scripts.lock().map(|s| s.contains(&hash)).unwrap_or(false);
            let load = || {
                let mut c = redis::cmd("SCRIPT");
                c.arg("LOAD").arg(script);
                c
            };
            let transport = |e: redis::RedisError| Error::Redis(transport_message(&e));
            if !loaded {
                self.run(&load()).await.map_err(|e| self.lost(e))?;
                if let Ok(mut s) = self.scripts.lock() {
                    s.insert(hash.clone());
                }
            }
            let mut eval = redis::cmd("EVALSHA");
            eval.arg(&hash).arg(keys.len());
            for k in keys {
                eval.arg(k.as_slice());
            }
            for a in args {
                eval.arg(a.as_slice());
            }
            let reply = match self.run(&eval).await {
                Ok(reply) => reply,
                Err(e) if e.kind() == redis::ErrorKind::NoScriptError => {
                    self.run(&load()).await.map_err(transport)?;
                    self.run(&eval).await.map_err(|e| self.lost(e))?
                }
                Err(e) => return Err(self.lost(e)),
            };
            match super::raised(&reply) {
                Some(message) => Err(Error::Redis(message)),
                None => Ok(reply),
            }
        })
    }

    fn ping(&self) -> BoxFuture<'_, bool> {
        Box::pin(async move { self.run(&redis::cmd("PING")).await.is_ok() })
    }

    fn close(&self) -> BoxFuture<'_, ()> {
        Box::pin(async move { self.drop_connection().await })
    }
}

impl Redis {
    fn lost(&self, e: redis::RedisError) -> Error {
        if is_transport(&e) {
            // Dropped synchronously on the next call: `conn` is reset lazily.
            if let Ok(mut guard) = self.conn.try_lock() {
                *guard = None;
            }
            return Error::Redis(transport_message(&e));
        }
        Error::Redis(server_message(&e))
    }
}

fn transport_message(e: &redis::RedisError) -> String {
    if let Some(detail) = e.detail()
        && detail.starts_with("Failed to connect")
    {
        return detail.to_owned();
    }
    if e.is_connection_refusal() {
        return "Connection refused".to_owned();
    }
    "Connection lost".to_owned()
}
