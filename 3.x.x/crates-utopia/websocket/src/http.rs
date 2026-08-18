//! HTTP request/response types passed to `on_open` / `on_request`.

use parking_lot::Mutex;
use std::fmt::Write;
use std::sync::Arc;

/// Incoming HTTP request (PHP Swoole `Request` / Workerman `$_SERVER` analogue).
#[derive(Debug, Clone)]
pub struct HttpRequest {
    /// Connection id (PHP `$request->fd` / `$connection->id`).
    pub connection: i64,
    /// HTTP method.
    pub method: String,
    /// Request path (and query).
    pub path: String,
    /// Header name/value pairs.
    pub headers: Vec<(String, String)>,
}

/// Mutable HTTP response written by `on_request`.
#[derive(Debug, Clone)]
pub struct HttpResponse {
    inner: Arc<Mutex<HttpResponseInner>>,
}

#[derive(Debug)]
struct HttpResponseInner {
    status: u16,
    headers: Vec<(String, String)>,
    body: Vec<u8>,
    ended: bool,
}

impl HttpResponse {
    #[must_use]
    pub(crate) fn new() -> Self {
        Self {
            inner: Arc::new(Mutex::new(HttpResponseInner {
                status: 200,
                headers: Vec::new(),
                body: Vec::new(),
                ended: false,
            })),
        }
    }

    /// PHP `$response->header($key, $value)`.
    pub fn header(&self, key: impl Into<String>, value: impl Into<String>) -> &Self {
        self.inner.lock().headers.push((key.into(), value.into()));
        self
    }

    /// PHP `$response->status($code)`.
    pub fn status(&self, code: u16) -> &Self {
        self.inner.lock().status = code;
        self
    }

    /// PHP `$response->end($body)`.
    pub fn end(&self, body: impl AsRef<[u8]>) {
        let mut inner = self.inner.lock();
        inner.body = body.as_ref().to_vec();
        inner.ended = true;
    }

    pub(crate) fn to_bytes(&self) -> Vec<u8> {
        let inner = self.inner.lock();
        let reason = match inner.status {
            404 => "Not Found",
            _ => "OK",
        };
        let mut out = format!("HTTP/1.1 {} {}\r\n", inner.status, reason);
        let mut has_length = false;
        for (name, value) in &inner.headers {
            if name.eq_ignore_ascii_case("content-length") {
                has_length = true;
            }
            let _ = write!(out, "{name}: {value}\r\n");
        }
        if !has_length {
            let _ = write!(out, "Content-Length: {}\r\n", inner.body.len());
        }
        out.push_str("\r\n");
        let mut bytes = out.into_bytes();
        bytes.extend_from_slice(&inner.body);
        bytes
    }
}
