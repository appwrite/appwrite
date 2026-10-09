//! `Utopia\Http\Request` with the semantics of its Swoole adapter, the one
//! Appwrite serves with: headers, cookies, query and form fields are parsed
//! the way Swoole parses them, the JSON body the way Utopia does.

use std::net::IpAddr;
use std::sync::{Arc, OnceLock};

use bytes::Bytes;
use indexmap::IndexMap;
use php_std::zval::{Array, Zval};
use serde_json::{Map, Value};

use crate::headers::Headers;
use crate::multipart;
use crate::params::{self, Params};
use crate::trusted::TrustedHeaders;

/// Headers Swoole keeps a single value of (a repeated one replaces it).
const SINGLE: [&str; 8] = [
    "host",
    "content-type",
    "connection",
    "user-agent",
    "accept",
    "accept-encoding",
    "authorization",
    "content-length",
];

/// Schemes a trusted proto header may name (`Request::SCHEMES`).
pub const SCHEMES: [&str; 4] = ["http", "https", "ws", "wss"];

/// How the body was encoded, decided by the original `Content-Type` (Swoole
/// parses form bodies while reading the request).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Form {
    None,
    Urlencoded,
    Multipart,
}

/// An `Range` or `Content-Range` header, parsed.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Range {
    pub unit: String,
    pub start: i64,
    /// `None` for an open range (`bytes=0-`).
    pub end: Option<i64>,
    /// The total size (`Content-Range` only).
    pub size: Option<i64>,
}

/// An incoming HTTP request.
#[derive(Debug)]
pub struct Request {
    method: String,
    uri: String,
    query_string: Option<String>,
    protocol: String,
    remote_addr: Option<String>,
    remote_port: Option<u16>,
    server_port: Option<u16>,
    time: f64,
    /// `setServer()` values and keys this adapter does not model.
    server: IndexMap<String, String>,
    headers: Headers,
    cookie_lines: Vec<String>,
    body: Bytes,
    form: Form,
    trusted: Arc<TrustedHeaders>,
    query: OnceLock<Params>,
    payload: OnceLock<Params>,
    post: OnceLock<(Params, Params)>,
    cookies: OnceLock<Params>,
}

impl Clone for Request {
    fn clone(&self) -> Self {
        let cell = |c: &OnceLock<Params>| {
            let n = OnceLock::new();
            if let Some(v) = c.get() {
                let _ = n.set(v.clone());
            }
            n
        };
        let post = OnceLock::new();
        if let Some(v) = self.post.get() {
            let _ = post.set(v.clone());
        }
        Self {
            method: self.method.clone(),
            uri: self.uri.clone(),
            query_string: self.query_string.clone(),
            protocol: self.protocol.clone(),
            remote_addr: self.remote_addr.clone(),
            remote_port: self.remote_port,
            server_port: self.server_port,
            time: self.time,
            server: self.server.clone(),
            headers: self.headers.clone(),
            cookie_lines: self.cookie_lines.clone(),
            body: self.body.clone(),
            form: self.form,
            trusted: self.trusted.clone(),
            query: cell(&self.query),
            payload: cell(&self.payload),
            post,
            cookies: cell(&self.cookies),
        }
    }
}

impl Request {
    /// A request for `method` and `target` (the request-line target: path and
    /// optional `?query`) with `headers` in arrival order and `body`.
    pub fn new<'a, I>(method: &str, target: &str, headers: I, body: Bytes) -> Self
    where
        I: IntoIterator<Item = (&'a str, &'a str)>,
    {
        let (uri, query_string) = match target.split_once('?') {
            Some((path, query)) => (path.to_owned(), Some(query.to_owned())),
            None => (target.to_owned(), None),
        };
        let mut map = Headers::with_capacity(16);
        let mut cookie_lines = Vec::new();
        let mut form = Form::None;
        for (name, value) in headers {
            let name = name.to_ascii_lowercase();
            if name == "cookie" {
                // Swoole parses cookies out of the headers (`http_parse_cookie`).
                cookie_lines.push(value.to_owned());
                continue;
            }
            if name == "content-type" && form == Form::None {
                form = form_kind(value);
            }
            // Swoole drops the whitespace before a value (hyper drops it around).
            let value = value.trim_start_matches([' ', '\t']);
            if SINGLE.contains(&name.as_str()) {
                // Headers Swoole keeps one value of: the last one wins.
                map.set(&name, value);
            } else {
                map.push_lowercase(name, value.to_owned());
            }
        }
        // Swoole parses form bodies of these methods only.
        if !matches!(method, "POST" | "PUT" | "PATCH" | "DELETE") {
            form = Form::None;
        }
        Self {
            method: method.to_owned(),
            uri,
            query_string,
            protocol: "HTTP/1.1".to_owned(),
            remote_addr: None,
            remote_port: None,
            server_port: None,
            time: now(),
            server: IndexMap::new(),
            headers: map,
            cookie_lines,
            body,
            form,
            trusted: Arc::new(TrustedHeaders::default()),
            query: OnceLock::new(),
            payload: OnceLock::new(),
            post: OnceLock::new(),
            cookies: OnceLock::new(),
        }
    }

    /// A request from hyper's parts.
    pub fn from_http(parts: &http::request::Parts, body: Bytes, remote: Option<std::net::SocketAddr>) -> Self {
        let target = parts.uri.path_and_query().map(|p| p.as_str()).unwrap_or("/");
        let headers = parts.headers.iter().map(|(n, v)| (n.as_str(), std::str::from_utf8(v.as_bytes()).unwrap_or("")));
        // Non-UTF-8 header values are decoded lossily.
        let lossy: Vec<(String, String)>;
        let mut request = if parts.headers.values().all(|v| std::str::from_utf8(v.as_bytes()).is_ok()) {
            Self::new(parts.method.as_str(), target, headers, body)
        } else {
            lossy = parts
                .headers
                .iter()
                .map(|(n, v)| (n.as_str().to_owned(), String::from_utf8_lossy(v.as_bytes()).into_owned()))
                .collect();
            Self::new(parts.method.as_str(), target, lossy.iter().map(|(n, v)| (n.as_str(), v.as_str())), body)
        };
        request.protocol = match parts.version {
            http::Version::HTTP_09 => "HTTP/0.9",
            http::Version::HTTP_10 => "HTTP/1.0",
            http::Version::HTTP_2 => "HTTP/2",
            http::Version::HTTP_3 => "HTTP/3",
            _ => "HTTP/1.1",
        }
        .to_owned();
        if let Some(remote) = remote {
            request.remote_addr = Some(remote.ip().to_string());
            request.remote_port = Some(remote.port());
        }
        request
    }

    /// Which forwarded headers to believe (set by the server adapter).
    pub fn with_trusted(mut self, trusted: Arc<TrustedHeaders>) -> Self {
        self.trusted = trusted;
        self
    }

    /// The client socket address (`$server['remote_addr']`).
    pub fn with_remote(mut self, addr: impl Into<String>, port: Option<u16>) -> Self {
        self.remote_addr = Some(addr.into());
        self.remote_port = port;
        self
    }

    /// The request-line protocol (`$server['server_protocol']`, `HTTP/1.1`).
    pub fn with_protocol(mut self, protocol: impl Into<String>) -> Self {
        self.protocol = protocol.into();
        self
    }

    /// The listening port (`$server['server_port']`).
    pub fn with_server_port(mut self, port: u16) -> Self {
        self.server_port = Some(port);
        self
    }

    // -- server ------------------------------------------------------------

    /// `getServer($key, $default)`.
    pub fn server(&self, key: &str) -> Option<String> {
        if let Some(v) = self.server.get(key) {
            return Some(v.clone());
        }
        match key {
            "request_method" => Some(self.method.clone()),
            "request_uri" => Some(self.uri.clone()),
            "path_info" => {
                Some(String::from_utf8_lossy(&php_std::encoding::rawurldecode(self.uri.as_bytes())).into_owned())
            }
            "query_string" => self.query_string.clone(),
            "server_protocol" => Some(self.protocol.clone()),
            "remote_addr" => self.remote_addr.clone(),
            "remote_port" => self.remote_port.map(|p| p.to_string()),
            "server_port" => self.server_port.map(|p| p.to_string()),
            "request_time" => Some((self.time as i64).to_string()),
            "request_time_float" => Some(php_std::number::to_string(self.time)),
            _ => None,
        }
    }

    /// `setServer($key, $value)`.
    pub fn set_server(&mut self, key: &str, value: impl Into<String>) -> &mut Self {
        let value = value.into();
        match key {
            "request_method" => self.method = value,
            "request_uri" => self.uri = value,
            _ => {
                self.server.insert(key.to_owned(), value);
            }
        }
        self
    }

    // -- request line ------------------------------------------------------

    /// `getMethod()`.
    pub fn method(&self) -> &str {
        &self.method
    }

    /// `setMethod($method)`.
    pub fn set_method(&mut self, method: impl Into<String>) -> &mut Self {
        self.method = method.into();
        self
    }

    /// `getURI()`: the request-line path, without the query string, as sent.
    pub fn uri(&self) -> &str {
        &self.uri
    }

    /// The path as sent (the request-line target without its query).
    pub fn path(&self) -> &str {
        &self.uri
    }

    /// The path the router matches (`Http::match()`): `parse_url($uri,
    /// PHP_URL_PATH)`, `/` when it has none. `//host/path` is a network
    /// path: only `/path` is matched.
    pub fn route_path(&self) -> std::borrow::Cow<'_, str> {
        // A plain absolute path is its own path; anything else goes through parse_url().
        let plain = self.uri.starts_with('/')
            && !self.uri.starts_with("//")
            && !self.uri.bytes().any(|b| matches!(b, b'#' | b'?' | b':' | b'@'));
        if plain {
            return std::borrow::Cow::Borrowed(&self.uri);
        }
        let path = php_std::url::parse_url(self.uri.as_bytes()).and_then(|u| u.path().map(|p| p.into_owned()));
        std::borrow::Cow::Owned(match path {
            Some(p) if !p.is_empty() => String::from_utf8_lossy(&p).into_owned(),
            _ => "/".to_owned(),
        })
    }

    /// `setURI($uri)`.
    pub fn set_uri(&mut self, uri: impl Into<String>) -> &mut Self {
        self.uri = uri.into();
        self
    }

    /// The raw query string, if the target had one.
    pub fn query_string(&self) -> Option<&str> {
        self.query_string.as_deref()
    }

    /// The request-line protocol (`HTTP/1.1`).
    pub fn version(&self) -> &str {
        &self.protocol
    }

    /// The client socket address.
    pub fn remote_addr(&self) -> Option<IpAddr> {
        self.remote_addr.as_deref().and_then(|a| a.parse().ok())
    }

    // -- headers -----------------------------------------------------------

    /// `getHeaders()`.
    pub fn headers(&self) -> &Headers {
        &self.headers
    }

    /// `hasHeader($key)`.
    pub fn has_header(&self, name: &str) -> bool {
        self.headers.has(name)
    }

    /// `getHeader($key)`: every value (empty when missing).
    pub fn header_values(&self, name: &str) -> &[String] {
        self.headers.get(name).unwrap_or(&[])
    }

    /// The first value of a header.
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers.first(name)
    }

    /// The first value of a header, or `""`.
    pub fn header_or_empty(&self, name: &str) -> &str {
        self.header(name).unwrap_or("")
    }

    /// `getHeaderLine($key, $default)`: the values joined with `", "`.
    pub fn header_line(&self, name: &str) -> Option<String> {
        self.headers.line(name)
    }

    /// `getHeaderLine($key, $default)` with its default.
    pub fn header_line_or(&self, name: &str, default: &str) -> String {
        self.headers.line(name).unwrap_or_else(|| default.to_owned())
    }

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

    /// `removeHeader($key)`.
    pub fn remove_header(&mut self, name: &str) -> &mut Self {
        self.headers.remove(name);
        self
    }

    /// `getReferer($default)`.
    pub fn referer(&self, default: &str) -> String {
        self.header_line_or("referer", default)
    }

    /// `getOrigin($default)`.
    pub fn origin(&self, default: &str) -> String {
        self.header_line_or("origin", default)
    }

    /// `getUserAgent($default)`.
    pub fn user_agent(&self, default: &str) -> String {
        self.header_line_or("user-agent", default)
    }

    /// `getAccept($default)`.
    pub fn accept(&self, default: &str) -> String {
        self.header_line_or("accept", default)
    }

    // -- client ------------------------------------------------------------

    /// `getIP()`: the first trusted header carrying a valid IP (its leftmost
    /// address), else the socket address, else `0.0.0.0`.
    pub fn ip(&self) -> String {
        for header in self.trusted.ip() {
            let Some(value) = self.headers.line(header) else { continue };
            if value.is_empty() || value == "0" {
                continue;
            }
            let first = value.split(',').next().unwrap_or("");
            let ip = php_std::string::trim(first.as_bytes(), b" \t\n\r\0\x0B");
            if valid_ip(ip) {
                return String::from_utf8_lossy(ip).into_owned();
            }
        }
        self.server("remote_addr").unwrap_or_else(|| "0.0.0.0".to_owned())
    }

    /// The scheme named by the first trusted proto header that names a known one.
    fn trusted_protocol(&self) -> Option<String> {
        for header in self.trusted.proto() {
            let Some(value) = self.headers.line(header) else { continue };
            if value.is_empty() {
                continue;
            }
            let first = value.split(',').next().unwrap_or("");
            let scheme = php_std::string::trim(first.as_bytes(), b" \t\n\r\0\x0B").to_ascii_lowercase();
            let scheme = String::from_utf8_lossy(&scheme).into_owned();
            if SCHEMES.contains(&scheme.as_str()) {
                return Some(scheme);
            }
        }
        None
    }

    /// `getProtocol()`: a trusted proto header, else what the request line says.
    pub fn protocol(&self) -> String {
        if let Some(trusted) = self.trusted_protocol() {
            return trusted;
        }
        let protocol = self.server("server_protocol").unwrap_or_else(|| "https".to_owned());
        if protocol == "HTTP/1.1" {
            return "http".to_owned();
        }
        match protocol.as_str() {
            "http" | "https" | "ws" | "wss" => protocol,
            _ => "https".to_owned(),
        }
    }

    fn authority(&self) -> String {
        let host = self.header_line_or("host", "");
        let host = self.header_line_or("x-forwarded-host", &host);
        format!("{}://{}", self.protocol(), host)
    }

    /// `getPort()`: `X-Forwarded-Port`, else the port of the (forwarded) host.
    pub fn port(&self) -> String {
        let authority = self.authority();
        let port = php_std::url::parse_url(authority.as_bytes())
            .and_then(|u| u.port())
            .map(|p| p.to_string())
            .unwrap_or_default();
        self.header_line_or("x-forwarded-port", &port)
    }

    /// `getHostname()`: the lowercased host of the (forwarded) host header.
    pub fn hostname(&self) -> String {
        let authority = self.authority();
        php_std::url::parse_url(authority.as_bytes())
            .and_then(|u| u.host().map(|h| h.to_ascii_lowercase()))
            .map(|h| String::from_utf8_lossy(&h).into_owned())
            .unwrap_or_default()
    }

    // -- cookies -----------------------------------------------------------

    /// `getCookieParams()`.
    pub fn cookies(&self) -> &Params {
        self.cookies.get_or_init(|| {
            let mut array = Array::new();
            let mut count = 0;
            for line in &self.cookie_lines {
                parse_cookie(line.as_bytes(), &mut array, &mut count);
            }
            params::array_to_params(&array)
        })
    }

    /// `getCookie($key)`: a cookie that is a string.
    pub fn cookie(&self, name: &str) -> Option<&str> {
        self.cookies().get(name).and_then(Value::as_str)
    }

    /// `setCookieParams($cookies)`.
    pub fn set_cookies(&mut self, cookies: Params) -> &mut Self {
        self.cookies = OnceLock::from(cookies);
        self
    }

    // -- params ------------------------------------------------------------

    fn post(&self) -> &(Params, Params) {
        self.post.get_or_init(|| match self.form {
            Form::Urlencoded => (params::parse_query_bytes(&self.body), Map::new()),
            Form::Multipart => {
                let ct = self.headers.first("content-type").unwrap_or("");
                multipart::parse(ct, &self.body)
            }
            Form::None => (Map::new(), Map::new()),
        })
    }

    /// The query string parameters (`$request->get`).
    pub fn query_params(&self) -> &Params {
        self.query.get_or_init(|| self.query_string.as_deref().map(params::parse_query).unwrap_or_default())
    }

    /// The body parameters: the JSON body, or the form fields.
    pub fn payload_params(&self) -> &Params {
        self.payload.get_or_init(|| {
            let ct = self.headers.line("content-type").unwrap_or_default();
            // substr($ct, 0, strpos($ct, ';') ?: strlen($ct))
            let ct = match ct.find(';') {
                Some(n) if n > 0 => &ct[..n],
                _ => ct.as_str(),
            };
            if ct == "application/json" { params::decode_payload(&self.body) } else { self.post().0.clone() }
        })
    }

    /// `getParams()`: the body for POST, PUT, PATCH and DELETE, the query
    /// string for every other method.
    pub fn params(&self) -> &Params {
        if self.has_body_params() { self.payload_params() } else { self.query_params() }
    }

    fn has_body_params(&self) -> bool {
        matches!(self.method.as_str(), "POST" | "PUT" | "PATCH" | "DELETE")
    }

    /// `getParam($key)` (a `null` value counts as missing).
    pub fn param(&self, key: &str) -> Option<&Value> {
        self.params().get(key).filter(|v| !v.is_null())
    }

    /// `getQuery($key)` (a `null` value counts as missing).
    pub fn query(&self, key: &str) -> Option<&Value> {
        self.query_params().get(key).filter(|v| !v.is_null())
    }

    /// A query string value as a string.
    pub fn query_value(&self, key: &str) -> Option<&str> {
        self.query_params().get(key).and_then(Value::as_str)
    }

    /// `getPayload($key)` (a `null` value counts as missing).
    pub fn payload(&self, key: &str) -> Option<&Value> {
        self.payload_params().get(key).filter(|v| !v.is_null())
    }

    /// `setQueryString($params)`.
    pub fn set_query(&mut self, params: Params) -> &mut Self {
        self.query = OnceLock::from(params);
        self
    }

    /// `setPayload($params)`.
    pub fn set_payload(&mut self, params: Params) -> &mut Self {
        self.payload = OnceLock::from(params);
        self
    }

    /// The request body as received.
    pub fn body(&self) -> &Bytes {
        &self.body
    }

    /// `getRawPayload()`: the body, except that a body of exactly `0` reads
    /// as empty (`$swoole->rawContent() ?: ''`; quirk kept).
    pub fn raw_payload(&self) -> &[u8] {
        if self.body.as_ref() == b"0" { b"" } else { &self.body }
    }

    /// `getFiles($key)`: the uploaded file(s) of a multipart field (field
    /// names are looked up lowercased, as the Swoole adapter does).
    pub fn files(&self, key: &str) -> Option<&Value> {
        self.post().1.get(&key.to_ascii_lowercase())
    }

    /// Every uploaded file, by field name.
    pub fn all_files(&self) -> &Params {
        &self.post().1
    }

    // -- size and ranges ---------------------------------------------------

    /// `getSize()`: the header lines (`name: v1, v2`, joined by `\n`). The
    /// body is not counted: under Swoole `php://input` is always empty.
    pub fn size(&self) -> usize {
        let mut total = 0usize;
        for (i, (name, values)) in self.headers.iter().enumerate() {
            if i > 0 {
                total += 1;
            }
            total += name.len() + 2;
            for (j, v) in values.iter().enumerate() {
                if j > 0 {
                    total += 2;
                }
                total += v.len();
            }
        }
        total
    }

    /// The `Content-Range` header: `getContentRangeUnit/Start/End/Size()`.
    pub fn content_range(&self) -> Option<Range> {
        let header = self.headers.line("content-range").unwrap_or_default();
        if php_std::value::empty(&Value::String(header.clone())) {
            return None;
        }
        let parts: Vec<&str> = header.split(' ').collect();
        if parts.len() != 2 {
            return None;
        }
        let unit = String::from_utf8_lossy(php_std::string::trim(parts[0].as_bytes(), b" \t\n\r\0\x0B")).into_owned();
        if unit.is_empty() || unit == "0" {
            return None;
        }
        let range: Vec<&str> = parts[1].split('/').collect();
        if range.len() != 2 || !php_std::string::ctype_digit(range[1].as_bytes()) {
            return None;
        }
        let size = int(range[1]);
        let bounds: Vec<&str> = range[0].split('-').collect();
        if bounds.len() != 2
            || !php_std::string::ctype_digit(bounds[0].as_bytes())
            || !php_std::string::ctype_digit(bounds[1].as_bytes())
        {
            return None;
        }
        let (start, end) = (int(bounds[0]), int(bounds[1]));
        if start > end || end > size {
            return None;
        }
        Some(Range { unit, start, end: Some(end), size: Some(size) })
    }

    /// The `Range` header: `getRangeUnit/Start/End()`.
    pub fn range(&self) -> Option<Range> {
        let header = self.headers.line("range").unwrap_or_default();
        if header.is_empty() || header == "0" {
            return None;
        }
        let parts: Vec<&str> = header.split('=').collect();
        if parts.len() != 2 || parts[0].is_empty() || parts[0] == "0" || parts[1].is_empty() || parts[1] == "0" {
            return None;
        }
        let unit = parts[0].to_owned();
        let bounds: Vec<&str> = parts[1].split('-').collect();
        if bounds.len() != 2 || bounds[0].is_empty() || !php_std::string::ctype_digit(bounds[0].as_bytes()) {
            return None;
        }
        let start = int(bounds[0]);
        let end = if bounds[1].is_empty() {
            None
        } else {
            if !php_std::string::ctype_digit(bounds[1].as_bytes()) {
                return None;
            }
            Some(int(bounds[1]))
        };
        if let Some(end) = end
            && start > end
        {
            return None;
        }
        Some(Range { unit, start, end, size: None })
    }
}

/// `(int) $digits`: saturates like PHP's string to int conversion.
fn int(digits: &str) -> i64 {
    php_std::format::str_to_int(digits.as_bytes())
}

fn now() -> f64 {
    std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs_f64()).unwrap_or(0.0)
}

/// Swoole's `Content-Type` sniffing: case-insensitive prefixes.
fn form_kind(content_type: &str) -> Form {
    let lower = content_type.as_bytes();
    let starts = |p: &str| lower.len() >= p.len() && lower[..p.len()].eq_ignore_ascii_case(p.as_bytes());
    if starts("application/x-www-form-urlencoded") {
        Form::Urlencoded
    } else if starts("multipart/form-data") {
        Form::Multipart
    } else {
        Form::None
    }
}

/// `filter_var($ip, FILTER_VALIDATE_IP)` is truthy.
fn valid_ip(ip: &[u8]) -> bool {
    use php_std::filter::{FILTER_VALIDATE_IP, Options, Value as F, filter_var};
    match filter_var(&F::Str(ip.to_vec()), FILTER_VALIDATE_IP, &Options::Flags(0)) {
        Ok(filtered) => matches!(filtered.value, F::Str(ref s) if !s.is_empty() && s.as_slice() != b"0"),
        Err(_) => false,
    }
}

/// Swoole's cookie parser: `php_default_treat_data(PARSE_COOKIE)` rules.
/// Pairs are separated by `;`, leading whitespace of names is skipped, names
/// are registered like PHP variables (first one wins at the top level) and
/// values are raw-URL-decoded.
fn parse_cookie(line: &[u8], array: &mut Array, count: &mut usize) {
    for pair in line.split(|&b| b == b';').filter(|p| !p.is_empty()) {
        let start = pair.iter().position(|b| !b.is_ascii_whitespace() && *b != 0x0B).unwrap_or(pair.len());
        let pair = &pair[start..];
        if pair.is_empty() || pair[0] == b'=' {
            continue;
        }
        *count += 1;
        if *count > php_std::encoding::MAX_INPUT_VARS {
            return;
        }
        let (name, value) = match pair.iter().position(|&b| b == b'=') {
            Some(eq) => (&pair[..eq], php_std::encoding::rawurldecode(&pair[eq + 1..])),
            None => (pair, Vec::new()),
        };
        php_std::encoding::register_variable(array, name, Zval::String(value), true);
    }
}
