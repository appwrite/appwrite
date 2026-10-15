//! The outgoing response: status, headers and cookies set by the
//! application, sent once through [`Response::send`] (or streamed with
//! [`Response::chunk`]); what reaches the connection is recorded as a
//! [`Wire`].
//!
//! PHP: `Utopia\Http\Response`; the [`Wire`] stands for the server
//! adapter's output.

use std::fmt::Write;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

use bytes::Bytes;
use http::{HeaderName, HeaderValue, StatusCode};
use serde_json::Value;

use crate::compression::{self, Compression};
use crate::encode;
use crate::error::{Error, Result};
use crate::headers::Headers;

/// Bodies larger than this many bytes are written in chunks.
///
/// PHP: `Response::CHUNK_SIZE`.
pub const CHUNK_SIZE: usize = 2_000_000;

/// Common content types.
///
/// PHP: `Response::CONTENT_TYPE_*`.
pub mod content_type {
    /// `text/plain`.
    pub const TEXT: &str = "text/plain";
    /// `text/html`.
    pub const HTML: &str = "text/html";
    /// `application/json`.
    pub const JSON: &str = "application/json";
    /// `text/xml`.
    pub const XML: &str = "text/xml";
    /// `text/javascript`.
    pub const JAVASCRIPT: &str = "text/javascript";
    /// `image/*`: any image.
    pub const IMAGE: &str = "image/*";
    /// `image/jpeg`.
    pub const IMAGE_JPEG: &str = "image/jpeg";
    /// `image/png`.
    pub const IMAGE_PNG: &str = "image/png";
    /// `image/gif`.
    pub const IMAGE_GIF: &str = "image/gif";
    /// `image/svg+xml`.
    pub const IMAGE_SVG: &str = "image/svg+xml";
    /// `image/webp`.
    pub const IMAGE_WEBP: &str = "image/webp";
    /// `image/x-icon`.
    pub const IMAGE_ICON: &str = "image/x-icon";
    /// `image/bmp`.
    pub const IMAGE_BMP: &str = "image/bmp";
}

/// The UTF-8 charset name, for [`Response::set_content_type`].
///
/// PHP: `Response::CHARSET_UTF8`.
pub const CHARSET_UTF8: &str = "UTF-8";

/// The `SameSite` values of a cookie.
///
/// PHP: `Response::COOKIE_SAMESITE_*`.
pub mod same_site {
    /// `None`: sent with cross-site requests.
    pub const NONE: &str = "None";
    /// `Strict`: sent with same-site requests only.
    pub const STRICT: &str = "Strict";
    /// `Lax`: sent with same-site requests and top-level navigations.
    pub const LAX: &str = "Lax";
}

/// The reason phrase of a status code, or `None` for a code the response
/// does not accept.
///
/// PHP: every status code `setStatusCode()` accepts.
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
    /// The cookie name.
    pub name: String,
    /// The value; empty or `None` deletes the cookie.
    pub value: Option<String>,
    /// The expiry, in Unix seconds.
    pub expire: Option<i64>,
    /// The `path` attribute.
    pub path: Option<String>,
    /// The `domain` attribute.
    pub domain: Option<String>,
    /// Whether the cookie is sent over HTTPS only.
    pub secure: Option<bool>,
    /// Whether scripts are denied access to the cookie.
    pub http_only: Option<bool>,
    /// The `SameSite` attribute (see [`same_site`]).
    pub same_site: Option<String>,
}

impl Cookie {
    /// The `Set-Cookie` header value for this cookie at `now` (Unix
    /// seconds), or `None` when the cookie is refused: an empty name, a name
    /// with `=`, a name, path or domain with separators or whitespace, or an
    /// expiry past the year 9999.
    ///
    /// The value is URL-encoded; an empty value deletes the cookie.
    ///
    /// PHP: the header Swoole sends for the cookie.
    pub fn header(&self, now: i64) -> Option<String> {
        let name = &self.name;
        let forbidden =
            |s: &str| s.bytes().any(|b| matches!(b, b',' | b';' | b' ' | b'\t' | b'\r' | b'\n' | 0x0B | 0x0C));
        if name.is_empty() || name.contains('=') || forbidden(name) {
            return None;
        }
        if self.path.as_deref().is_some_and(forbidden) || self.domain.as_deref().is_some_and(forbidden) {
            return None;
        }
        // Expiry years past 9999 are refused.
        if self.expire.is_some_and(|e| e >= 253_402_300_800) {
            return None;
        }
        let value = self.value.as_deref().unwrap_or("");
        let mut out = String::with_capacity(name.len() + value.len() * 3 + 128);
        out.push_str(name);
        out.push('=');
        if value.is_empty() {
            out.push_str("deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0");
        } else {
            urlencode_into(&mut out, value.as_bytes());
            let expires = self.expire.unwrap_or(0);
            if expires > 0 {
                out.push_str("; expires=");
                cookie_date(&mut out, expires);
                let _ = write!(out, "; Max-Age={}", (expires - now).max(0));
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

/// PHP `urlencode()`, appended: alphanumerics and `-_.` are kept, a space
/// is `+`, every other byte is `%XX`.
fn urlencode_into(out: &mut String, s: &[u8]) {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    for &c in s {
        if c == b' ' {
            out.push('+');
        } else if c.is_ascii_alphanumeric() || matches!(c, b'-' | b'.' | b'_') {
            out.push(char::from(c));
        } else {
            out.push('%');
            out.push(char::from(HEX[usize::from(c >> 4)]));
            out.push(char::from(HEX[usize::from(c & 15)]));
        }
    }
}

/// `D, d-M-Y H:i:s GMT` in UTC, appended.
fn cookie_date(out: &mut String, timestamp: i64) {
    const DAYS: [&str; 7] = ["Thu", "Fri", "Sat", "Sun", "Mon", "Tue", "Wed"];
    const MONTHS: [&str; 12] = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    let days = timestamp.div_euclid(86_400);
    let secs = timestamp.rem_euclid(86_400);
    let (y, m, d) = civil(days);
    let _ = write!(
        out,
        "{}, {:02}-{}-{:04} {:02}:{:02}:{:02} GMT",
        DAYS[days.rem_euclid(7) as usize],
        d,
        MONTHS[(m - 1) as usize],
        y,
        secs / 3600,
        secs % 3600 / 60,
        secs % 60
    );
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

/// The response on the wire: the status, headers, cookies and body bytes
/// the response has sent so far.
///
/// PHP: what the server adapter was asked to send (`sendStatus`,
/// `sendHeader`, `sendCookie`, `write`, `end`).
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

/// The head of a streamed response: what is sent before the first body byte.
#[derive(Debug)]
pub(crate) struct Head {
    pub status: u16,
    pub headers: Vec<(String, Vec<String>)>,
    pub cookies: Vec<Cookie>,
    /// The whole body, when the response ended before anything was written.
    pub body: Option<Bytes>,
}

/// Where a streamed response goes as it is sent (the server's connection).
#[derive(Debug)]
pub(crate) struct Stream {
    pub head: Option<utopia_async::sync::oneshot::Sender<Head>>,
    pub body: Option<utopia_async::sync::mpsc::UnboundedSender<Bytes>>,
}

/// A hyper response body: a buffer, or the frames a streamed response sends.
pub type Body = http_body_util::combinators::BoxBody<Bytes, std::convert::Infallible>;

/// An outgoing response.
///
/// The application sets the status, headers and cookies, then sends the
/// body once (or in chunks); later sends are ignored.
#[derive(Debug)]
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
    stream: Option<Stream>,
}

impl Clone for Response {
    /// A copy of the response state; a copy does not stream.
    fn clone(&self) -> Self {
        Self {
            status: self.status,
            content_type: self.content_type.clone(),
            disable_payload: self.disable_payload,
            sent: self.sent,
            headers_sent: self.headers_sent,
            headers: self.headers.clone(),
            cookies: self.cookies.clone(),
            next_cookie: self.next_cookie,
            start: self.start,
            size: self.size,
            accept_encoding: self.accept_encoding.clone(),
            compression_min_size: self.compression_min_size,
            compression_supported: self.compression_supported.clone(),
            wire: self.wire.clone(),
            staged: self.staged.clone(),
            stream: None,
        }
    }
}

impl Default for Response {
    /// A `200` response started now.
    fn default() -> Self {
        Self::new()
    }
}

impl Response {
    /// A `200` response started now.
    ///
    /// PHP: `new Response()`.
    pub fn new() -> Self {
        Self::started_at(Instant::now())
    }

    /// A `200` response started at `start`, the time the `X-Debug-Speed`
    /// header measures from.
    ///
    /// PHP: `new Response($time)`.
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
            stream: None,
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

    /// Sets the client's `Accept-Encoding` header, which picks the
    /// compression algorithm; empty disables compression.
    ///
    /// PHP: `setAcceptEncoding($acceptEncoding)`.
    pub fn set_accept_encoding(&mut self, accept: impl Into<String>) -> &mut Self {
        self.accept_encoding = accept.into();
        self
    }

    /// Sets the size a body must exceed, in bytes, to be compressed.
    ///
    /// PHP: `setCompressionMinSize($size)`.
    pub fn set_compression_min_size(&mut self, size: usize) -> &mut Self {
        self.compression_min_size = size;
        self
    }

    /// Sets the algorithm names the response may use; empty means every
    /// supported one.
    ///
    /// PHP: `setCompressionSupported($supported)`.
    pub fn set_compression_supported(&mut self, supported: Vec<String>) -> &mut Self {
        self.compression_supported = supported;
        self
    }

    /// Applies an application's compression settings with the client's
    /// `Accept-Encoding` header; does nothing when they are disabled.
    /// [`Http::run`](crate::Http::run) calls it.
    pub fn set_compression(&mut self, accept: &str, settings: &Compression) -> &mut Self {
        if settings.enabled {
            self.accept_encoding = accept.to_owned();
            self.compression_min_size = settings.min_size;
            self.compression_supported = settings.supported.clone();
        }
        self
    }

    // -- status and content type -------------------------------------------

    /// Sets the content type, with `; charset=<charset>` appended unless
    /// `charset` is empty.
    ///
    /// PHP: `setContentType($type, $charset)`; a charset of `0` counts as
    /// empty.
    pub fn set_content_type(&mut self, kind: &str, charset: &str) -> &mut Self {
        self.content_type =
            if charset.is_empty() || charset == "0" { kind.to_owned() } else { format!("{kind}; charset={charset}") };
        self
    }

    /// The content type, with its charset (empty until set).
    ///
    /// PHP: `getContentType()`.
    pub fn content_type(&self) -> &str {
        &self.content_type
    }

    /// Whether the response has been sent.
    ///
    /// PHP: `isSent()`.
    pub fn is_sent(&self) -> bool {
        self.sent
    }

    /// Sets the status code; fails with an [`Error::Http`] for a code
    /// without a reason phrase (see [`reason`]).
    ///
    /// PHP: `setStatusCode($code)`.
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

    /// The status code.
    ///
    /// PHP: `getStatusCode()`.
    pub fn status_code(&self) -> u16 {
        self.status
    }

    /// The status as an [`http::StatusCode`].
    pub fn status(&self) -> StatusCode {
        StatusCode::from_u16(self.status).unwrap_or(StatusCode::OK)
    }

    /// The bytes sent: header lines and body.
    ///
    /// PHP: `getSize()`.
    pub fn size(&self) -> usize {
        self.size
    }

    /// Suppresses the body: the response sends its status and headers only
    /// (as for `HEAD`).
    ///
    /// PHP: `disablePayload()`.
    pub fn disable_payload(&mut self) -> &mut Self {
        self.disable_payload = true;
        self
    }

    /// Lets the response send a body again.
    ///
    /// PHP: `enablePayload()`.
    pub fn enable_payload(&mut self) -> &mut Self {
        self.disable_payload = false;
        self
    }

    /// Whether the body is suppressed (HEAD).
    pub fn payload_disabled(&self) -> bool {
        self.disable_payload
    }

    // -- headers -----------------------------------------------------------

    /// Sets a header to one value, replacing the others.
    ///
    /// PHP: `setHeader($key, $value)`.
    pub fn set_header(&mut self, name: &str, value: impl Into<String>) -> &mut Self {
        self.headers.set(name, value);
        self
    }

    /// Appends a value to a header.
    ///
    /// PHP: `addHeader($key, $value)`.
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

    /// Removes a header.
    ///
    /// PHP: `removeHeader($key)`.
    pub fn remove_header(&mut self, name: &str) -> &mut Self {
        self.headers.remove(name);
        self
    }

    /// Whether a header is set.
    ///
    /// PHP: `hasHeader($key)`.
    pub fn has_header(&self, name: &str) -> bool {
        self.headers.has(name)
    }

    /// Every value of a header (empty when it is not set).
    ///
    /// PHP: `getHeader($key)`.
    pub fn header(&self, name: &str) -> &[String] {
        self.headers.get(name).unwrap_or(&[])
    }

    /// The values of a header joined with `", "`, or `None` when it is not
    /// set.
    ///
    /// PHP: `getHeaderLine($key, $default)`.
    pub fn header_line(&self, name: &str) -> Option<String> {
        self.headers.line(name)
    }

    /// Every header set.
    ///
    /// PHP: `getHeaders()`.
    pub fn headers(&self) -> &Headers {
        &self.headers
    }

    // -- cookies -----------------------------------------------------------

    /// Adds a cookie to send; its name is lowercased.
    ///
    /// PHP: `addCookie($name, ...)`.
    pub fn add_cookie(&mut self, mut cookie: Cookie) -> &mut Self {
        cookie.name.make_ascii_lowercase();
        self.cookies.push((self.next_cookie, cookie));
        self.next_cookie += 1;
        self
    }

    /// Removes the cookies named exactly `name`.
    ///
    /// PHP: `removeCookie($name)`.
    pub fn remove_cookie(&mut self, name: &str) -> &mut Self {
        self.cookies.retain(|(_, c)| c.name != name);
        self
    }

    /// The cookies to send, each with the position it was added at
    /// (positions of removed cookies are not reused).
    ///
    /// PHP: `getCookies()`; the position is the cookie's key in the PHP
    /// array.
    pub fn cookies(&self) -> &[(usize, Cookie)] {
        &self.cookies
    }

    // -- output --------------------------------------------------------------

    /// Sends what is written to `stream` as it is written (the server does this).
    pub(crate) fn attach(&mut self, stream: Stream) {
        self.stream = Some(stream);
    }

    /// Sends the head to the stream, once.
    fn stream_head(&mut self, body: Option<Bytes>) {
        let Some(stream) = self.stream.as_mut() else { return };
        if let Some(tx) = stream.head.take() {
            let head = Head {
                status: self.wire.status.unwrap_or(200),
                headers: self.wire.headers.clone(),
                cookies: self.wire.cookies.clone(),
                body,
            };
            let _ = tx.send(head);
        }
    }

    /// `write($content)` of the adapter: `false` once the response ended.
    fn out_write(&mut self, content: &[u8]) -> bool {
        if self.stream.is_none() {
            return self.wire.write(content);
        }
        if self.wire.ended {
            return false;
        }
        self.wire.writes += 1;
        self.stream_head(None);
        match self.stream.as_ref().and_then(|s| s.body.as_ref()) {
            Some(body) => body.send(Bytes::copy_from_slice(content)).is_ok(),
            None => false,
        }
    }

    /// `end($content)` of the adapter.
    fn out_end(&mut self, content: Option<&[u8]>) {
        if self.stream.is_none() {
            self.wire.end(content);
            return;
        }
        if self.wire.ended {
            return;
        }
        self.wire.ended = true;
        let whole = self.stream.as_ref().is_some_and(|s| s.head.is_some());
        if whole {
            // Nothing was written yet: the body is known in full (Content-Length).
            self.stream_head(Some(Bytes::copy_from_slice(content.unwrap_or_default())));
        }
        if let Some(stream) = self.stream.as_mut()
            && let Some(body) = stream.body.take()
            && !whole
            && let Some(c) = content.filter(|c| !c.is_empty())
        {
            let _ = body.send(Bytes::copy_from_slice(c));
        }
    }

    /// Puts the status on the wire without sending anything (a server-made
    /// answer: 500 after a failure, 503 past the concurrency cap).
    pub(crate) fn force_status(&mut self) {
        self.wire.status = Some(self.status);
    }

    /// Ends a streamed response the application left open (Swoole ends it
    /// with what was set on the connection: a 200 without a body).
    pub(crate) fn finish(&mut self) {
        self.flush();
        self.out_end(None);
    }

    /// What has been sent so far.
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
            self.headers.set("Content-Type", self.content_type.as_str());
        }
        // The response keeps its headers (PHP's getters read them after a
        // send): the wire gets one copy.
        for (name, values) in self.headers.iter() {
            self.wire.header(name, values);
        }
    }

    /// Sends the status, headers, cookies and body, compressing the body when
    /// the client accepts it, its type is compressible and it is large
    /// enough. Only the first send counts; later ones are ignored.
    ///
    /// Adds an `X-Debug-Speed` header with the seconds since the response
    /// started.
    ///
    /// PHP: `send($body)`.
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
            self.out_end(None);
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
            self.out_end(Some(body));
        } else {
            for chunk in body.chunks(CHUNK_SIZE) {
                self.out_write(chunk);
            }
            self.out_end(None);
        }
        self.sent = true;
        self.disable_payload = true;
    }

    /// Streams a part of the body, uncompressed; the status, headers and
    /// cookies go with the first chunk, and `end` closes the response.
    ///
    /// PHP: `chunk($body, $end)`.
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
            self.out_write(body);
            if end {
                self.disable_payload = true;
                self.out_end(None);
            }
        } else {
            self.out_end(None);
        }
    }

    /// Sends an empty redirect to `url` with `status` (such as 301 or 302);
    /// fails, without sending, for an unknown status.
    ///
    /// PHP: `redirect($url, $statusCode)`.
    pub fn redirect(&mut self, url: &str, status: i64) -> Result<()> {
        self.add_header("Location", url);
        self.set_status_code(status)?;
        self.send(b"");
        Ok(())
    }

    /// Sends `data` as `text/html; charset=UTF-8`.
    ///
    /// PHP: `html($data)`.
    pub fn html(&mut self, data: &str) {
        self.set_content_type(content_type::HTML, CHARSET_UTF8);
        self.send(data.as_bytes());
    }

    /// Sends `data` as `text/plain; charset=UTF-8`.
    ///
    /// PHP: `text($data)`.
    pub fn text(&mut self, data: &str) {
        self.set_content_type(content_type::TEXT, CHARSET_UTF8);
        self.send(data.as_bytes());
    }

    /// Sends `data` encoded as JSON (`application/json; charset=UTF-8`),
    /// with Unicode left unescaped. Fails, without sending, unless `data` is
    /// an array or an object.
    ///
    /// PHP: `json($data)`; the body is `json_encode($data,
    /// JSON_UNESCAPED_UNICODE)`.
    pub fn json(&mut self, data: &Value) -> Result<()> {
        if !matches!(data, Value::Array(_) | Value::Object(_)) {
            return Err(Error::Generic("Invalid JSON input var".to_owned()));
        }
        let body = encode::json(data, true).unwrap_or_default();
        self.set_content_type(content_type::JSON, CHARSET_UTF8);
        self.send(body.as_bytes());
        Ok(())
    }

    /// Sends `data` as a JSONP script, `parent.<callback>(<json>);`, as
    /// `text/javascript`.
    ///
    /// PHP: `jsonp($callback, $data)`.
    pub fn jsonp(&mut self, callback: &str, data: &Value) {
        let json = encode::json(data, false).unwrap_or_default();
        self.set_content_type(content_type::JAVASCRIPT, CHARSET_UTF8);
        self.send(format!("parent.{callback}({json});").as_bytes());
    }

    /// Sends an HTML page whose script calls `window.parent.<callback>` with
    /// `data` as JSON.
    ///
    /// PHP: `iframe($callback, $data)`.
    pub fn iframe(&mut self, callback: &str, data: &Value) {
        let json = encode::json(data, false).unwrap_or_default();
        self.set_content_type(content_type::HTML, CHARSET_UTF8);
        self.send(format!("<script type=\"text/javascript\">window.parent.{callback}({json});</script>").as_bytes());
    }

    /// Sends an empty `204 No Content` response.
    ///
    /// PHP: `noContent()`.
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
    pub fn into_http(mut self) -> http::Response<Body> {
        use http_body_util::BodyExt;
        self.flush();
        let wire = self.wire;
        let body = http_body_util::Full::new(Bytes::from(wire.body)).boxed();
        head_response(wire.status.unwrap_or(200), &wire.headers, &wire.cookies, body)
    }
}

/// A hyper response from a head and a body.
pub(crate) fn head_response(
    status: u16,
    headers: &[(String, Vec<String>)],
    cookies: &[Cookie],
    body: Body,
) -> http::Response<Body> {
    {
        let status = StatusCode::from_u16(status).unwrap_or(StatusCode::OK);
        let mut builder = http::Response::builder().status(status);
        if let Some(map) = builder.headers_mut() {
            let headers_out = map;
            for (name, values) in headers {
                let Ok(name) = HeaderName::from_bytes(name.as_bytes()) else { continue };
                for v in values {
                    if let Ok(v) = HeaderValue::from_str(v) {
                        headers_out.append(name.clone(), v);
                    }
                }
            }
            let now = Response::now();
            for cookie in cookies {
                if let Some(line) = cookie.header(now)
                    && let Ok(v) = HeaderValue::from_str(&line)
                {
                    headers_out.append(http::header::SET_COOKIE, v);
                }
            }
        }
        builder.body(body).unwrap_or_else(|_| {
            use http_body_util::BodyExt;
            let mut r = http::Response::new(http_body_util::Empty::new().boxed());
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

    /// `getSize()`: every `name: value` line, two bytes between lines, and the body.
    #[test]
    fn size_counts_header_lines_and_body() {
        let mut r = Response::new();
        r.add_header("A", "1").add_header("a", "22").set_content_type("text/plain", "");
        r.send(b"hello");
        let speed = r.header("x-debug-speed")[0].len();
        let lines = ["a: 1".len(), "a: 22".len(), "x-debug-speed: ".len() + speed, "content-type: text/plain".len()];
        let expected = lines.iter().sum::<usize>() + 2 /* second a */ + 2 * 2 /* three names */ + 5;
        assert_eq!(r.size(), expected);
    }
}
