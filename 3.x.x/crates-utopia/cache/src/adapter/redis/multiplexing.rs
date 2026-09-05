use std::io::{Read, Write};
use std::net::{TcpStream, ToSocketAddrs};
use std::sync::atomic::{AtomicI32, Ordering};
use std::sync::Arc;
use std::time::Duration;

use parking_lot::Mutex;
use utopia_telemetry::adapters::NoneAdapter;
use utopia_telemetry::{Adapter as TelemetryAdapter, UpDownCounter};

use super::client::Client;
use super::envelope::Envelope;
use super::leasable::{self, effective_hash, is_reserved, LeaseReply, LeaseTransport};
use super::noscript::NoScript;
use super::types::{ParseOutcome, RespValue};
use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::feature::{Leasable, Telemetry};
use crate::value::{is_empty_key, unix_now, CacheValue, LoadResult, SaveResult};

/// PHP `Utopia\Cache\Adapter\Redis\Multiplexing`.
///
/// Swoole coroutine multiplexing is not available; this adapter multiplexes
/// callers over one TCP connection with a mutex (in-order RESP).
pub struct Multiplexing {
    host: String,
    port: u16,
    timeout: Duration,
    read_timeout: Duration,
    auth: Option<RedisAuth>,
    db_index: i64,
    stream: Mutex<Option<TcpStream>>,
    buffer: Mutex<Vec<u8>>,
    lease_grace_window: AtomicI32,
    telemetry: Mutex<Arc<dyn TelemetryAdapter>>,
    pending_depth: Mutex<Option<Arc<dyn UpDownCounter>>>,
}

#[derive(Debug, Clone)]
enum RedisAuth {
    Password(String),
    Acl { username: String, password: String },
}

impl std::fmt::Debug for Multiplexing {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Multiplexing")
            .field("host", &self.host)
            .field("port", &self.port)
            .finish_non_exhaustive()
    }
}

impl Multiplexing {
    /// PHP `__construct($host, $port = 6379, $timeout = 1.0, $readTimeout = 0.25, $auth = null, $dbIndex = 0)`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        host: impl Into<String>,
        port: u16,
        timeout: f64,
        read_timeout: f64,
        auth: Option<String>,
        db_index: i64,
    ) -> Result<Self, CacheError> {
        if timeout <= 0.0 {
            return Err(CacheError::TimeoutMustBePositive);
        }
        if read_timeout <= 0.0 {
            return Err(CacheError::ReadTimeoutMustBePositive);
        }
        let this = Self {
            host: host.into(),
            port,
            timeout: Duration::from_secs_f64(timeout),
            read_timeout: Duration::from_secs_f64(read_timeout),
            auth: auth.map(RedisAuth::Password),
            db_index,
            stream: Mutex::new(None),
            buffer: Mutex::new(Vec::new()),
            lease_grace_window: AtomicI32::new(0),
            telemetry: Mutex::new(Arc::new(NoneAdapter::new())),
            pending_depth: Mutex::new(None),
        };
        this.connect()?;
        Ok(this)
    }

    pub fn connect_host(host: impl Into<String>, port: u16) -> Result<Self, CacheError> {
        Self::new(host, port, 1.0, 0.25, None, 0)
    }

    pub fn with_acl(mut self, username: impl Into<String>, password: impl Into<String>) -> Self {
        self.auth = Some(RedisAuth::Acl {
            username: username.into(),
            password: password.into(),
        });
        self
    }

    pub fn set_lease_grace_window(&self, milliseconds: i32) -> &Self {
        self.lease_grace_window
            .store(milliseconds.max(0), Ordering::SeqCst);
        self
    }

    #[must_use]
    pub fn get_lease_grace_window(&self) -> i32 {
        self.lease_grace_window.load(Ordering::SeqCst)
    }

    /// PHP `disconnect()`.
    pub fn disconnect(&self) {
        *self.stream.lock() = None;
        self.buffer.lock().clear();
    }

    fn connect(&self) -> Result<(), CacheError> {
        let addr = (self.host.as_str(), self.port)
            .to_socket_addrs()?
            .next()
            .ok_or_else(|| CacheError::RedisConnect("no address".into()))?;
        let stream = TcpStream::connect_timeout(&addr, self.timeout)
            .map_err(|e| CacheError::RedisConnect(e.to_string()))?;
        stream.set_read_timeout(Some(self.read_timeout))?;
        stream.set_write_timeout(Some(self.timeout))?;
        stream.set_nodelay(true)?;
        *self.stream.lock() = Some(stream);
        self.buffer.lock().clear();

        if let Some(auth) = &self.auth {
            let args: Vec<String> = match auth {
                RedisAuth::Password(p) => vec!["AUTH".into(), p.clone()],
                RedisAuth::Acl { username, password } => {
                    vec!["AUTH".into(), username.clone(), password.clone()]
                }
            };
            match self.dispatch(&args)? {
                RespValue::Simple(s) if s == "OK" => {}
                _ => return Err(CacheError::Redis("Redis AUTH failed".into())),
            }
        }
        if self.db_index != 0 {
            match self.dispatch(&["SELECT".into(), self.db_index.to_string()])? {
                RespValue::Simple(s) if s == "OK" => {}
                _ => return Err(CacheError::Redis("Redis SELECT failed".into())),
            }
        }
        Ok(())
    }

    fn get_pending_depth(&self) -> Arc<dyn UpDownCounter> {
        let mut slot = self.pending_depth.lock();
        if let Some(c) = slot.as_ref() {
            return Arc::clone(c);
        }
        let counter = self.telemetry.lock().create_up_down_counter(
            "cache.redis_multiplexing.pending.depth",
            None,
            Some("Pending response channels awaiting RESP frames on the multiplexed connection."),
            std::collections::HashMap::new(),
        );
        *slot = Some(Arc::clone(&counter));
        counter
    }

    fn command(&self, args: &[String]) -> Result<RespValue, CacheError> {
        match self.dispatch(args) {
            Err(
                CacheError::Connection(_)
                | CacheError::RedisTimeout
                | CacheError::RedisNotOpen
                | CacheError::RedisClosed
                | CacheError::RedisConnect(_),
            ) => {
                self.connect()?;
                self.dispatch(args)
            }
            other => other,
        }
    }

    fn dispatch(&self, args: &[String]) -> Result<RespValue, CacheError> {
        let payload = Client::encode(&args.iter().map(String::as_bytes).collect::<Vec<_>>());
        self.get_pending_depth()
            .add(1.0, &std::collections::HashMap::new());
        {
            let mut guard = self.stream.lock();
            let stream = guard.as_mut().ok_or(CacheError::RedisNotOpen)?;
            stream
                .write_all(&payload)
                .map_err(|e| CacheError::RedisSend(e.to_string()))?;
        }
        let value = self.read_frame()?;
        self.get_pending_depth()
            .add(-1.0, &std::collections::HashMap::new());
        Client::unwrap_value(value)
    }

    fn read_frame(&self) -> Result<RespValue, CacheError> {
        let mut stream_guard = self.stream.lock();
        let stream = stream_guard.as_mut().ok_or(CacheError::RedisNotOpen)?;
        let mut buffer = self.buffer.lock();
        loop {
            let mut offset = 0usize;
            match Client::parse_bytes(&buffer, &mut offset)? {
                ParseOutcome::Value(v) => {
                    buffer.drain(..offset);
                    return Ok(v);
                }
                ParseOutcome::Incomplete => {}
            }
            let mut chunk = [0u8; 8192];
            let n = stream.read(&mut chunk).map_err(CacheError::from)?;
            if n == 0 {
                return Err(CacheError::RedisClosed);
            }
            buffer.extend_from_slice(&chunk[..n]);
        }
    }

    fn resp_to_string(value: &RespValue) -> Option<String> {
        match value {
            RespValue::Simple(s) | RespValue::Bulk(s) => Some(s.clone()),
            _ => None,
        }
    }

    fn resp_truthy(value: &RespValue) -> bool {
        !matches!(
            value,
            RespValue::Nil
                | RespValue::Integer(0)
                | RespValue::RedisError(_)
                | RespValue::ConnectionError(_)
        )
    }
}

impl Adapter for Multiplexing {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError> {
        let hash = effective_hash(key, hash);
        if is_reserved(hash) {
            return Ok(LoadResult::Miss);
        }
        let value = self.command(&["HGET".into(), key.into(), hash.into()])?;
        let Some(raw) = Self::resp_to_string(&value) else {
            return Ok(LoadResult::Miss);
        };
        Ok(Envelope::decode(&raw, ttl, unix_now()).map_or(LoadResult::Miss, LoadResult::Hit))
    }

    fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError> {
        if is_empty_key(key) || data.is_php_empty() {
            return Ok(SaveResult::Failed);
        }
        let hash = effective_hash(key, hash);
        if is_reserved(hash) {
            return Ok(SaveResult::Failed);
        }
        let value =
            Envelope::encode(data, unix_now()).map_err(|e| CacheError::message(e.to_string()))?;
        self.command(&["HSET".into(), key.into(), hash.into(), value])?;
        Ok(SaveResult::Saved(data.clone()))
    }

    fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        let hash = effective_hash(key, hash);
        if is_reserved(hash) {
            return Ok(false);
        }
        let value = self.command(&["HGET".into(), key.into(), hash.into()])?;
        let Some(raw) = Self::resp_to_string(&value) else {
            return Ok(false);
        };
        let Some(payload) = Envelope::touch(&raw, unix_now()) else {
            return Ok(false);
        };
        let result = self.command(&["HSET".into(), key.into(), hash.into(), payload])?;
        Ok(Self::resp_truthy(&result))
    }

    fn list(&self, key: &str) -> Result<Vec<String>, CacheError> {
        match self.command(&["HKEYS".into(), key.into()])? {
            RespValue::Array(items) => Ok(items
                .into_iter()
                .filter_map(|v| Self::resp_to_string(&v))
                .filter(|f| !is_reserved(f))
                .collect()),
            _ => Ok(Vec::new()),
        }
    }

    fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        leasable::purge(self, key, hash, self.get_lease_grace_window())
    }

    fn flush(&self) -> Result<bool, CacheError> {
        Ok(matches!(
            self.command(&["FLUSHDB".into()])?,
            RespValue::Simple(s) if s == "OK"
        ))
    }

    fn ping(&self) -> bool {
        matches!(
            self.command(&["PING".into()]),
            Ok(RespValue::Simple(s)) if s == "PONG"
        )
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        match self.command(&["DBSIZE".into()])? {
            RespValue::Integer(n) => Ok(n),
            _ => Ok(0),
        }
    }

    fn get_name(&self, _key: Option<&str>) -> String {
        "redis-multiplexing".into()
    }

    fn as_leasable(&self) -> Option<&dyn Leasable> {
        Some(self)
    }

    fn as_telemetry_mut(&mut self) -> Option<&mut dyn Telemetry> {
        Some(self)
    }
}

impl Leasable for Multiplexing {
    fn get_generation(&self, key: &str) -> Result<String, CacheError> {
        leasable::get_generation(self, key)
    }

    fn save_with_lease(
        &self,
        key: &str,
        data: &CacheValue,
        hash: &str,
        generation: &str,
    ) -> Result<SaveResult, CacheError> {
        leasable::save_with_lease(
            self,
            key,
            data,
            hash,
            generation,
            self.get_lease_grace_window(),
        )
    }
}

impl Telemetry for Multiplexing {
    fn set_telemetry(&mut self, telemetry: Arc<dyn TelemetryAdapter>) {
        *self.telemetry.lock() = telemetry;
        *self.pending_depth.lock() = None;
    }
}

impl LeaseTransport for Multiplexing {
    fn lease_eval_sha(
        &self,
        sha: &str,
        key: &str,
        args: &[String],
    ) -> Result<LeaseReply, CacheError> {
        let mut cmd = vec!["EVALSHA".into(), sha.into(), "1".into(), key.into()];
        cmd.extend(args.iter().cloned());
        match self.command(&cmd) {
            Err(err) if NoScript::matches(&err.to_string()) => Err(err),
            Ok(RespValue::Integer(n)) => Ok(LeaseReply::Int(n)),
            Ok(RespValue::RedisError(msg)) if NoScript::matches(&msg) => {
                Err(CacheError::Redis(msg))
            }
            Ok(_) => Ok(LeaseReply::Other),
            Err(err) => Err(err),
        }
    }

    fn lease_eval(
        &self,
        script: &str,
        key: &str,
        args: &[String],
    ) -> Result<LeaseReply, CacheError> {
        let mut cmd = vec!["EVAL".into(), script.into(), "1".into(), key.into()];
        cmd.extend(args.iter().cloned());
        match self.command(&cmd)? {
            RespValue::Integer(n) => Ok(LeaseReply::Int(n)),
            _ => Ok(LeaseReply::Other),
        }
    }

    fn lease_hget(&self, key: &str, field: &str) -> Result<Option<String>, CacheError> {
        match self.command(&["HGET".into(), key.into(), field.into()])? {
            RespValue::Nil => Ok(None),
            other => Ok(Self::resp_to_string(&other)),
        }
    }
}
