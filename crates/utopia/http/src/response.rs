//! `Utopia\Http\Response`: status, headers and cookies set by the
//! application, sent once through [`Response::send`] (or streamed with
//! [`Response::chunk`]) to the server adapter's [`Wire`].

use std::time::{Instant, SystemTime, UNIX_EPOCH};

use bytes::Bytes;
use http::{HeaderName, HeaderValue, StatusCode};
use serde_json::Value;

use crate::compression::{self, Compression};
use crate::error::{Error, Result};
use crate::headers::Headers;

/// `Response::CHUNK_SIZE`: bodies larger than this are written in chunks.
pub const CHUNK_SIZE: usize = 2_000_000;

/// HTTP content types (`Response::CONTENT_TYPE_*`).
pub mod content_type {
    pub const TEXT: &str = "text/plain";
    pub const HTML: &str = "text/html";
    pub const JSON: &str = "application/json";
    pub const XML: &str = "text/xml";
    pub const JAVASCRIPT: &str = "text/javascript";
    pub const IMAGE: &str = "image/*";
    pub const IMAGE_JPEG: &str = "image/jpeg";
    pub const IMAGE_PNG: &str = "image/png";
    pub const IMAGE_GIF: &str = "image/gif";
    pub const IMAGE_SVG: &str = "image/svg+xml";
    pub const IMAGE_WEBP: &str = "image/webp";
    pub const IMAGE_ICON: &str = "image/x-icon";
    pub const IMAGE_BMP: &str = "image/bmp";
}

/// `Response::CHARSET_UTF8`.
pub const CHARSET_UTF8: &str = "UTF-8";

/// `SameSite` values (`Response::COOKIE_SAMESITE_*`).
pub mod same_site {
    pub const NONE: &str = "None";
    pub const STRICT: &str = "Strict";
    pub const LAX: &str = "Lax";
}

/// The reason phrase of every status code `setStatusCode()` accepts.
pub fn reason(code: u16) -> Option<&'static str> {
    Some(match code {
        100 => "Continue",
        101 => "Switching Protocols",
        102 => "Processing",
        103 => "Early Hints",
        200 => "OK",
        201 => "Created",
        202 => "Accepted",
        203 => "Non-Authoritative Information",
        204 => "No Content",
        205 => "Reset Content",
        206 => "Partial Content",
        207 => "Multi-Status",
        208 => "Already Reported",
        226 => "IM Used",
        300 => "Multiple Choices",
        301 => "Moved Permanently",
        302 => "Found",
        303 => "See Other",
        304 => "Not Modified",
        305 => "Use Proxy",
        306 => "(Unused)",
        307 => "Temporary Redirect",
        308 => "Permanent Redirect",
        400 => "Bad Request",
        401 => "Unauthorized",
        402 => "Payment Required",
        403 => "Forbidden",
        404 => "Not Found",
        405 => "Method Not Allowed",
        406 => "Not Acceptable",
        407 => "Proxy Authentication Required",
        408 => "Request Timeout",
        409 => "Conflict",
        410 => "Gone",
        411 => "Length Required",
        412 => "Precondition Failed",
        413 => "Request Entity Too Large",
        414 => "Request-URI Too Long",
        415 => "Unsupported Media Type",
        416 => "Requested Range Not Satisfiable",
        417 => "Expectation Failed",
        418 => "I'm a teapot",
        421 => "Misdirected Request",
        422 => "Unprocessable Entity",
        423 => "Locked",
        424 => "Failed Dependency",
        425 => "Too Early",
        426 => "Upgrade Required",
        428 => "Precondition Required",
        429 => "Too Many Requests",
        431 => "Request Header Fields Too Large",
        451 => "Unavailable For Legal Reasons",
        500 => "Internal Server Error",
        501 => "Not Implemented",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        504 => "Gateway Timeout",
        505 => "HTTP Version Not Supported",
        506 => "Variant Also Negotiates",
        507 => "Insufficient Storage",
        508 => "Loop Detected",
        510 => "Not Extended",
        511 => "Network Authentication Required",
        _ => return None,
    })
}

/// A cookie added with [`Response::add_cookie`]. `None` means not given.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Cookie {
    pub name: String,
    pub value: Option<String>,
    pub expire: Option<i64>,
    pub path: Option<String>,
    pub domain: Option<String>,
    pub secure: Option<bool>,
    pub http_only: Option<bool>,
    pub same_site: Option<String>,
}

impl Cookie {
    /// The `Set-Cookie` header value Swoole sends for this cookie at `now`
    /// (Unix seconds), or `None` when Swoole refuses the name.
    pub fn header(&self, now: i64) -> Option<String> {
        let name = &self.name;
        if name.is_empty()
            || name.bytes().any(|b| matches!(b, b'=' | b',' | b';' | b' ' | b'\t' | b'\r' | b'\n' | 0x0B | 0x0C))
        {
            return None;
        }
        let value = self.value.as_deref().unwrap_or("");
        let mut out = String::with_capacity(64);
        out.push_str(name);
        out.push('=');
        if value.is_empty() {
            out.push_str("deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0");
        } else {
            out.push_str(&php_std::encoding::urlencode(value.as_bytes()));
            let expires = self.expire.unwrap_or(0);
            if expires > 0 {
                out.push_str("; expires=");
                out.push_str(&cookie_date(expires));
                out.push_str("; Max-Age=");
                out.push_str(&(expires - now).max(0).to_string());
            }
        }
        if let Some(path) = self.path.as_deref().filter(|p| !p.is_empty()) {
            out.push_str("; path=");
            out.push_str(path);
        }
        if let Some(domain) = self.domain.as_deref().filter(|d| !d.is_empty()) {
            out.push_str("; domain=");
            out.push_str(domain);
        }
        if self.secure == Some(true) {
            out.push_str("; secure");
        }
        if self.http_only == Some(true) {
            out.push_str("; HttpOnly");
        }
        if let Some(same_site) = self.same_site.as_deref().filter(|s| !s.is_empty()) {
            out.push_str("; SameSite=");
            out.push_str(same_site);
        }
        Some(out)
    }
}

/// `D, d-M-Y H:i:s GMT` in UTC.
fn cookie_date(timestamp: i64) -> String {
    const DAYS: [&str; 7] = ["Thu", "Fri", "Sat", "Sun", "Mon", "Tue", "Wed"];
    const MONTHS: [&str; 12] = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    let days = timestamp.div_euclid(86_400);
    let secs = timestamp.rem_euclid(86_400);
    let (y, m, d) = civil(days);
    format!(
        "{}, {:02}-{}-{:04} {:02}:{:02}:{:02} GMT",
        DAYS[days.rem_euclid(7) as usize],
        d,
        MONTHS[(m - 1) as usize],
        y,
        secs / 3600,
        secs % 3600 / 60,
        secs % 60
    )
}

/// Days since the Unix epoch to (year, month, day).
pub(crate) fn civil(days: i64) -> (i64, i64, i64) {
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (if m <= 2 { y + 1 } else { y }, m, d)
}

/// What the server adapter was asked to send (`sendStatus`, `sendHeader`,
/// `sendCookie`, `write`, `end`): the response on the wire.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Wire {
    /// The status sent, if any.
    pub status: Option<u16>,
    /// Header lines by lowercased name, in the order they were first sent.
    pub headers: Vec<(String, Vec<String>)>,
    /// Cookies sent.
    pub cookies: Vec<Cookie>,
    /// Body bytes written.
    pub body: Vec<u8>,
    /// Number of `write()` calls (a chunked response has at least one).
    pub writes: usize,
    /// Whether `end()` was called.
    pub ended: bool,
}

impl Wire {
    fn header(&mut self, name: &str, values: &[String]) {
        match self.headers.iter_mut().find(|(n, _)| n == name) {
            Some((_, v)) => *v = values.to_vec(),
            None => self.headers.push((name.to_owned(), values.to_vec())),
        }
    }

    fn write(&mut self, content: &[u8]) -> bool {
        if self.ended {
            return false;
        }
        self.writes += 1;
        self.body.extend_from_slice(content);
        true
    }

    fn end(&mut self, content: Option<&[u8]>) {
        if self.ended {
            return;
        }
        if let Some(c) = content {
            self.body.extend_from_slice(c);
        }
        self.ended = true;
    }
}

/// An outgoing response.
#[derive(Debug, Clone)]
pub struct Response {
    status: u16,
    content_type: String,
    disable_payload: bool,
    sent: bool,
    headers_sent: bool,
    headers: Headers,
    cookies: Vec<(usize, Cookie)>,
    next_cookie: usize,
    start: Instant,
    size: usize,
    accept_encoding: String,
    compression_min_size: usize,
    compression_supported: Vec<String>,
    wire: Wire,
    /// A body staged by [`Response::with_body`], sent by the server when the
    /// application did not send one itself.
    staged: Option<Bytes>,
}

impl Default for Response {
    fn default() -> Self {
        Self::new()
    }
}

impl Response {
    /// `new Response()`: status 200, started now.
    pub fn new() -> Self {
        Self::started_at(Instant::now())
    }

    /// `new Response($time)`: the start time `X-Debug-Speed` measures from.
    pub fn started_at(start: Instant) -> Self {
        Self {
            status: 200,
            content_type: String::new(),
            disable_payload: false,
            sent: false,
            headers_sent: false,
            headers: Headers::new(),
            cookies: Vec::new(),
            next_cookie: 0,
            start,
            size: 0,
            accept_encoding: String::new(),
            compression_min_size: crate::COMPRESSION_MIN_SIZE_DEFAULT,
            compression_supported: Vec::new(),
            wire: Wire::default(),
            staged: None,
        }
    }

    // -- builders used by applications that return a response value -------

    /// A response with `status` and `body` staged to be sent with `content_type`.
    pub fn with_body(status: StatusCode, content_type: &str, body: impl Into<Bytes>) -> Self {
        let mut r = Self::new();
        r.status = status.as_u16();
        r.content_type = content_type.to_owned();
        r.staged = Some(body.into());
        r
    }

    /// A JSON response from already encoded bytes (`application/json; charset=UTF-8`).
    pub fn json_bytes(status: StatusCode, body: impl Into<Bytes>) -> Self {
        Self::with_body(status, "application/json; charset=UTF-8", body)
    }

    /// A plain text response (`text/plain; charset=UTF-8`).
    pub fn text_bytes(status: StatusCode, body: impl Into<Bytes>) -> Self {
        Self::with_body(status, "text/plain; charset=UTF-8", body)
    }

    /// An empty `204 No Content` response.
    pub fn no_content_response() -> Self {
        Self::with_body(StatusCode::NO_CONTENT, "", Bytes::new())
    }

    /// Moves the start `X-Debug-Speed` measures from.
    pub fn set_started(&mut self, start: Instant) -> &mut Self {
        self.start = start;
        self
    }

    /// The staged body, if any.
    pub fn staged(&self) -> Option<&Bytes> {
        self.staged.as_ref()
    }

    /// Replaces the staged body.
    pub fn stage(&mut self, body: impl Into<Bytes>) {
        self.staged = Some(body.into());
    }

    /// Sends the staged body, if any, unless the response was sent.
    pub fn flush(&mut self) {
        if !self.sent
            && !self.headers_sent
            && let Some(body) = self.staged.take()
        {
            self.send(&body);
        }
    }

    // -- compression -------------------------------------------------------

    /// `setAcceptEncoding($acceptEncoding)`.
    pub fn set_accept_encoding(&mut self, accept: impl Into<String>) -> &mut Self {
        self.accept_encoding = accept.into();
        self
    }

    /// `setCompressionMinSize($size)`.
    pub fn set_compression_min_size(&mut self, size: usize) -> &mut Self {
        self.compression_min_size = size;
        self
    }

    /// `setCompressionSupported($supported)`.
    pub fn set_compression_supported(&mut self, supported: Vec<String>) -> &mut Self {
        self.compression_supported = supported;
        self
    }

    /// Applies an application's compression settings (`Http::run`).
    pub fn set_compression(&mut self, accept: &str, settings: &Compression) -> &mut Self {
        if settings.enabled {
            self.accept_encoding = accept.to_owned();
            self.compression_min_size = settings.min_size;
            self.compression_supported = settings.supported.clone();
        }
        self
    }

    // -- status and content type -------------------------------------------

    /// `setContentType($type, $charset)`.
    pub fn set_content_type(&mut self, kind: &str, charset: &str) -> &mut Self {
        self.content_type =
            if charset.is_empty() || charset == "0" { kind.to_owned() } else { format!("{kind}; charset={charset}") };
        self
    }

    /// `getContentType()`.
    pub fn content_type(&self) -> &str {
        &self.content_type
    }

    /// `isSent()`.
    pub fn is_sent(&self) -> bool {
        self.sent
    }

    /// `setStatusCode($code)`: fails for a code without a reason phrase.
    pub fn set_status_code(&mut self, code: i64) -> Result<&mut Self> {
        match u16::try_from(code).ok().filter(|c| reason(*c).is_some()) {
            Some(c) => {
                self.status = c;
                Ok(self)
            }
            None => Err(Error::http("Unknown HTTP status code", 0)),
        }
    }

    /// Sets a known status.
    pub fn set_status(&mut self, status: StatusCode) -> &mut Self {
        self.status = status.as_u16();
        self
    }

    /// `getStatusCode()`.
    pub fn status_code(&self) -> u16 {
        self.status
    }

    /// The status as an [`http::StatusCode`].
    pub fn status(&self) -> StatusCode {
        StatusCode::from_u16(self.status).unwrap_or(StatusCode::OK)
    }

    /// `getSize()`: header and body bytes sent.
    pub fn size(&self) -> usize {
        self.size
    }

    /// `disablePayload()`.
    pub fn disable_payload(&mut self) -> &mut Self {
        self.disable_payload = true;
        self
    }

    /// `enablePayload()`.
    pub fn enable_payload(&mut self) -> &mut Self {
        self.disable_payload = false;
        self
    }

    /// Whether the body is suppressed (HEAD).
    pub fn payload_disabled(&self) -> bool {
        self.disable_payload
    }

    // -- headers -----------------------------------------------------------

    /// `setHeader($key, $value)`.
    pub fn set_header(&mut self, name: &str, value: impl Into<String>) -> &mut Self {
        self.headers.set(name, value);
        self
    }

    /// `addHeader($key, $value)`.
    pub fn add_header(&mut self, name: &str, value: impl Into<String>) -> &mut Self {
        self.headers.add(name, value);
        self
    }

    /// Alias of [`Response::add_header`].
    pub fn add(&mut self, name: &str, value: &str) -> &mut Self {
        self.headers.add(name, value);
        self
    }

    /// Alias of [`Response::set_header`].
    pub fn set(&mut self, name: &str, value: &str) -> &mut Self {
        self.headers.set(name, value);
        self
    }

    /// `removeHeader($key)`.
    pub fn remove_header(&mut self, name: &str) -> &mut Self {
        self.headers.remove(name);
        self
    }

    /// `hasHeader($key)`.
    pub fn has_header(&self, name: &str) -> bool {
        self.headers.has(name)
    }

    /// `getHeader($key)`.
    pub fn header(&self, name: &str) -> &[String] {
        self.headers.get(name).unwrap_or(&[])
    }

    /// `getHeaderLine($key, $default)`.
    pub fn header_line(&self, name: &str) -> Option<String> {
        self.headers.line(name)
    }

    /// `getHeaders()`.
    pub fn headers(&self) -> &Headers {
        &self.headers
    }

    // -- cookies -----------------------------------------------------------

    /// `addCookie($name, ...)`: the name is lowercased.
    pub fn add_cookie(&mut self, mut cookie: Cookie) -> &mut Self {
        cookie.name = cookie.name.to_ascii_lowercase();
        self.cookies.push((self.next_cookie, cookie));
        self.next_cookie += 1;
        self
    }

    /// `removeCookie($name)` (exact name).
    pub fn remove_cookie(&mut self, name: &str) -> &mut Self {
        self.cookies.retain(|(_, c)| c.name != name);
        self
    }

    /// `getCookies()`: each cookie with its position in the PHP array.
    pub fn cookies(&self) -> &[(usize, Cookie)] {
        &self.cookies
    }

    // -- output --------------------------------------------------------------

    /// What has been sent to the adapter.
    pub fn wire(&self) -> &Wire {
        &self.wire
    }

    /// Takes what has been sent.
    pub fn take_wire(&mut self) -> Wire {
        std::mem::take(&mut self.wire)
    }

    fn debug_speed(&mut self) {
        let speed = self.start.elapsed().as_secs_f64();
        self.headers.add("X-Debug-Speed", php_std::number::to_string(speed));
    }

    fn append_cookies(&mut self) {
        for (_, cookie) in &self.cookies {
            let mut sent = cookie.clone();
            sent.value = Some(cookie.value.clone().unwrap_or_default());
            self.wire.cookies.push(sent);
        }
    }

    fn append_headers(&mut self) {
        self.wire.status = Some(self.status);
        if !self.content_type.is_empty() && self.content_type != "0" {
            let ct = self.content_type.clone();
            self.headers.set("Content-Type", ct);
        }
        let headers: Vec<(String, Vec<String>)> =
            self.headers.iter().map(|(k, v)| (k.to_owned(), v.to_vec())).collect();
        for (name, values) in headers {
            self.wire.header(&name, &values);
        }
    }

    /// `send($body)`: sends the status, headers, cookies and body once,
    /// compressing the body when the client accepts it.
    pub fn send(&mut self, body: &[u8]) {
        if self.sent {
            return;
        }
        self.append_cookies();

        let mut compressed: Option<Vec<u8>> = None;
        if !self.has_header("Content-Encoding")
            && !self.accept_encoding.is_empty()
            && self.accept_encoding != "0"
            && compression::compressible(&self.content_type)
            && body.len() > self.compression_min_size
            && let Some(algorithm) =
                compression::from_accept_encoding(&self.accept_encoding, &self.compression_supported)
        {
            let out = algorithm.compress(body);
            self.remove_header("Content-Length");
            self.add_header("Content-Length", out.len().to_string());
            self.add_header("Content-Encoding", algorithm.content_encoding());
            self.add_header("X-Utopia-Compression", "true");
            self.add_header("Vary", "Accept-Encoding");
            compressed = Some(out);
        }
        let body = compressed.as_deref().unwrap_or(body);

        self.debug_speed();
        self.append_headers();

        if self.disable_payload {
            self.wire.end(None);
            self.sent = true;
            return;
        }

        let mut headers_size = 0usize;
        for (name, values) in self.headers.iter() {
            for v in values {
                headers_size += name.len() + 2 + v.len();
            }
            headers_size += (values.len().max(1) - 1) * 2;
        }
        headers_size += (self.headers.len().max(1) - 1) * 2;
        self.size += headers_size + body.len();

        if body.len() <= CHUNK_SIZE {
            self.wire.end(Some(body));
        } else {
            for chunk in body.chunks(CHUNK_SIZE) {
                self.wire.write(chunk);
            }
            self.wire.end(None);
        }
        self.sent = true;
        self.disable_payload = true;
    }

    /// `chunk($body, $end)`: streams a part of the body; headers go with the first.
    pub fn chunk(&mut self, body: &[u8], end: bool) {
        if self.sent {
            return;
        }
        if end {
            self.sent = true;
        }
        self.debug_speed();
        if !self.headers_sent {
            self.append_cookies();
            self.append_headers();
            self.headers_sent = true;
        }
        if !self.disable_payload {
            self.wire.write(body);
            if end {
                self.disable_payload = true;
                self.wire.end(None);
            }
        } else {
            self.wire.end(None);
        }
    }

    /// `redirect($url, $statusCode)`.
    pub fn redirect(&mut self, url: &str, status: i64) -> Result<()> {
        self.add_header("Location", url);
        self.set_status_code(status)?;
        self.send(b"");
        Ok(())
    }

    /// `html($data)`.
    pub fn html(&mut self, data: &str) {
        self.set_content_type(content_type::HTML, CHARSET_UTF8);
        self.send(data.as_bytes());
    }

    /// `text($data)`.
    pub fn text(&mut self, data: &str) {
        self.set_content_type(content_type::TEXT, CHARSET_UTF8);
        self.send(data.as_bytes());
    }

    /// `json($data)`: `$data` must be an array or object.
    pub fn json(&mut self, data: &Value) -> Result<()> {
        if !matches!(data, Value::Array(_) | Value::Object(_)) {
            return Err(Error::Generic("Invalid JSON input var".to_owned()));
        }
        let body = php_std::json::encode(data, php_std::json::Flags::UNESCAPED_UNICODE, php_std::json::DEFAULT_DEPTH)
            .unwrap_or_default();
        self.set_content_type(content_type::JSON, CHARSET_UTF8);
        self.send(body.as_bytes());
        Ok(())
    }

    /// `jsonp($callback, $data)`.
    pub fn jsonp(&mut self, callback: &str, data: &Value) {
        let json =
            php_std::json::encode(data, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH).unwrap_or_default();
        self.set_content_type(content_type::JAVASCRIPT, CHARSET_UTF8);
        self.send(format!("parent.{callback}({json});").as_bytes());
    }

    /// `iframe($callback, $data)`.
    pub fn iframe(&mut self, callback: &str, data: &Value) {
        let json =
            php_std::json::encode(data, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH).unwrap_or_default();
        self.set_content_type(content_type::HTML, CHARSET_UTF8);
        self.send(format!("<script type=\"text/javascript\">window.parent.{callback}({json});</script>").as_bytes());
    }

    /// `noContent()`.
    pub fn no_content(&mut self) {
        self.status = 204;
        self.send(b"");
    }

    // -- conversion for the server -------------------------------------------

    /// Unix seconds now (for cookie `Max-Age`).
    pub(crate) fn now() -> i64 {
        SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs() as i64).unwrap_or(0)
    }

    /// The hyper response for what was sent (sends the staged body first).
    pub fn into_http(mut self) -> http::Response<http_body_util::Full<Bytes>> {
        self.flush();
        let wire = self.wire;
        let status = wire.status.and_then(|s| StatusCode::from_u16(s).ok()).unwrap_or(StatusCode::OK);
        let mut builder = http::Response::builder().status(status);
        if let Some(headers) = builder.headers_mut() {
            for (name, values) in &wire.headers {
                let Ok(name) = HeaderName::from_bytes(name.as_bytes()) else { continue };
                for v in values {
                    if let Ok(v) = HeaderValue::from_str(v) {
                        headers.append(name.clone(), v);
                    }
                }
            }
            let now = Self::now();
            for cookie in &wire.cookies {
                if let Some(line) = cookie.header(now)
                    && let Ok(v) = HeaderValue::from_str(&line)
                {
                    headers.append(http::header::SET_COOKIE, v);
                }
            }
        }
        builder.body(http_body_util::Full::new(Bytes::from(wire.body))).unwrap_or_else(|_| {
            let mut r = http::Response::new(http_body_util::Full::new(Bytes::new()));
            *r.status_mut() = StatusCode::INTERNAL_SERVER_ERROR;
            r
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn cookie_lines_like_swoole() {
        let c = Cookie {
            name: "n".into(),
            value: Some("v a;l=ü".into()),
            expire: Some(1000),
            path: Some("/p".into()),
            domain: Some("d.com".into()),
            secure: Some(true),
            http_only: Some(true),
            same_site: Some("Strict".into()),
        };
        assert_eq!(
            c.header(2000).unwrap(),
            "n=v+a%3Bl%3D%C3%BC; expires=Thu, 01-Jan-1970 00:16:40 GMT; Max-Age=0; path=/p; domain=d.com; secure; HttpOnly; SameSite=Strict"
        );
        let empty = Cookie { name: "n".into(), ..Default::default() };
        assert_eq!(empty.header(0).unwrap(), "n=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0");
    }

    #[test]
    fn send_once() {
        let mut r = Response::new();
        r.add_header("key", "value").add_cookie(Cookie {
            name: "Name".into(),
            value: Some("value".into()),
            ..Default::default()
        });
        r.send(b"body");
        r.send(b"again");
        assert_eq!(r.wire().body, b"body");
        assert_eq!(r.wire().cookies[0].name, "name");
        assert!(r.set_status_code(0).is_err());
    }
}
