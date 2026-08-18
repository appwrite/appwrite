use std::io::{Read, Write};
use std::net::{TcpStream, ToSocketAddrs};
use std::thread;
use std::time::Duration;

use parking_lot::Mutex;

use super::Json;
use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::feature::{clamp_retries, Retryable};
use crate::value::{is_empty_key, unix_now, CacheValue, LoadResult, SaveResult};

/// Text-protocol memcached client used by [`Memcached`] and [`super::Hazelcast`].
#[derive(Debug)]
pub struct MemcacheConn {
    host: String,
    port: u16,
    stream: Mutex<Option<TcpStream>>,
}

impl MemcacheConn {
    pub fn connect(host: impl Into<String>, port: u16) -> Result<Self, CacheError> {
        let host = host.into();
        let this = Self {
            host,
            port,
            stream: Mutex::new(None),
        };
        this.ensure()?;
        Ok(this)
    }

    fn ensure(&self) -> Result<(), CacheError> {
        if self.stream.lock().is_some() {
            return Ok(());
        }
        let addr = (self.host.as_str(), self.port)
            .to_socket_addrs()?
            .next()
            .ok_or_else(|| CacheError::message("no memcached address"))?;
        let stream = TcpStream::connect_timeout(&addr, Duration::from_secs(2))?;
        stream.set_nodelay(true)?;
        stream.set_read_timeout(Some(Duration::from_secs(2)))?;
        stream.set_write_timeout(Some(Duration::from_secs(2)))?;
        *self.stream.lock() = Some(stream);
        Ok(())
    }

    fn request(&self, payload: &[u8]) -> Result<Vec<u8>, CacheError> {
        self.ensure()?;
        let mut guard = self.stream.lock();
        let stream = guard
            .as_mut()
            .ok_or_else(|| CacheError::message("not connected"))?;
        stream.write_all(payload)?;
        let mut buf = Vec::new();
        let mut tmp = [0u8; 4096];
        loop {
            let n = stream.read(&mut tmp)?;
            if n == 0 {
                break;
            }
            buf.extend_from_slice(&tmp[..n]);
            if looks_complete(&buf) {
                break;
            }
        }
        Ok(buf)
    }

    pub fn get(&self, key: &str) -> Result<Option<String>, CacheError> {
        let req = format!("get {key}\r\n");
        let resp = self.request(req.as_bytes())?;
        Ok(parse_get(&resp))
    }

    pub fn set(&self, key: &str, value: &str) -> Result<bool, CacheError> {
        let req = format!("set {key} 0 0 {}\r\n{}\r\n", value.len(), value);
        let resp = String::from_utf8_lossy(&self.request(req.as_bytes())?).into_owned();
        Ok(resp.starts_with("STORED"))
    }

    pub fn delete(&self, key: &str) -> Result<bool, CacheError> {
        let req = format!("delete {key}\r\n");
        let resp = String::from_utf8_lossy(&self.request(req.as_bytes())?).into_owned();
        Ok(resp.starts_with("DELETED"))
    }

    pub fn flush_all(&self) -> Result<bool, CacheError> {
        let resp = String::from_utf8_lossy(&self.request(b"flush_all\r\n")?).into_owned();
        Ok(resp.starts_with("OK"))
    }

    pub fn stats(&self) -> Result<std::collections::HashMap<String, String>, CacheError> {
        let resp = String::from_utf8_lossy(&self.request(b"stats\r\n")?).into_owned();
        let mut map = std::collections::HashMap::new();
        for line in resp.lines() {
            if let Some(rest) = line.strip_prefix("STAT ") {
                if let Some((k, v)) = rest.split_once(' ') {
                    map.insert(k.to_owned(), v.to_owned());
                }
            }
        }
        Ok(map)
    }

    #[must_use]
    pub fn host_port(&self) -> String {
        format!("{}:{}", self.host, self.port)
    }
}

fn looks_complete(buf: &[u8]) -> bool {
    buf.windows(5).any(|w| w == b"END\r\n")
        || buf.windows(8).any(|w| w == b"STORED\r\n")
        || buf.windows(9).any(|w| w == b"DELETED\r\n")
        || buf.windows(12).any(|w| w == b"NOT_FOUND\r\n")
        || buf.windows(5).any(|w| w == b"OK\r\n")
        || buf.windows(7).any(|w| w == b"ERROR\r\n")
}

fn parse_get(resp: &[u8]) -> Option<String> {
    let text = String::from_utf8_lossy(resp);
    if text.starts_with("END") {
        return None;
    }
    let header_end = text.find("\r\n")?;
    let header = &text[..header_end];
    if !header.starts_with("VALUE ") {
        return None;
    }
    let parts: Vec<&str> = header.split_whitespace().collect();
    let bytes: usize = parts.get(3).and_then(|s| s.parse().ok()).unwrap_or(0);
    let data_start = header_end + 2;
    text.get(data_start..data_start + bytes).map(str::to_owned)
}

fn is_conn_fail(err: &CacheError) -> bool {
    let m = err.to_string().to_lowercase();
    m.contains("connection")
        || m.contains("broken pipe")
        || m.contains("timed out")
        || m.contains("reset")
        || m.contains("refused")
}

fn retry<T>(
    max_retries: i32,
    retry_delay: i32,
    kind: &str,
    mut f: impl FnMut() -> Result<T, CacheError>,
) -> Result<T, CacheError> {
    let max_attempts = 1 + max_retries;
    let mut attempts = 0;
    loop {
        match f() {
            Ok(v) => return Ok(v),
            Err(err) if is_conn_fail(&err) => {
                attempts += 1;
                if attempts >= max_attempts {
                    return if kind == "hazelcast" {
                        Err(CacheError::Hazelcast {
                            attempts: attempts as usize,
                            error: err.to_string(),
                        })
                    } else {
                        Err(CacheError::Memcached {
                            attempts: attempts as usize,
                            error: err.to_string(),
                        })
                    };
                }
                thread::sleep(Duration::from_millis(retry_delay.max(0) as u64));
            }
            Err(err) => return Err(err),
        }
    }
}

/// PHP `Utopia\Cache\Adapter\Memcached`.
pub struct Memcached {
    conn: MemcacheConn,
    max_retries: i32,
    retry_delay: i32,
}

impl std::fmt::Debug for Memcached {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Memcached")
            .field("server", &self.conn.host_port())
            .finish_non_exhaustive()
    }
}

impl Memcached {
    pub fn connect(host: impl Into<String>, port: u16) -> Result<Self, CacheError> {
        Ok(Self {
            conn: MemcacheConn::connect(host, port)?,
            max_retries: 0,
            retry_delay: 1000,
        })
    }

    fn execute<T>(&self, f: impl Fn() -> Result<T, CacheError>) -> Result<T, CacheError> {
        retry(self.max_retries, self.retry_delay, "memcached", f)
    }
}

impl Retryable for Memcached {
    fn set_max_retries(&mut self, max_retries: i32) -> &mut Self {
        self.max_retries = clamp_retries(max_retries);
        self
    }

    fn set_retry_delay(&mut self, retry_delay: i32) -> &mut Self {
        self.retry_delay = retry_delay;
        self
    }

    fn get_max_retries(&self) -> i32 {
        self.max_retries
    }

    fn get_retry_delay(&self) -> i32 {
        self.retry_delay
    }
}

impl Adapter for Memcached {
    fn load(&self, key: &str, ttl: i64, _hash: &str) -> Result<LoadResult, CacheError> {
        let cache = self.execute(|| self.conn.get(key))?;
        let Some(raw) = cache else {
            return Ok(LoadResult::Miss);
        };
        let Some(value) = Json::decode(&raw) else {
            return Ok(LoadResult::Miss);
        };
        let Some(obj) = value.as_object() else {
            return Ok(LoadResult::Miss);
        };
        let Some(time) = obj.get("time").and_then(serde_json::Value::as_i64) else {
            return Ok(LoadResult::Miss);
        };
        if time + ttl > unix_now() {
            Ok(LoadResult::Hit(CacheValue::from_json(
                obj.get("data").cloned().unwrap_or(serde_json::Value::Null),
            )))
        } else {
            Ok(LoadResult::Miss)
        }
    }

    fn save(&self, key: &str, data: &CacheValue, _hash: &str) -> Result<SaveResult, CacheError> {
        if is_empty_key(key) || data.is_php_empty() {
            return Ok(SaveResult::Failed);
        }
        let payload = match super::redis::Envelope::encode(data, unix_now()) {
            Ok(v) => v,
            Err(_) => return Ok(SaveResult::Failed),
        };
        Ok(if self.execute(|| self.conn.set(key, &payload))? {
            SaveResult::Saved(data.clone())
        } else {
            SaveResult::Failed
        })
    }

    fn touch(&self, key: &str, _hash: &str) -> Result<bool, CacheError> {
        let Some(raw) = self.execute(|| self.conn.get(key))? else {
            return Ok(false);
        };
        let Some(payload) = super::redis::Envelope::touch(&raw, unix_now()) else {
            return Ok(false);
        };
        self.execute(|| self.conn.set(key, &payload))
    }

    fn list(&self, _key: &str) -> Result<Vec<String>, CacheError> {
        Ok(Vec::new())
    }

    fn purge(&self, key: &str, _hash: &str) -> Result<bool, CacheError> {
        self.execute(|| self.conn.delete(key))
    }

    fn flush(&self) -> Result<bool, CacheError> {
        self.execute(|| self.conn.flush_all())
    }

    fn ping(&self) -> bool {
        self.conn.stats().map(|s| !s.is_empty()).unwrap_or(false)
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        let stats = self.conn.stats().unwrap_or_default();
        Ok(stats
            .get("curr_items")
            .and_then(|s| s.parse().ok())
            .unwrap_or(0))
    }

    fn get_name(&self, _key: Option<&str>) -> String {
        "memcached".into()
    }
}

/// PHP `Utopia\Cache\Adapter\Hazelcast` (Memcached protocol).
pub struct Hazelcast {
    conn: MemcacheConn,
    max_retries: i32,
    retry_delay: i32,
}

impl std::fmt::Debug for Hazelcast {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Hazelcast")
            .field("server", &self.conn.host_port())
            .finish_non_exhaustive()
    }
}

impl Hazelcast {
    pub fn connect(host: impl Into<String>, port: u16) -> Result<Self, CacheError> {
        Ok(Self {
            conn: MemcacheConn::connect(host, port)?,
            max_retries: 0,
            retry_delay: 1000,
        })
    }

    fn execute<T>(&self, f: impl Fn() -> Result<T, CacheError>) -> Result<T, CacheError> {
        retry(self.max_retries, self.retry_delay, "hazelcast", f)
    }
}

impl Retryable for Hazelcast {
    fn set_max_retries(&mut self, max_retries: i32) -> &mut Self {
        self.max_retries = clamp_retries(max_retries);
        self
    }

    fn set_retry_delay(&mut self, retry_delay: i32) -> &mut Self {
        self.retry_delay = retry_delay;
        self
    }

    fn get_max_retries(&self) -> i32 {
        self.max_retries
    }

    fn get_retry_delay(&self) -> i32 {
        self.retry_delay
    }
}

impl Adapter for Hazelcast {
    fn load(&self, key: &str, ttl: i64, _hash: &str) -> Result<LoadResult, CacheError> {
        let cache = self.execute(|| self.conn.get(key))?;
        let Some(raw) = cache else {
            return Ok(LoadResult::Miss);
        };
        let decoded = Json::decode(&raw).unwrap_or(serde_json::Value::Null);
        let Some(obj) = decoded.as_object() else {
            return Ok(LoadResult::Miss);
        };
        let Some(time) = obj.get("time").and_then(serde_json::Value::as_i64) else {
            return Ok(LoadResult::Miss);
        };
        if time + ttl > unix_now() {
            Ok(LoadResult::Hit(CacheValue::from_json(
                obj.get("data").cloned().unwrap_or(serde_json::Value::Null),
            )))
        } else {
            Ok(LoadResult::Miss)
        }
    }

    fn save(&self, key: &str, data: &CacheValue, _hash: &str) -> Result<SaveResult, CacheError> {
        if is_empty_key(key) || data.is_php_empty() {
            return Ok(SaveResult::Failed);
        }
        let payload = match super::redis::Envelope::encode(data, unix_now()) {
            Ok(v) => v,
            Err(_) => return Ok(SaveResult::Failed),
        };
        Ok(if self.execute(|| self.conn.set(key, &payload))? {
            SaveResult::Saved(data.clone())
        } else {
            SaveResult::Failed
        })
    }

    fn touch(&self, key: &str, _hash: &str) -> Result<bool, CacheError> {
        let Some(raw) = self.execute(|| self.conn.get(key))? else {
            return Ok(false);
        };
        let Some(payload) = super::redis::Envelope::touch(&raw, unix_now()) else {
            return Ok(false);
        };
        self.execute(|| self.conn.set(key, &payload))
    }

    fn list(&self, _key: &str) -> Result<Vec<String>, CacheError> {
        Ok(Vec::new())
    }

    fn purge(&self, key: &str, _hash: &str) -> Result<bool, CacheError> {
        self.execute(|| self.conn.delete(key))
    }

    fn flush(&self) -> Result<bool, CacheError> {
        Ok(false)
    }

    fn ping(&self) -> bool {
        self.conn.stats().is_ok()
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        let stats = self.conn.stats().unwrap_or_default();
        Ok(stats
            .get("total_items")
            .and_then(|s| s.parse().ok())
            .unwrap_or(0))
    }

    fn get_name(&self, _key: Option<&str>) -> String {
        "hazelcast".into()
    }
}
