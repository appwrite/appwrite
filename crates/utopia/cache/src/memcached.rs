//! [`Memcached`] and [`Hazelcast`]: the Memcached text protocol, with the
//! value format of PHP's Memcached extension.

use std::time::Duration;

use bytes::Bytes;
use php_std::types::{Array, ArrayKey, Value};
use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufStream};
use tokio::net::TcpStream;

use crate::adapter::{Adapter, Retry, blank, coerce, empty, now};
use crate::{Codec, Envelope, Error, Json, Result, fastlz};

/// Value flags of the Memcached extension.
const IS_STRING: u32 = 0;
const IS_LONG: u32 = 1;
const IS_DOUBLE: u32 = 2;
const IS_BOOL: u32 = 3;
const IS_SERIALIZED: u32 = 4;
const IS_IGBINARY: u32 = 5;
const IS_JSON: u32 = 6;
const TYPE_MASK: u32 = 0xf;
const COMPRESSED: u32 = 1 << 4;
const COMPRESSION_ZLIB: u32 = 1 << 5;
const COMPRESSION_FASTLZ: u32 = 1 << 6;
/// `memcached.compression_threshold`.
const COMPRESSION_THRESHOLD: usize = 2000;
/// `memcached.compression_factor`.
const COMPRESSION_FACTOR: f64 = 1.3;

/// The flags and bytes the extension stores for `value`: strings as they
/// are, anything else `serialize()`d; payloads of 2000 bytes or more
/// FastLZ-compressed when that saves enough (prefixed with their length).
fn pack(value: &Value) -> (u32, Vec<u8>) {
    let (mut flags, payload) = match value {
        Value::Str(s) => (IS_STRING, s.as_bytes().to_vec()),
        other => (IS_SERIALIZED, php_std::serialize::serialize(other)),
    };
    if payload.len() < COMPRESSION_THRESHOLD {
        return (flags, payload);
    }
    let compressed = fastlz::compress(&payload);
    if compressed.is_empty() || payload.len() as f64 <= compressed.len() as f64 * COMPRESSION_FACTOR {
        return (flags, payload);
    }
    flags |= COMPRESSED | COMPRESSION_FASTLZ;
    let mut out = Vec::with_capacity(compressed.len() + 4);
    out.extend_from_slice(&(payload.len() as u32).to_le_bytes());
    out.extend_from_slice(&compressed);
    (flags, out)
}

/// The value the extension reads from `flags` and `bytes`; `None` when it
/// cannot (PHP's `get` fails).
fn unpack(flags: u32, bytes: Vec<u8>) -> Option<Value> {
    let payload = if flags & COMPRESSED != 0 {
        let size = u32::from_le_bytes(bytes.get(..4)?.try_into().ok()?) as usize;
        let body = &bytes[4..];
        if flags & COMPRESSION_ZLIB != 0 {
            let mut out = Vec::with_capacity(size);
            flate2::read::ZlibDecoder::new(body).read_to_end_sync(&mut out)?;
            out
        } else {
            fastlz::decompress(body, size)?
        }
    } else {
        bytes
    };
    Some(match flags & TYPE_MASK {
        IS_STRING => Value::Str(payload.into()),
        IS_LONG => Value::Int(php_std::format::str_to_int(&payload)),
        IS_DOUBLE => Value::Float(php_std::format::str_to_float(&payload)),
        IS_BOOL => Value::Bool(payload.as_slice() == b"1"),
        IS_SERIALIZED => php_std::serialize::unserialize(&payload, &Default::default()).ok()?.value,
        IS_IGBINARY => php_std::igbinary::unserialize(&Bytes::from(payload)).ok()?,
        IS_JSON => php_std::json::decode(&payload, Some(false), 512, php_std::json::Flags::NONE).ok()?,
        _ => return None,
    })
}

trait ReadToEnd {
    fn read_to_end_sync(self, out: &mut Vec<u8>) -> Option<()>;
}

impl<R: std::io::Read> ReadToEnd for flate2::read::ZlibDecoder<R> {
    fn read_to_end_sync(mut self, out: &mut Vec<u8>) -> Option<()> {
        std::io::Read::read_to_end(&mut self, out).ok().map(|_| ())
    }
}

/// Why a Memcached request failed on the connection.
#[derive(Debug, Clone)]
struct Failure(String);

/// A connection to one Memcached-protocol server (Memcached, or Hazelcast
/// with its Memcached endpoint), reconnecting after a failure.
pub struct MemcachedClient {
    host: String,
    port: u16,
    timeout: Duration,
    stream: tokio::sync::Mutex<Option<BufStream<TcpStream>>>,
}

impl MemcachedClient {
    /// A client for `host:port` (connected on first use).
    pub fn new(host: impl Into<String>, port: u16) -> Self {
        MemcachedClient {
            host: host.into(),
            port,
            timeout: Duration::from_secs(1),
            stream: tokio::sync::Mutex::new(None),
        }
    }

    /// `host:port`, as the extension names a server in its stats.
    pub fn server(&self) -> String {
        format!("{}:{}", self.host, self.port)
    }

    async fn request(&self, command: &[u8], reply: Reply) -> std::result::Result<Response, Failure> {
        let mut guard = self.stream.lock().await;
        if guard.is_none() {
            let connect = TcpStream::connect((self.host.as_str(), self.port));
            let stream = match tokio::time::timeout(self.timeout, connect).await {
                Ok(Ok(stream)) => stream,
                _ => return Err(Failure("CONNECTION FAILURE".into())),
            };
            let _ = stream.set_nodelay(true);
            *guard = Some(BufStream::new(stream));
        }
        let Some(stream) = guard.as_mut() else { return Err(Failure("CONNECTION FAILURE".into())) };
        let exchange = async {
            stream.write_all(command).await?;
            stream.flush().await?;
            read(stream, reply).await
        };
        match tokio::time::timeout(self.timeout, exchange).await {
            Ok(Ok(value)) => Ok(value),
            Ok(Err(e)) => {
                *guard = None;
                Err(Failure(if e.kind() == std::io::ErrorKind::UnexpectedEof {
                    "CONNECTION FAILURE".into()
                } else {
                    "UNKNOWN READ FAILURE".into()
                }))
            }
            Err(_) => {
                *guard = None;
                Err(Failure("A TIMEOUT OCCURRED".into()))
            }
        }
    }

    /// `get key`: the flags and bytes stored, `None` when missing.
    async fn get(&self, key: &str) -> std::result::Result<Option<(u32, Vec<u8>)>, Failure> {
        let mut command = b"get ".to_vec();
        command.extend_from_slice(key.as_bytes());
        command.extend_from_slice(b"\r\n");
        match self.request(&command, Reply::Item).await? {
            Response::Item(item) => Ok(item),
            _ => Ok(None),
        }
    }

    /// `set key flags 0 bytes`: whether it was stored.
    async fn set(&self, key: &str, flags: u32, data: &[u8]) -> std::result::Result<bool, Failure> {
        let mut command = format!("set {key} {flags} 0 {}\r\n", data.len()).into_bytes();
        command.extend_from_slice(data);
        command.extend_from_slice(b"\r\n");
        Ok(matches!(self.request(&command, Reply::Line).await?, Response::Line(l) if l == b"STORED"))
    }

    /// `delete key`: whether it existed.
    async fn delete(&self, key: &str) -> std::result::Result<bool, Failure> {
        let command = format!("delete {key}\r\n").into_bytes();
        Ok(matches!(self.request(&command, Reply::Line).await?, Response::Line(l) if l == b"DELETED"))
    }

    /// `flush_all`.
    async fn flush(&self) -> std::result::Result<bool, Failure> {
        Ok(matches!(self.request(b"flush_all\r\n", Reply::Line).await?, Response::Line(l) if l == b"OK"))
    }

    /// `stats`: name => value.
    async fn stats(&self) -> std::result::Result<Vec<(String, String)>, Failure> {
        match self.request(b"stats\r\n", Reply::Stats).await? {
            Response::Stats(stats) => Ok(stats),
            _ => Ok(Vec::new()),
        }
    }
}

/// The reply a command expects.
#[derive(Clone, Copy)]
enum Reply {
    Item,
    Line,
    Stats,
}

enum Response {
    Item(Option<(u32, Vec<u8>)>),
    Line(Vec<u8>),
    Stats(Vec<(String, String)>),
}

async fn read(stream: &mut BufStream<TcpStream>, reply: Reply) -> std::io::Result<Response> {
    match reply {
        Reply::Line => Ok(Response::Line(read_line(stream).await?)),
        Reply::Item => {
            let mut found = None;
            loop {
                let line = read_line(stream).await?;
                if line == b"END" {
                    return Ok(Response::Item(found));
                }
                let header = String::from_utf8_lossy(&line).into_owned();
                let parts: Vec<&str> = header.split(' ').collect();
                if parts.len() < 4 || parts[0] != "VALUE" {
                    return Err(std::io::Error::other(header));
                }
                let flags: u32 = parts[2].parse().map_err(std::io::Error::other)?;
                let size: usize = parts[3].parse().map_err(std::io::Error::other)?;
                let mut data = vec![0u8; size + 2];
                stream.read_exact(&mut data).await?;
                data.truncate(size);
                found = Some((flags, data));
            }
        }
        Reply::Stats => {
            let mut stats = Vec::new();
            loop {
                let line = read_line(stream).await?;
                if line == b"END" {
                    return Ok(Response::Stats(stats));
                }
                let line = String::from_utf8_lossy(&line).into_owned();
                let mut parts = line.splitn(3, ' ');
                if parts.next() != Some("STAT") {
                    return Err(std::io::Error::other(line));
                }
                let name = parts.next().unwrap_or_default().to_owned();
                stats.push((name, parts.next().unwrap_or_default().to_owned()));
            }
        }
    }
}

async fn read_line(stream: &mut BufStream<TcpStream>) -> std::io::Result<Vec<u8>> {
    let mut line = Vec::new();
    if stream.read_until(b'\n', &mut line).await? == 0 {
        return Err(std::io::ErrorKind::UnexpectedEof.into());
    }
    while line.last().is_some_and(|b| *b == b'\n' || *b == b'\r') {
        line.pop();
    }
    Ok(line)
}

/// Runs a request, retrying connection failures (`execute`).
macro_rules! execute {
    ($retry:expr, $label:literal, $request:expr) => {{
        let mut attempts = 0u32;
        loop {
            match $request {
                Ok(value) => break Ok(value),
                Err(Failure(message)) => {
                    attempts += 1;
                    if attempts > $retry.max_retries() {
                        break Err(Error::Memcached(format!(
                            "{} connection failed after {attempts} attempts. Error: {message}",
                            $label
                        )));
                    }
                    $retry.pause().await;
                }
            }
        }
    }};
}

/// Entries in Memcached: one item per key holding the serialized
/// `['time' => ..., 'data' => ...]` record, in the Memcached extension's
/// format (so PHP and Rust read each other's items). Hashes are ignored.
///
/// ```no_run
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Memcached, MemcachedClient};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let cache = Memcached::new(MemcachedClient::new("localhost", 11211));
/// cache.save("greeting", &Value::from("hello"), "", 0).await?;
/// assert_eq!(cache.load("greeting", 60, "").await?, Some(Value::from("hello")));
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
pub struct Memcached {
    client: MemcachedClient,
    retry: Retry,
}

impl Memcached {
    /// Entries through `client`.
    pub fn new(client: MemcachedClient) -> Self {
        Memcached { client, retry: Retry::default() }
    }

    /// Retries connection failures up to `retries` times (clamped to `0..=10`, `setMaxRetries`).
    pub fn with_max_retries(mut self, retries: i64) -> Self {
        self.retry = self.retry.with_max_retries(retries);
        self
    }

    /// Waits `delay_ms` milliseconds between attempts (`setRetryDelay`).
    pub fn with_retry_delay(mut self, delay_ms: i64) -> Self {
        self.retry = self.retry.with_delay_ms(delay_ms);
        self
    }

    /// The retry settings (`getMaxRetries`, `getRetryDelay`).
    pub fn retry(&self) -> Retry {
        self.retry
    }

    async fn record(&self, key: &str) -> Result<Option<Array>> {
        let item = execute!(self.retry, "Memcached", self.client.get(key).await)?;
        Ok(match item.and_then(|(flags, bytes)| unpack(flags, bytes)) {
            Some(Value::Array(record)) => Some(record),
            _ => None,
        })
    }

    async fn store(&self, key: &str, record: Array) -> Result<bool> {
        let (flags, bytes) = pack(&Value::Array(record));
        execute!(self.retry, "Memcached", self.client.set(key, flags, &bytes).await)
    }
}

fn time_of(record: &Array) -> i64 {
    match record.get(&ArrayKey::from("time")) {
        Some(Value::Int(time)) => *time,
        _ => 0,
    }
}

impl Adapter for Memcached {
    async fn load(&self, key: &str, ttl: i64, _hash: &str) -> Result<Option<Value>> {
        let Some(mut record) = self.record(key).await? else { return Ok(None) };
        if !crate::memory::fresh(time_of(&record), ttl, now()) {
            return Ok(None);
        }
        Ok(Some(record.remove(&ArrayKey::from("data")).unwrap_or_default()))
    }

    async fn save(&self, key: &str, data: &Value, _hash: &str, _ttl: i64) -> Result<bool> {
        let data = coerce(data, "Utopia\\Cache\\Adapter\\Memcached::save(): Argument #2 ($data)")?;
        if blank(key) || empty(&data) {
            return Ok(false);
        }
        let mut record = Array::with_capacity(2);
        record.set(ArrayKey::from("time"), Value::Int(now()));
        record.set(ArrayKey::from("data"), data.into_owned());
        self.store(key, record).await
    }

    async fn touch(&self, key: &str, _hash: &str) -> Result<bool> {
        let Some(mut record) = self.record(key).await? else { return Ok(false) };
        record.set(ArrayKey::from("time"), Value::Int(now()));
        self.store(key, record).await
    }

    async fn list(&self, _key: &str) -> Result<Vec<String>> {
        Ok(Vec::new())
    }

    async fn purge(&self, key: &str, _hash: &str) -> Result<bool> {
        execute!(self.retry, "Memcached", self.client.delete(key).await)
    }

    async fn flush(&self) -> Result<bool> {
        execute!(self.retry, "Memcached", self.client.flush().await)
    }

    async fn ping(&self) -> Result<bool> {
        Ok(self.client.stats().await.is_ok_and(|s| !s.is_empty()))
    }

    async fn size(&self) -> Result<i64> {
        Ok(stat(&self.client, "curr_items").await)
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "memcached"
    }
}

async fn stat(client: &MemcachedClient, name: &str) -> i64 {
    match client.stats().await {
        Ok(stats) => {
            stats.iter().find(|(n, _)| n == name).map_or(0, |(_, v)| php_std::format::str_to_int(v.as_bytes()))
        }
        Err(_) => 0,
    }
}

/// Entries in Hazelcast through its Memcached endpoint: one item per key
/// holding an [`Envelope`] (JSON by default), as the Redis adapters store
/// it. Hazelcast cannot flush, so [`Adapter::flush`] returns `false`.
///
/// ```no_run
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Hazelcast, MemcachedClient};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let cache = Hazelcast::new(MemcachedClient::new("localhost", 5701));
/// cache.save("greeting", &Value::from("hello"), "", 0).await?;
/// assert_eq!(cache.load("greeting", 60, "").await?, Some(Value::from("hello")));
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
pub struct Hazelcast<K = Json> {
    client: MemcachedClient,
    envelope: Envelope<K>,
    retry: Retry,
}

impl Hazelcast<Json> {
    /// Entries through `client`, stored as JSON.
    pub fn new(client: MemcachedClient) -> Self {
        Self::with_codec(client, Json)
    }
}

impl<K: Codec> Hazelcast<K> {
    /// Entries through `client`, stored with `codec`.
    pub fn with_codec(client: MemcachedClient, codec: K) -> Self {
        Hazelcast { client, envelope: Envelope::new(codec), retry: Retry::default() }
    }

    /// Retries connection failures up to `retries` times (clamped to `0..=10`, `setMaxRetries`).
    pub fn with_max_retries(mut self, retries: i64) -> Self {
        self.retry = self.retry.with_max_retries(retries);
        self
    }

    /// Waits `delay_ms` milliseconds between attempts (`setRetryDelay`).
    pub fn with_retry_delay(mut self, delay_ms: i64) -> Self {
        self.retry = self.retry.with_delay_ms(delay_ms);
        self
    }

    /// The retry settings (`getMaxRetries`, `getRetryDelay`).
    pub fn retry(&self) -> Retry {
        self.retry
    }

    async fn stored(&self, key: &str) -> Result<Option<Vec<u8>>> {
        let item = execute!(self.retry, "Hazelcast", self.client.get(key).await)?;
        Ok(match item.and_then(|(flags, bytes)| unpack(flags, bytes)) {
            Some(Value::Str(s)) => Some(s.as_bytes().to_vec()),
            _ => None,
        })
    }

    async fn put(&self, key: &str, value: Vec<u8>) -> Result<bool> {
        let (flags, bytes) = pack(&Value::Str(value.into()));
        execute!(self.retry, "Hazelcast", self.client.set(key, flags, &bytes).await)
    }
}

impl<K: Codec> Adapter for Hazelcast<K> {
    async fn load(&self, key: &str, ttl: i64, _hash: &str) -> Result<Option<Value>> {
        Ok(self.stored(key).await?.and_then(|stored| self.envelope.decode(&stored, ttl, now())))
    }

    async fn save(&self, key: &str, data: &Value, _hash: &str, _ttl: i64) -> Result<bool> {
        let data = coerce(data, "Utopia\\Cache\\Adapter\\Hazelcast::save(): Argument #2 ($data)")?;
        if blank(key) || empty(&data) {
            return Ok(false);
        }
        let Ok(value) = self.envelope.encode(&data, now()) else { return Ok(false) };
        self.put(key, value).await
    }

    async fn touch(&self, key: &str, _hash: &str) -> Result<bool> {
        let Some(stored) = self.stored(key).await? else { return Ok(false) };
        let Some(value) = self.envelope.touch(&stored, now()) else { return Ok(false) };
        self.put(key, value).await
    }

    async fn list(&self, _key: &str) -> Result<Vec<String>> {
        Ok(Vec::new())
    }

    async fn purge(&self, key: &str, _hash: &str) -> Result<bool> {
        execute!(self.retry, "Hazelcast", self.client.delete(key).await)
    }

    async fn flush(&self) -> Result<bool> {
        Ok(false)
    }

    async fn ping(&self) -> Result<bool> {
        Ok(true)
    }

    async fn size(&self) -> Result<i64> {
        Ok(stat(&self.client, "total_items").await)
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "hazelcast"
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn packs_like_the_extension() {
        let mut record = Array::new();
        record.set(ArrayKey::from("time"), Value::Int(1));
        record.set(ArrayKey::from("data"), Value::from("x"));
        let (flags, bytes) = pack(&Value::Array(record.clone()));
        assert_eq!(flags, IS_SERIALIZED);
        assert_eq!(bytes, br#"a:2:{s:4:"time";i:1;s:4:"data";s:1:"x";}"#);
        assert_eq!(unpack(flags, bytes), Some(Value::Array(record)));

        let large = Value::from("abcdefgh".repeat(400).as_str());
        let (flags, bytes) = pack(&large);
        assert_eq!(flags, COMPRESSED | COMPRESSION_FASTLZ);
        assert_eq!(&bytes[..4], &3200u32.to_le_bytes());
        assert_eq!(unpack(flags, bytes), Some(large));
    }
}
