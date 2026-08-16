//! HTTP transport for OTLP metric export.
//!
//! Rust equivalent of PHP `Utopia\Telemetry\Adapter\OpenTelemetry\Transport\Swoole`:
//! connection-pooled HTTP POST with keep-alive. Tokio/reqwest replaces Swoole
//! coroutines.

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::Duration;

use parking_lot::Mutex;
use reqwest::blocking::Client;
use reqwest::header::{
    HeaderMap, HeaderName, HeaderValue, CONNECTION, CONTENT_LENGTH, CONTENT_TYPE,
};

use crate::error::TelemetryError;
use crate::php_url::parse_url;

/// OTLP protobuf content type (PHP `OpenTelemetry\Contrib\Otlp\ContentTypes::PROTOBUF`).
pub const CONTENT_TYPE_PROTOBUF: &str = "application/x-protobuf";
/// OTLP JSON content type (PHP `ContentTypes::JSON`).
pub const CONTENT_TYPE_JSON: &str = "application/json";
/// OTLP NDJSON content type (PHP `ContentTypes::NDJSON`).
pub const CONTENT_TYPE_NDJSON: &str = "application/x-ndjson";

const DEFAULT_TIMEOUT: f64 = 10.0;
const DEFAULT_POOL_SIZE: usize = 8;
const DEFAULT_SOCKET_BUFFER: usize = 64 * 1024;

/// Backend that sends encoded OTLP payloads (PHP `TransportInterface`).
pub trait Transport: Send + Sync {
    fn content_type(&self) -> &str;
    fn send(&self, payload: &[u8]) -> Result<Vec<u8>, TelemetryError>;
    fn shutdown(&self) -> bool;
    fn force_flush(&self) -> bool;
}

/// Connection-pooled HTTP transport (PHP `Transport\Swoole`).
pub struct HttpTransport {
    endpoint: String,
    content_type: String,
    client: Mutex<Option<Client>>,
    shutdown: AtomicBool,
}

impl std::fmt::Debug for HttpTransport {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("HttpTransport")
            .field("endpoint", &self.endpoint)
            .field("content_type", &self.content_type)
            .field("shutdown", &self.shutdown.load(Ordering::SeqCst))
            .finish_non_exhaustive()
    }
}

impl HttpTransport {
    /// PHP `new Swoole($endpoint)` with protobuf content type.
    pub fn new(endpoint: impl AsRef<str>) -> Result<Self, TelemetryError> {
        Self::new_with(
            endpoint,
            CONTENT_TYPE_PROTOBUF,
            HashMap::new(),
            DEFAULT_TIMEOUT,
            DEFAULT_POOL_SIZE,
            DEFAULT_SOCKET_BUFFER,
        )
    }

    /// Full PHP constructor including content type, headers, timeout, pool size,
    /// and socket buffer size.
    pub fn new_with(
        endpoint: impl AsRef<str>,
        content_type: impl Into<String>,
        headers: HashMap<String, String>,
        timeout: f64,
        pool_size: usize,
        _socket_buffer_size: usize,
    ) -> Result<Self, TelemetryError> {
        let endpoint = endpoint.as_ref();
        let parsed = parse_url(endpoint)
            .ok_or_else(|| TelemetryError::InvalidEndpoint(endpoint.to_string()))?;
        let ssl = parsed.scheme.as_deref() == Some("https");
        let host = parsed.host.unwrap_or_else(|| "localhost".to_string());
        let port = parsed.port.unwrap_or(if ssl { 443 } else { 80 });
        let mut path = parsed.path.unwrap_or_else(|| "/".to_string());
        if let Some(query) = parsed.query {
            path.push('?');
            path.push_str(&query);
        }
        let scheme = if ssl { "https" } else { "http" };
        let url = format!("{scheme}://{host}:{port}{path}");

        let timeout = if timeout > 0.0 {
            timeout
        } else {
            DEFAULT_TIMEOUT
        };
        let connect_timeout = timeout.max(0.5);
        let pool_size = if pool_size == 0 {
            DEFAULT_POOL_SIZE
        } else {
            pool_size
        };

        let mut header_map = HeaderMap::new();
        header_map.insert(CONNECTION, HeaderValue::from_static("keep-alive"));
        for (key, value) in &headers {
            if let (Ok(name), Ok(val)) = (
                HeaderName::from_bytes(key.as_bytes()),
                HeaderValue::from_str(value),
            ) {
                header_map.insert(name, val);
            }
        }

        let client = Client::builder()
            .timeout(Duration::from_secs_f64(timeout))
            .connect_timeout(Duration::from_secs_f64(connect_timeout))
            .pool_max_idle_per_host(pool_size)
            .tcp_nodelay(true)
            .default_headers(header_map)
            .build()
            .map_err(|err| TelemetryError::ConnectionFailed {
                message: err.to_string(),
                code: 0,
            })?;

        Ok(Self {
            endpoint: url,
            content_type: content_type.into(),
            client: Mutex::new(Some(client)),
            shutdown: AtomicBool::new(false),
        })
    }
}

impl Transport for HttpTransport {
    fn content_type(&self) -> &str {
        &self.content_type
    }

    fn send(&self, payload: &[u8]) -> Result<Vec<u8>, TelemetryError> {
        if self.shutdown.load(Ordering::SeqCst) {
            return Err(TelemetryError::TransportShutdown);
        }
        let client = self.client.lock().clone();
        let Some(client) = client else {
            return Err(TelemetryError::TransportShutdown);
        };
        let response = client
            .post(&self.endpoint)
            .header(CONTENT_TYPE, self.content_type.as_str())
            .header(CONTENT_LENGTH, payload.len())
            .body(payload.to_vec())
            .send()
            .map_err(|err| TelemetryError::ConnectionFailed {
                message: err.to_string(),
                code: 0,
            })?;
        let status_code = response.status().as_u16();
        let body = response.text().unwrap_or_default();
        if (200..300).contains(&status_code) {
            return Ok(body.into_bytes());
        }
        Err(TelemetryError::ExportFailed {
            status: status_code.to_string(),
            body,
        })
    }

    fn shutdown(&self) -> bool {
        self.shutdown.store(true, Ordering::SeqCst);
        *self.client.lock() = None;
        true
    }

    fn force_flush(&self) -> bool {
        true
    }
}
