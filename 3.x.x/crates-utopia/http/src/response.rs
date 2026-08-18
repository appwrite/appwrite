use crate::error::{HttpError, Result};
use crate::headers::HeaderMap;
use parking_lot::Mutex;
use serde::Serialize;
use std::collections::HashMap;
use std::sync::Arc;
use utopia_compression::Compression;

#[derive(Debug, Clone, Copy)]
pub struct StatusCode;
impl StatusCode {
    pub const OK: u16 = 200;
    pub const CREATED: u16 = 201;
    pub const NO_CONTENT: u16 = 204;
    pub const MOVED_PERMANENTLY: u16 = 301;
    pub const FOUND: u16 = 302;
    pub const BAD_REQUEST: u16 = 400;
    pub const UNAUTHORIZED: u16 = 401;
    pub const FORBIDDEN: u16 = 403;
    pub const NOT_FOUND: u16 = 404;
    pub const METHOD_NOT_ALLOWED: u16 = 405;
    pub const INTERNAL_SERVER_ERROR: u16 = 500;
}

#[derive(Clone)]
pub struct Response {
    inner: Arc<Mutex<ResponseInner>>,
}

impl std::fmt::Debug for Response {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let inner = self.inner.lock();
        f.debug_struct("Response")
            .field("status", &inner.status)
            .field("content_type", &inner.content_type)
            .field("sent", &inner.sent)
            .field("size", &inner.size)
            .finish_non_exhaustive()
    }
}

struct ResponseInner {
    status: u16,
    headers: HeaderMap,
    cookies: Vec<Cookie>,
    body: Vec<u8>,
    content_type: String,
    disable_payload: bool,
    sent: bool,
    size: usize,
    accept_encoding: String,
    compression_min_size: usize,
    start: std::time::Instant,
    debug_timing: bool,
}

#[derive(Clone)]
struct Cookie {
    name: String,
    value: String,
    path: Option<String>,
    domain: Option<String>,
    secure: bool,
    http_only: bool,
    same_site: Option<String>,
}

impl Default for Response {
    fn default() -> Self {
        Self::new()
    }
}

impl Response {
    pub fn new() -> Self {
        Self {
            inner: Arc::new(Mutex::new(ResponseInner {
                status: 200,
                headers: HeaderMap::new(),
                cookies: Vec::new(),
                body: Vec::new(),
                content_type: String::new(),
                disable_payload: false,
                sent: false,
                size: 0,
                accept_encoding: String::new(),
                compression_min_size: 1024,
                start: std::time::Instant::now(),
                debug_timing: false,
            })),
        }
    }

    /// When enabled, adds `x-debug-speed` (development-style timing header).
    pub fn set_debug_timing(&self, enabled: bool) -> &Self {
        self.inner.lock().debug_timing = enabled;
        self
    }

    /// Time since this response was created (request start in the Hyper adapter).
    #[must_use]
    pub fn elapsed(&self) -> std::time::Duration {
        self.inner.lock().start.elapsed()
    }

    pub fn set_status(&self, code: u16) -> Result<&Self> {
        let known = [
            100, 101, 200, 201, 202, 204, 206, 301, 302, 303, 304, 307, 308, 400, 401, 403, 404,
            405, 408, 409, 410, 413, 415, 422, 429, 500, 501, 502, 503, 504,
        ];
        if !known.contains(&code) {
            return Err(HttpError::UnknownStatus);
        }
        self.inner.lock().status = code;
        Ok(self)
    }

    pub fn status_code(&self) -> u16 {
        self.inner.lock().status
    }

    pub fn set_content_type(&self, ty: impl Into<String>) -> &Self {
        self.inner.lock().content_type = ty.into();
        self
    }

    pub fn add_header(&self, key: &str, value: impl Into<String>) -> &Self {
        self.inner.lock().headers.add(key, value);
        self
    }

    pub fn set_header(&self, key: &str, value: impl Into<String>) -> &Self {
        self.inner.lock().headers.set(key, value);
        self
    }

    pub fn header_line(&self, key: &str) -> String {
        self.inner.lock().headers.get_line(key, "")
    }

    pub fn has_header(&self, key: &str) -> bool {
        self.inner.lock().headers.has(key)
    }

    pub fn disable_payload(&self) -> &Self {
        self.inner.lock().disable_payload = true;
        self
    }

    pub fn is_sent(&self) -> bool {
        self.inner.lock().sent
    }

    pub fn size(&self) -> usize {
        self.inner.lock().size
    }

    pub fn set_accept_encoding(&self, v: impl Into<String>) -> &Self {
        self.inner.lock().accept_encoding = v.into();
        self
    }

    pub fn set_compression_min_size(&self, n: usize) -> &Self {
        self.inner.lock().compression_min_size = n;
        self
    }

    #[allow(clippy::too_many_arguments)] // mirrors utopia-php/http cookie API surface
    pub fn add_cookie(
        &self,
        name: impl Into<String>,
        value: impl Into<String>,
        path: Option<&str>,
        domain: Option<&str>,
        secure: bool,
        http_only: bool,
        same_site: Option<&str>,
    ) -> &Self {
        self.inner.lock().cookies.push(Cookie {
            name: name.into(),
            value: value.into(),
            path: path.map(str::to_string),
            domain: domain.map(str::to_string),
            secure,
            http_only,
            same_site: same_site.map(str::to_string),
        });
        self
    }

    pub fn send(&self, body: impl AsRef<[u8]>) -> Result<()> {
        self.send_with_type(None, body.as_ref());
        Ok(())
    }

    fn send_with_type(&self, content_type: Option<&str>, body: &[u8]) {
        let mut inner = self.inner.lock();
        if inner.sent {
            return;
        }
        if let Some(ct) = content_type {
            inner.content_type.clear();
            inner.content_type.push_str(ct);
        }
        let mut body = body.to_vec();
        let content_type = inner.content_type.clone();
        let compress = !inner.accept_encoding.is_empty()
            && is_compressible(&content_type)
            && body.len() > inner.compression_min_size
            && !inner.headers.has("content-encoding");
        let accept_encoding = if compress {
            Some(inner.accept_encoding.clone())
        } else {
            None
        };
        let has_cookies = !inner.cookies.is_empty();
        let cookies = if has_cookies {
            Some(inner.cookies.clone())
        } else {
            None
        };
        let elapsed = if inner.debug_timing {
            Some(inner.start.elapsed().as_secs_f64())
        } else {
            None
        };
        let disable_payload = inner.disable_payload;

        if !content_type.is_empty() {
            inner.headers.set("content-type", content_type.clone());
        }
        if let Some(accept_encoding) = accept_encoding.as_deref() {
            if let Some(algo) = Compression::from_accept_encoding(accept_encoding) {
                if let Ok(compressed) = algo.compress(&body) {
                    body = compressed;
                    inner
                        .headers
                        .set("content-encoding", algo.content_encoding());
                    inner.headers.add("vary", "Accept-Encoding");
                    inner.headers.set("x-utopia-compression", "true");
                }
            }
        }
        if let Some(cookies) = &cookies {
            for c in cookies {
                let mut v = format!("{}={}", c.name, c.value);
                if let Some(p) = &c.path {
                    v.push_str("; Path=");
                    v.push_str(p);
                }
                if let Some(d) = &c.domain {
                    v.push_str("; Domain=");
                    v.push_str(d);
                }
                if c.secure {
                    v.push_str("; Secure");
                }
                if c.http_only {
                    v.push_str("; HttpOnly");
                }
                if let Some(s) = &c.same_site {
                    v.push_str("; SameSite=");
                    v.push_str(s);
                }
                inner.headers.add("set-cookie", v);
            }
        }
        if let Some(elapsed) = elapsed {
            inner.headers.set("x-debug-speed", format!("{elapsed}"));
        }
        if disable_payload {
            body.clear();
        }
        let mut size = body.len();
        for (k, vals) in inner.headers.iter() {
            for v in vals {
                size += k.len() + v.len();
            }
        }
        inner.size = size;
        inner.body = body;
        inner.sent = true;
    }

    pub fn json<T: Serialize>(&self, data: &T) -> Result<()> {
        let body = serde_json::to_vec(data).map_err(|e| HttpError::Other(e.to_string()))?;
        self.send_with_type(Some("application/json; charset=UTF-8"), &body);
        Ok(())
    }

    pub fn text(&self, data: impl AsRef<str>) -> Result<()> {
        self.send_with_type(Some("text/plain; charset=UTF-8"), data.as_ref().as_bytes());
        Ok(())
    }

    pub fn html(&self, data: impl AsRef<str>) -> Result<()> {
        self.send_with_type(Some("text/html; charset=UTF-8"), data.as_ref().as_bytes());
        Ok(())
    }

    /// Single-lock export for adapters: status, headers, body.
    pub fn into_http_parts(&self) -> (u16, Vec<(String, String)>, Vec<u8>) {
        if !self.is_sent() {
            let _ = self.send("");
        }
        let mut inner = self.inner.lock();
        let status = inner.status;
        let mut headers = Vec::with_capacity(inner.headers.iter().count());
        for (name, values) in inner.headers.iter() {
            for value in values {
                headers.push((name.to_string(), value.clone()));
            }
        }
        let body = std::mem::take(&mut inner.body);
        (status, headers, body)
    }

    pub fn redirect(&self, url: &str, status: u16) -> Result<()> {
        self.add_header("location", url);
        self.set_status(status)?;
        self.send("")
    }

    pub fn no_content(&self) -> Result<()> {
        self.set_status(StatusCode::NO_CONTENT)?;
        self.send("")
    }

    pub fn take_body(&self) -> Vec<u8> {
        std::mem::take(&mut self.inner.lock().body)
    }

    pub fn headers_snapshot(&self) -> HashMap<String, Vec<String>> {
        self.inner.lock().headers.clone().into_inner()
    }

    /// Iterate response headers without cloning the map.
    pub fn for_each_header(&self, mut f: impl FnMut(&str, &[String])) {
        let inner = self.inner.lock();
        for (name, values) in inner.headers.iter() {
            f(name, values);
        }
    }

    pub fn body_string(&self) -> String {
        String::from_utf8_lossy(&self.inner.lock().body).into_owned()
    }
}

fn is_compressible(content_type: &str) -> bool {
    let ct = content_type
        .split(';')
        .next()
        .unwrap_or("")
        .trim()
        .to_ascii_lowercase();
    matches!(
        ct.as_str(),
        "text/html"
            | "text/plain"
            | "text/css"
            | "text/javascript"
            | "application/javascript"
            | "application/json"
            | "application/xml"
            | "image/svg+xml"
    )
}
