//! [`Multiplexing`]: many tasks over one Redis connection.

use std::collections::VecDeque;
use std::future::Future;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use indexmap::IndexMap;
use php_std::types::Value;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use tokio::net::tcp::{OwnedReadHalf, OwnedWriteHalf};
use tokio::sync::{Notify, oneshot};

use crate::adapter::{Adapter, Fields, blank, coerce, empty, field, now};
use crate::lease::{self, Transport, is_reserved};
use crate::redis_adapter::text;
use crate::resp::{self, Reply};
use crate::{Codec, Envelope, Error, Json, Result};
use utopia_telemetry::{NoTelemetry, Telemetry, UpDownCounter};

/// Settings of a [`Multiplexing`] connection; defaults in parentheses.
///
/// PHP: the defaults are PHP's.
#[derive(Debug, Clone, PartialEq)]
pub struct MultiplexingOptions {
    /// Server host.
    pub host: String,
    /// Server port (6379).
    pub port: u16,
    /// Connect timeout in seconds (1.0).
    pub timeout: f64,
    /// How long one caller waits for its own reply, in seconds (0.25).
    /// Expiring fails that call only, not the connection.
    pub read_timeout: f64,
    /// `AUTH` arguments: `[password]` or `[username, password]`.
    pub auth: Option<Vec<String>>,
    /// Database selected after connecting (0).
    pub db: i64,
    /// How long the connection may make no progress at all, with callers
    /// waiting, before it is declared dead and torn down, in seconds (5.0).
    pub liveness_timeout: f64,
    /// How long an idle reader stays parked, in seconds (1.0). Validated
    /// for parity; the Rust reader is a parked task that costs nothing idle,
    /// so it stays for the connection's lifetime.
    pub idle_grace: f64,
}

impl MultiplexingOptions {
    /// Defaults for a server at `host:port`.
    pub fn new(host: impl Into<String>, port: u16) -> Self {
        MultiplexingOptions {
            host: host.into(),
            port,
            timeout: 1.0,
            read_timeout: 0.25,
            auth: None,
            db: 0,
            liveness_timeout: 5.0,
            idle_grace: 1.0,
        }
    }
}

type Waiter = oneshot::Sender<Result<Reply>>;

/// One live connection: the write half (its lock is the send lock), the
/// callers waiting for replies in wire order, and when the reader last made
/// progress.
struct Connection {
    writer: tokio::sync::Mutex<OwnedWriteHalf>,
    pending: Mutex<VecDeque<Waiter>>,
    progress: Mutex<Instant>,
    closed: Notify,
}

impl Connection {
    fn record_progress(&self) {
        *self.progress.lock().unwrap_or_else(|e| e.into_inner()) = Instant::now();
    }

    fn idle_for(&self) -> f64 {
        self.progress.lock().unwrap_or_else(|e| e.into_inner()).elapsed().as_secs_f64()
    }
}

struct Shared {
    current: Mutex<Option<Arc<Connection>>>,
    depth: Mutex<Option<UpDownCounter>>,
    telemetry: Mutex<Arc<dyn Telemetry>>,
}

impl Shared {
    fn depth(&self, amount: i64) {
        let mut depth = self.depth.lock().unwrap_or_else(|e| e.into_inner());
        let counter = depth.get_or_insert_with(|| {
            self.telemetry.lock().unwrap_or_else(|e| e.into_inner()).up_down_counter(
                "cache.redis_multiplexing.pending.depth",
                None,
                Some("Pending response channels awaiting RESP frames on the multiplexed connection."),
            )
        });
        counter.add(amount, &[]);
    }

    fn is_current(&self, connection: &Arc<Connection>) -> bool {
        self.current.lock().unwrap_or_else(|e| e.into_inner()).as_ref().is_some_and(|c| Arc::ptr_eq(c, connection))
    }

    /// Tears `connection` down if it is still the current one: fails every
    /// waiting caller with `error` and stops its reader.
    fn teardown(&self, connection: &Arc<Connection>, error: Error) {
        let current = {
            let mut current = self.current.lock().unwrap_or_else(|e| e.into_inner());
            if current.as_ref().is_some_and(|c| Arc::ptr_eq(c, connection)) { current.take() } else { None }
        };
        if let Some(connection) = current {
            self.finish(&connection, error);
        }
    }

    fn finish(&self, connection: &Connection, error: Error) {
        let waiting: Vec<Waiter> = connection.pending.lock().unwrap_or_else(|e| e.into_inner()).drain(..).collect();
        for waiter in waiting {
            self.depth(-1);
            let _ = waiter.send(Err(error.clone()));
        }
        connection.closed.notify_one();
    }
}

/// Many tasks sharing one Redis connection: each command is queued and
/// written under a send lock, so the queue's order is the wire order, and
/// one reader task hands every reply to the next caller in the queue (Redis
/// answers in order). Entries are stored like [`crate::Redis`] stores them,
/// with the same leases.
///
/// Two deadlines keep a slow server apart from a dead one. A caller that
/// waits longer than `read_timeout` fails with [`Error::Timeout`] and leaves
/// the connection alone (its slot stays queued so later replies stay
/// aligned). When nothing at all has arrived for `liveness_timeout` while
/// callers wait, the connection is torn down ([`Error::Idle`]) and the next
/// call reconnects. Other connection failures are retried once on a new
/// connection.
///
/// ```no_run
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Multiplexing, MultiplexingOptions};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let cache = Multiplexing::connect(MultiplexingOptions::new("localhost", 6379)).await?;
/// cache.save("docs:user:2", &Value::from("Ada"), "", 0).await?;
/// assert_eq!(cache.load("docs:user:2", 60, "").await?, Some(Value::from("Ada")));
/// cache.purge("docs:user:2", "").await?;
/// cache.disconnect();
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Redis\Multiplexing;
///
/// use function Swoole\Coroutine\run;
///
/// run(function () {
///     $cache = new Multiplexing('redis', 6379);
///     $cache->save('docs:user:2', 'Ada');
///     echo $cache->load('docs:user:2', 60), "\n"; // Ada
///     $cache->purge('docs:user:2');
///     $cache->disconnect();
/// });
/// ```
pub struct Multiplexing<K = Json> {
    options: MultiplexingOptions,
    envelope: Envelope<K>,
    grace_ms: i64,
    shared: Arc<Shared>,
    /// Serialises reconnects.
    connecting: tokio::sync::Mutex<()>,
}

impl Multiplexing<Json> {
    /// Connects with `options`, storing entries as JSON.
    pub async fn connect(options: MultiplexingOptions) -> Result<Self> {
        Self::connect_with_codec(options, Json).await
    }
}

impl<K: Codec> Multiplexing<K> {
    /// Connects with `options`, storing entries with `codec`. Fails on
    /// invalid options ([`Error::InvalidArgument`]) or when the connection,
    /// `AUTH` or `SELECT` fails.
    pub async fn connect_with_codec(options: MultiplexingOptions, codec: K) -> Result<Self> {
        let invalid = |m: &str| Err(Error::InvalidArgument(m.to_owned()));
        if options.timeout <= 0.0 {
            return invalid("timeout must be greater than 0");
        }
        if options.read_timeout <= 0.0 {
            return invalid("readTimeout must be greater than 0");
        }
        if options.liveness_timeout < options.read_timeout {
            return invalid("livenessTimeout must be greater than or equal to readTimeout");
        }
        if options.idle_grace <= 0.0 {
            return invalid("idleGrace must be greater than 0");
        }
        let adapter = Multiplexing {
            options,
            envelope: Envelope::new(codec),
            grace_ms: 0,
            shared: Arc::new(Shared {
                current: Mutex::new(None),
                depth: Mutex::new(None),
                telemetry: Mutex::new(Arc::new(NoTelemetry)),
            }),
            connecting: tokio::sync::Mutex::new(()),
        };
        adapter.open().await?;
        Ok(adapter)
    }

    /// Refuses leased saves for `ms` milliseconds after a purge.
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

    /// The options.
    pub fn options(&self) -> &MultiplexingOptions {
        &self.options
    }

    async fn open(&self) -> Result<()> {
        let o = &self.options;
        let timeout = Duration::from_secs_f64(o.timeout);
        let stream = match tokio::time::timeout(timeout, TcpStream::connect((o.host.as_str(), o.port))).await {
            Ok(Ok(stream)) => stream,
            Ok(Err(e)) => return Err(Error::Connection(format!("Failed to connect to Redis: {e}"))),
            Err(_) => return Err(Error::Connection("Failed to connect to Redis: Connection timed out".into())),
        };
        let _ = stream.set_nodelay(true);
        let (mut reader, mut writer) = stream.into_split();
        let mut buffer = Vec::new();
        let read_timeout = Duration::from_secs_f64(o.read_timeout);
        if let Some(auth) = &o.auth {
            let mut args: Vec<&[u8]> = vec![b"AUTH"];
            args.extend(auth.iter().map(|a| a.as_bytes()));
            if handshake(&mut reader, &mut writer, &mut buffer, &args, read_timeout).await?
                != Reply::Str(b"OK".to_vec())
            {
                return Err(Error::Redis("Redis AUTH failed".into()));
            }
        }
        if o.db != 0 {
            let db = o.db.to_string();
            let args: [&[u8]; 2] = [b"SELECT", db.as_bytes()];
            if handshake(&mut reader, &mut writer, &mut buffer, &args, read_timeout).await?
                != Reply::Str(b"OK".to_vec())
            {
                return Err(Error::Redis("Redis SELECT failed".into()));
            }
        }
        let connection = Arc::new(Connection {
            writer: tokio::sync::Mutex::new(writer),
            pending: Mutex::new(VecDeque::new()),
            progress: Mutex::new(Instant::now()),
            closed: Notify::new(),
        });
        *self.shared.current.lock().unwrap_or_else(|e| e.into_inner()) = Some(connection.clone());
        tokio::spawn(read(self.shared.clone(), connection, reader, buffer));
        Ok(())
    }

    /// Sends a command and waits for its reply, reconnecting and retrying
    /// once after a connection failure (not after a timeout).
    async fn command(&self, args: &[&[u8]]) -> Result<Reply> {
        match self.dispatch(args).await {
            Err(Error::Connection(_)) => {
                self.ensure_connected().await?;
                self.dispatch(args).await
            }
            other => other,
        }
    }

    async fn ensure_connected(&self) -> Result<()> {
        let _connecting = self.connecting.lock().await;
        if self.shared.current.lock().unwrap_or_else(|e| e.into_inner()).is_none() {
            self.open().await?;
        }
        Ok(())
    }

    async fn dispatch(&self, args: &[&[u8]]) -> Result<Reply> {
        let connection = self
            .shared
            .current
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .clone()
            .ok_or_else(|| Error::Connection("Redis connection is not open".into()))?;
        let (sender, receiver) = oneshot::channel();
        let sent = {
            let mut writer = connection.writer.lock().await;
            {
                let mut pending = connection.pending.lock().unwrap_or_else(|e| e.into_inner());
                if pending.is_empty() {
                    // Idle time with nobody waiting says nothing about liveness.
                    connection.record_progress();
                }
                pending.push_back(sender);
            }
            self.shared.depth(1);
            writer.write_all(&resp::encode(args)).await
        };
        if let Err(e) = sent {
            let error = Error::Connection(format!("Redis send failed: {e}"));
            self.shared.teardown(&connection, error.clone());
            return Err(error);
        }
        let read_timeout = Duration::from_secs_f64(self.options.read_timeout);
        match tokio::time::timeout(read_timeout, receiver).await {
            Ok(Ok(result)) => resp::unwrap(result?),
            Ok(Err(_)) => Err(Error::Connection("Redis connection closed".into())),
            Err(_) => {
                let idle = connection.idle_for();
                if idle >= self.options.liveness_timeout {
                    let error = Error::Idle(format!("Redis connection idle for {idle:.3}s with responses outstanding"));
                    self.shared.teardown(&connection, error.clone());
                    return Err(error);
                }
                // The slot stays queued: the reader drops the late reply in order.
                Err(Error::Timeout(format!(
                    "Timed out waiting for Redis response after {:.3}s",
                    self.options.read_timeout
                )))
            }
        }
    }
}

/// A synchronous command during the handshake, before the reader starts.
async fn handshake(
    reader: &mut OwnedReadHalf,
    writer: &mut OwnedWriteHalf,
    buffer: &mut Vec<u8>,
    args: &[&[u8]],
    timeout: Duration,
) -> Result<Reply> {
    writer.write_all(&resp::encode(args)).await.map_err(|e| Error::Connection(format!("Redis send failed: {e}")))?;
    loop {
        let mut offset = 0;
        if let Some(reply) = resp::parse(buffer, &mut offset)? {
            buffer.drain(..offset);
            return resp::unwrap(reply);
        }
        let mut chunk = [0u8; 4096];
        match tokio::time::timeout(timeout, reader.read(&mut chunk)).await {
            Ok(Ok(n)) if n > 0 => buffer.extend_from_slice(&chunk[..n]),
            _ => return Err(Error::Connection("Timed out waiting for Redis response".into())),
        }
    }
}

/// The reader: parses replies and hands each to the next waiting caller.
async fn read(shared: Arc<Shared>, connection: Arc<Connection>, mut reader: OwnedReadHalf, mut buffer: Vec<u8>) {
    let mut chunk = vec![0u8; 64 * 1024];
    loop {
        loop {
            let mut offset = 0;
            let reply = match resp::parse(&buffer, &mut offset) {
                Ok(Some(reply)) => reply,
                Ok(None) => break,
                Err(e) => {
                    shared.teardown(&connection, Error::Connection(format!("Redis protocol parse failed: {e}")));
                    return;
                }
            };
            buffer.drain(..offset);
            if !shared.is_current(&connection) {
                return;
            }
            let waiter = connection.pending.lock().unwrap_or_else(|e| e.into_inner()).pop_front();
            match waiter {
                Some(waiter) => {
                    shared.depth(-1);
                    connection.record_progress();
                    // A caller that gave up has dropped its receiver: the reply is dropped.
                    let _ = waiter.send(Ok(reply));
                }
                None => {
                    tracing::error!("Redis multiplexing: unexpected RESP frame with no pending request");
                    shared.teardown(
                        &connection,
                        Error::Connection("Unexpected RESP frame with no pending request".into()),
                    );
                    return;
                }
            }
        }
        tokio::select! {
            read = reader.read(&mut chunk) => match read {
                Ok(n) if n > 0 => {
                    connection.record_progress();
                    buffer.extend_from_slice(&chunk[..n]);
                }
                _ => {
                    shared.teardown(&connection, Error::Connection("Redis connection closed".into()));
                    return;
                }
            },
            _ = connection.closed.notified() => return,
        }
    }
}

impl<K> Multiplexing<K> {
    /// Closes the connection, failing callers still waiting.
    ///
    /// PHP: `disconnect`.
    pub fn disconnect(&self) {
        let current = self.shared.current.lock().unwrap_or_else(|e| e.into_inner()).take();
        if let Some(connection) = current {
            self.shared.finish(&connection, Error::Connection("Connection closed".into()));
        }
    }
}

impl<K> Drop for Multiplexing<K> {
    /// Closes the connection ([`Multiplexing::disconnect`]).
    fn drop(&mut self) {
        self.disconnect();
    }
}

impl<K: Codec> Transport for Multiplexing<K> {
    /// Runs a cached script by digest; [`Error::NoScript`] when the server
    /// has no script for `sha`.
    async fn eval_sha(&self, sha: &str, key: &str, args: &[&[u8]]) -> Result<Reply> {
        let mut command: Vec<&[u8]> = vec![b"EVALSHA", sha.as_bytes(), b"1", key.as_bytes()];
        command.extend_from_slice(args);
        match self.command(&command).await {
            Err(Error::Redis(e)) if resp::is_noscript(&e) => Err(Error::NoScript(e)),
            other => other,
        }
    }

    /// Runs a script, sending its body.
    async fn eval(&self, script: &str, key: &str, args: &[&[u8]]) -> Result<Reply> {
        let mut command: Vec<&[u8]> = vec![b"EVAL", script.as_bytes(), b"1", key.as_bytes()];
        command.extend_from_slice(args);
        self.command(&command).await
    }

    /// The value of one field of a hash, `None` when missing.
    async fn hget(&self, key: &str, field: &str) -> Result<Option<Vec<u8>>> {
        Ok(match self.command(&[b"HGET", key.as_bytes(), field.as_bytes()]).await? {
            Reply::Str(s) => Some(s),
            _ => None,
        })
    }
}

impl<K: Codec> Adapter for Multiplexing<K> {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<Value>> {
        let hash = field(key, hash);
        if is_reserved(hash) {
            return Ok(None);
        }
        Ok(match Transport::hget(self, key, hash).await? {
            Some(stored) => self.envelope.decode(&stored, ttl, now()),
            None => None,
        })
    }

    async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> Result<bool> {
        let data = coerce(data, "Utopia\\Cache\\Adapter\\Redis\\Multiplexing::save(): Argument #2 ($data)")?;
        let data = data.as_ref();
        if blank(key) || empty(data) {
            return Ok(false);
        }
        let hash = field(key, hash);
        if is_reserved(hash) {
            return Ok(false);
        }
        let Ok(value) = self.envelope.encode(data, now()) else { return Ok(false) };
        if self.command(&[b"HSET", key.as_bytes(), hash.as_bytes(), &value]).await.is_err() {
            return Ok(false);
        }
        if ttl > 0 && self.command(&[b"EXPIRE", key.as_bytes(), ttl.to_string().as_bytes()]).await.is_err() {
            return Ok(false);
        }
        Ok(true)
    }

    async fn touch(&self, key: &str, hash: &str) -> Result<bool> {
        let hash = field(key, hash);
        if is_reserved(hash) {
            return Ok(false);
        }
        let Some(stored) = Transport::hget(self, key, hash).await? else { return Ok(false) };
        let Some(value) = self.envelope.touch(&stored, now()) else { return Ok(false) };
        self.command(&[b"HSET", key.as_bytes(), hash.as_bytes(), &value]).await?;
        Ok(true)
    }

    async fn list(&self, key: &str) -> Result<Vec<String>> {
        Ok(match self.command(&[b"HKEYS", key.as_bytes()]).await? {
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
        Ok(self.command(&[b"FLUSHDB"]).await? == Reply::Str(b"OK".to_vec()))
    }

    async fn ping(&self) -> Result<bool> {
        Ok(matches!(self.command(&[b"PING"]).await, Ok(Reply::Str(s)) if s == b"PONG"))
    }

    async fn size(&self) -> Result<i64> {
        Ok(match self.command(&[b"DBSIZE"]).await? {
            Reply::Int(size) => size,
            _ => 0,
        })
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "redis-multiplexing"
    }

    fn batchable(&self) -> bool {
        true
    }

    async fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> Result<Fields> {
        let now = now();
        let mut pairs: IndexMap<String, Option<Vec<u8>>> = IndexMap::new();
        if fields.is_empty() {
            let Reply::Array(flat) = self.command(&[b"HGETALL", key.as_bytes()]).await? else {
                return Ok(Fields::new());
            };
            let mut flat = flat.into_iter();
            while let (Some(f), Some(v)) = (flat.next(), flat.next()) {
                pairs.insert(f.as_bytes().map(text).unwrap_or_default(), v.as_bytes().map(<[u8]>::to_vec));
            }
        } else {
            let mut command: Vec<&[u8]> = vec![b"HMGET", key.as_bytes()];
            command.extend(fields.iter().map(|f| f.as_bytes()));
            let Reply::Array(values) = self.command(&command).await? else { return Ok(Fields::new()) };
            for (i, f) in fields.iter().enumerate() {
                pairs.insert((*f).to_owned(), values.get(i).and_then(|v| v.as_bytes()).map(<[u8]>::to_vec));
            }
        }
        let mut result = Fields::new();
        for (f, v) in pairs {
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
        let mut encoded: Vec<(&str, Vec<u8>)> = Vec::with_capacity(data.len());
        for (f, value) in data {
            if is_reserved(f) {
                continue;
            }
            encoded.push((f, self.envelope.encode(value, now())?));
        }
        if encoded.is_empty() {
            return Ok(false);
        }
        let mut command: Vec<&[u8]> = vec![b"HSET", key.as_bytes()];
        for (f, v) in &encoded {
            command.push(f.as_bytes());
            command.push(v);
        }
        if self.command(&command).await.is_err() {
            return Ok(false);
        }
        if ttl > 0 && self.command(&[b"EXPIRE", key.as_bytes(), ttl.to_string().as_bytes()]).await.is_err() {
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

    async fn load_bytes(&self, key: &str, hash: &str) -> Result<Option<Vec<u8>>> {
        let hash = field(key, hash);
        if blank(key) || is_reserved(hash) {
            return Ok(None);
        }
        Transport::hget(self, key, hash).await
    }

    async fn save_bytes(&self, key: &str, bytes: &[u8], hash: &str, ttl: i64) -> Result<bool> {
        let hash = field(key, hash);
        if blank(key) || is_reserved(hash) {
            return Ok(false);
        }
        // As `save`: a failed write is not stored, not an error.
        if self.command(&[b"HSET", key.as_bytes(), hash.as_bytes(), bytes]).await.is_err() {
            return Ok(false);
        }
        if ttl > 0 && self.command(&[b"EXPIRE", key.as_bytes(), ttl.to_string().as_bytes()]).await.is_err() {
            return Ok(false);
        }
        Ok(true)
    }

    fn save_bytes_with_lease(
        &self,
        key: &str,
        bytes: &[u8],
        hash: &str,
        generation: &str,
    ) -> impl Future<Output = Result<bool>> + Send {
        lease::save_bytes_with_lease(self, self.grace_ms, key, bytes, hash, generation)
    }

    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        *self.shared.telemetry.lock().unwrap_or_else(|e| e.into_inner()) = telemetry;
        *self.shared.depth.lock().unwrap_or_else(|e| e.into_inner()) = None;
    }
}
