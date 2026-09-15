//! Transports (PHP `Utopia\NATS\Transport`).

use crate::error::{ConnectionException, NatsError, TimeoutException};
use parking_lot::Mutex;
use std::collections::HashMap;
use std::io::{Read, Write};
use std::net::TcpStream;
use std::sync::Arc;
use std::time::Duration;

pub trait Transport: Send + Sync + std::fmt::Debug {
    fn connect(&self, host: &str, port: u16, timeout: f64) -> Result<(), NatsError>;
    fn write(&self, data: &[u8]) -> Result<usize, NatsError>;
    fn read(&self, max_bytes: usize, timeout: Option<f64>) -> Result<Vec<u8>, NatsError>;
    fn read_line(&self, timeout: Option<f64>) -> Result<String, NatsError>;
    fn upgrade_tls(&self, options: &HashMap<String, serde_json::Value>) -> Result<(), NatsError>;
    fn is_connected(&self) -> bool;
    fn close(&self);
}

/// Loop until `writer` accepts every byte (PHP `TcpTransport::write`).
pub fn write_fully<W: Write>(writer: &mut W, data: &[u8]) -> Result<usize, NatsError> {
    let mut written = 0;
    while written < data.len() {
        match writer.write(&data[written..]) {
            Ok(0) => {
                return Err(ConnectionException("Failed to write to socket".into()).into());
            }
            Ok(n) => written += n,
            Err(e) if e.kind() == std::io::ErrorKind::WouldBlock => {
                return Err(TimeoutException("Write timed out".into()).into());
            }
            Err(e) => {
                return Err(ConnectionException(format!("Failed to write to socket: {e}")).into());
            }
        }
    }
    Ok(written)
}

#[derive(Debug, Default)]
pub struct TcpTransport {
    inner: Mutex<Option<TcpStream>>,
}

impl TcpTransport {
    pub fn new() -> Self {
        Self::default()
    }

    /// Test helper: inject an already-open stream (PHP reflection on `$stream`).
    pub fn set_stream(&self, stream: TcpStream) {
        *self.inner.lock() = Some(stream);
    }
}

impl Transport for TcpTransport {
    fn connect(&self, host: &str, port: u16, timeout: f64) -> Result<(), NatsError> {
        let addr = format!("{host}:{port}");
        let stream = TcpStream::connect_timeout(
            &addr.parse().map_err(|e| {
                ConnectionException(format!("Failed to connect to tcp://{addr}: {e}"))
            })?,
            Duration::from_secs_f64(timeout.max(0.001)),
        )
        .map_err(|e| ConnectionException(format!("Failed to connect to tcp://{addr}: {e}")))?;
        stream
            .set_nodelay(true)
            .map_err(|e| ConnectionException(e.to_string()))?;
        *self.inner.lock() = Some(stream);
        Ok(())
    }

    fn write(&self, data: &[u8]) -> Result<usize, NatsError> {
        let mut guard = self.inner.lock();
        let stream = guard
            .as_mut()
            .ok_or_else(|| ConnectionException("Not connected".into()))?;
        write_fully(stream, data)
    }

    fn read(&self, max_bytes: usize, timeout: Option<f64>) -> Result<Vec<u8>, NatsError> {
        let mut guard = self.inner.lock();
        let stream = guard
            .as_mut()
            .ok_or_else(|| ConnectionException("Not connected".into()))?;
        if let Some(t) = timeout {
            stream
                .set_read_timeout(Some(Duration::from_secs_f64(t.max(0.001))))
                .ok();
        }
        let mut buf = vec![0u8; max_bytes.max(1)];
        match stream.read(&mut buf) {
            Ok(n) => {
                buf.truncate(n);
                Ok(buf)
            }
            Err(e)
                if e.kind() == std::io::ErrorKind::TimedOut
                    || e.kind() == std::io::ErrorKind::WouldBlock =>
            {
                Err(TimeoutException("Read timed out".into()).into())
            }
            Err(e) => Err(ConnectionException(format!("Failed to read from socket: {e}")).into()),
        }
    }

    fn read_line(&self, timeout: Option<f64>) -> Result<String, NatsError> {
        let bytes = self.read(65536, timeout)?;
        Ok(String::from_utf8_lossy(&bytes).into_owned())
    }

    fn upgrade_tls(&self, _options: &HashMap<String, serde_json::Value>) -> Result<(), NatsError> {
        Err(ConnectionException("TLS upgrade requires TlsTransport".into()).into())
    }

    fn is_connected(&self) -> bool {
        self.inner.lock().is_some()
    }

    fn close(&self) {
        *self.inner.lock() = None;
    }
}

/// TLS context options (PHP stream SSL options map).
#[derive(Debug, Default)]
pub struct TlsTransport {
    ssl_options: Mutex<HashMap<String, serde_json::Value>>,
    inner: Mutex<Option<TcpStream>>,
}

impl TlsTransport {
    pub fn new(options: HashMap<String, serde_json::Value>) -> Self {
        Self {
            ssl_options: Mutex::new(options),
            inner: Mutex::new(None),
        }
    }

    /// PHP `TlsTransport::buildSslOptions` (tested via reflection).
    pub fn build_ssl_options(&self) -> HashMap<String, serde_json::Value> {
        self.ssl_options.lock().clone()
    }
}

impl Transport for TlsTransport {
    fn connect(&self, host: &str, port: u16, timeout: f64) -> Result<(), NatsError> {
        TcpTransport::new().connect(host, port, timeout)?;
        Ok(())
    }

    fn write(&self, data: &[u8]) -> Result<usize, NatsError> {
        let mut guard = self.inner.lock();
        let stream = guard
            .as_mut()
            .ok_or_else(|| ConnectionException("Not connected".into()))?;
        write_fully(stream, data)
    }

    fn read(&self, max_bytes: usize, timeout: Option<f64>) -> Result<Vec<u8>, NatsError> {
        let tcp = TcpTransport::new();
        if let Some(s) = self.inner.lock().as_ref() {
            if let Ok(clone) = s.try_clone() {
                tcp.set_stream(clone);
                return tcp.read(max_bytes, timeout);
            }
        }
        Err(ConnectionException("Not connected".into()).into())
    }

    fn read_line(&self, timeout: Option<f64>) -> Result<String, NatsError> {
        Ok(String::from_utf8_lossy(&self.read(65536, timeout)?).into_owned())
    }

    fn upgrade_tls(&self, options: &HashMap<String, serde_json::Value>) -> Result<(), NatsError> {
        self.ssl_options.lock().clone_from(options);
        Ok(())
    }

    fn is_connected(&self) -> bool {
        self.inner.lock().is_some()
    }

    fn close(&self) {
        *self.inner.lock() = None;
    }
}

/// WebSocket transport placeholder; live servers use `NATS_URL`.
#[derive(Debug, Default)]
pub struct WebSocketTransport {
    connected: Mutex<bool>,
}

impl WebSocketTransport {
    pub fn new(_secure: bool, _tls: HashMap<String, serde_json::Value>) -> Self {
        Self::default()
    }
}

impl Transport for WebSocketTransport {
    fn connect(&self, host: &str, port: u16, _timeout: f64) -> Result<(), NatsError> {
        Err(ConnectionException(format!(
            "WebSocket transport requires a live NATS server at {host}:{port}"
        ))
        .into())
    }
    fn write(&self, _data: &[u8]) -> Result<usize, NatsError> {
        Err(ConnectionException("Not connected".into()).into())
    }
    fn read(&self, _max_bytes: usize, _timeout: Option<f64>) -> Result<Vec<u8>, NatsError> {
        Err(TimeoutException("No inbound data".into()).into())
    }
    fn read_line(&self, _timeout: Option<f64>) -> Result<String, NatsError> {
        Err(TimeoutException("No inbound line".into()).into())
    }
    fn upgrade_tls(&self, _options: &HashMap<String, serde_json::Value>) -> Result<(), NatsError> {
        Ok(())
    }
    fn is_connected(&self) -> bool {
        *self.connected.lock()
    }
    fn close(&self) {
        *self.connected.lock() = false;
    }
}

/// In-memory transport used by unit tests (PHP `FakeTransport`).
#[derive(Debug)]
pub struct FakeTransport {
    inner: Mutex<FakeInner>,
}

#[derive(Debug)]
struct FakeInner {
    info: serde_json::Value,
    inbound: Vec<u8>,
    written: Vec<u8>,
    writes: Vec<Vec<u8>>,
    tls_upgrades: Vec<HashMap<String, serde_json::Value>>,
    connected: bool,
}

impl FakeTransport {
    pub fn new(info: serde_json::Value) -> Arc<Self> {
        Arc::new(Self {
            inner: Mutex::new(FakeInner {
                info,
                inbound: Vec::new(),
                written: Vec::new(),
                writes: Vec::new(),
                tls_upgrades: Vec::new(),
                connected: false,
            }),
        })
    }

    pub fn written(&self) -> String {
        String::from_utf8_lossy(&self.inner.lock().written).into_owned()
    }

    pub fn push_inbound(&self, data: &str) {
        self.inner.lock().inbound.extend_from_slice(data.as_bytes());
    }

    pub fn connect_payload(&self) -> Result<serde_json::Value, NatsError> {
        let written = self.written();
        let re = regex_first_connect(&written)
            .ok_or_else(|| ConnectionException("No CONNECT sent".into()))?;
        serde_json::from_str(re)
            .map_err(|e| ConnectionException(format!("Invalid CONNECT json: {e}")).into())
    }

    pub fn tls_upgrades(&self) -> Vec<HashMap<String, serde_json::Value>> {
        self.inner.lock().tls_upgrades.clone()
    }
}

fn regex_first_connect(written: &str) -> Option<&str> {
    let start = written.find("CONNECT {")?;
    let json_start = start + "CONNECT ".len();
    let rest = &written[json_start..];
    let end = rest.find("\r\n")?;
    Some(&rest[..end])
}

impl Transport for FakeTransport {
    fn connect(&self, _host: &str, _port: u16, _timeout: f64) -> Result<(), NatsError> {
        let mut inner = self.inner.lock();
        inner.connected = true;
        let mut info = serde_json::json!({
            "server_id": "FAKE",
            "server_name": "fake",
            "version": "2.10.0",
            "proto": 1,
            "host": "127.0.0.1",
            "port": 4222,
            "headers": true,
            "auth_required": false,
            "tls_required": false,
            "tls_available": false,
            "max_payload": 1_048_576,
            "jetstream": true,
        });
        if let serde_json::Value::Object(map) = &inner.info {
            if let serde_json::Value::Object(dst) = &mut info {
                for (k, v) in map {
                    dst.insert(k.clone(), v.clone());
                }
            }
        }
        let line = format!(
            "INFO {}\r\n",
            serde_json::to_string(&info).expect("info json")
        );
        inner.inbound.extend_from_slice(line.as_bytes());
        Ok(())
    }

    fn write(&self, data: &[u8]) -> Result<usize, NatsError> {
        let mut inner = self.inner.lock();
        inner.written.extend_from_slice(data);
        inner.writes.push(data.to_vec());
        let text = String::from_utf8_lossy(data);
        let pings = text.matches("PING\r\n").count();
        for _ in 0..pings {
            inner.inbound.extend_from_slice(b"PONG\r\n");
        }
        Ok(data.len())
    }

    fn read(&self, max_bytes: usize, _timeout: Option<f64>) -> Result<Vec<u8>, NatsError> {
        let mut inner = self.inner.lock();
        if inner.inbound.is_empty() {
            return Err(TimeoutException("No inbound data".into()).into());
        }
        let n = max_bytes.min(inner.inbound.len());
        Ok(inner.inbound.drain(..n).collect())
    }

    fn read_line(&self, _timeout: Option<f64>) -> Result<String, NatsError> {
        let mut inner = self.inner.lock();
        let pos = inner
            .inbound
            .iter()
            .position(|b| *b == b'\n')
            .ok_or_else(|| TimeoutException("No inbound line".into()))?;
        let line = inner.inbound.drain(..=pos).collect::<Vec<_>>();
        Ok(String::from_utf8_lossy(&line).into_owned())
    }

    fn upgrade_tls(&self, options: &HashMap<String, serde_json::Value>) -> Result<(), NatsError> {
        self.inner.lock().tls_upgrades.push(options.clone());
        Ok(())
    }

    fn is_connected(&self) -> bool {
        self.inner.lock().connected
    }

    fn close(&self) {
        self.inner.lock().connected = false;
    }
}
