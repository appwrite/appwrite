//! HTTP `request` / `request_multi` (PHP `Adapter` helpers via [`utopia-client`] + [`utopia-pools`]).

use std::collections::HashMap;
use std::sync::Arc;

use bytes::Bytes;
use http::{Method, Request};
use parking_lot::Mutex;
use serde_json::{Map, Value};
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};
use utopia_pools::{Pool, Recover, RecoverCall, Stack};

use crate::error::MessagingError;

/// Upper bound on concurrent `request_multi` sends (PHP `MAX_CONCURRENT_REQUESTS`).
pub const MAX_CONCURRENT_REQUESTS: usize = 25;

/// Factory producing HTTP clients (PHP `$clientFactory`).
pub type ClientFactory = Arc<dyn Fn(u64, u64) -> Arc<dyn HttpClient> + Send + Sync>;

/// Result shape adapters consume (PHP `buildResult`).
#[derive(Debug, Clone)]
pub struct HttpResult {
    /// Request URL.
    pub url: String,
    /// HTTP status, or `0` on transport error.
    pub status_code: i32,
    /// Parsed JSON object/array, or raw string, or JSON null.
    pub response: Value,
    /// Response headers with lowercase names.
    pub headers: HashMap<String, String>,
    /// Transport error message; empty on HTTP responses.
    pub error: String,
    /// Transport error code; `0` on HTTP responses.
    pub error_code: i32,
}

/// One `request_multi` slot (PHP result plus `index`).
#[derive(Debug, Clone)]
pub struct MultiResult {
    /// Original request index.
    pub index: usize,
    /// Underlying HTTP result.
    pub result: HttpResult,
}

/// A prepared outbound request.
#[derive(Debug, Clone)]
pub struct PreparedRequest {
    /// HTTP method.
    pub method: String,
    /// Target URL.
    pub url: String,
    /// Headers as `"Name: value"` strings (PHP `$headers`).
    pub headers: Vec<String>,
    /// Parsed header map (after User-Agent default).
    pub header_map: Vec<(String, String)>,
    /// JSON-compatible body (objects/arrays/nulls).
    pub body: Option<Value>,
    /// How to encode [`Self::body`].
    pub encoding: BodyEncoding,
}

/// Body encoding selected from Content-Type (PHP `buildRequest`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum BodyEncoding {
    /// `application/json`.
    Json,
    /// `application/x-www-form-urlencoded`.
    Form,
    /// `multipart/form-data`.
    Multipart,
    /// No body.
    None,
}

/// Pluggable HTTP backend (`utopia-client` by default; tests inject stubs / wiremock).
pub trait HttpClient: Send + Sync {
    /// Execute one request and map it to [`HttpResult`].
    fn execute(&self, request: &PreparedRequest) -> HttpResult;
}

/// Default HTTP backend: PHP `new Client(new CurlAdapter)`.
#[derive(Clone, Debug)]
pub struct UtopiaClient {
    inner: Client<curl::Client>,
}

/// Historical name kept so existing tests that name the default transport still compile.
pub type ReqwestClient = UtopiaClient;

impl UtopiaClient {
    /// Build with PHP `withTimeout` / `withConnectTimeout` (seconds).
    #[must_use]
    pub fn new(timeout_secs: u64, connect_timeout_secs: u64) -> Self {
        let inner = Client::new(curl::Client::new())
            .with_connection_reuse(true)
            .with_timeout(timeout_secs.max(1) as f64)
            .and_then(|client| client.with_connect_timeout(connect_timeout_secs.max(1) as f64))
            .unwrap_or_else(|_| Client::new(curl::Client::new()).with_connection_reuse(true));
        Self { inner }
    }
}

impl HttpClient for UtopiaClient {
    fn execute(&self, request: &PreparedRequest) -> HttpResult {
        execute_utopia(&self.inner, request)
    }
}

impl<T: HttpClient + ?Sized> HttpClient for Arc<T> {
    fn execute(&self, request: &PreparedRequest) -> HttpResult {
        (**self).execute(request)
    }
}

/// Sequence of canned responses for Resend/SES routing tests (PHP stub `request()`).
#[derive(Debug, Default)]
pub struct SequenceClient {
    /// Captured outbound requests.
    pub captured: Mutex<Vec<CapturedRequest>>,
    stubs: Mutex<Vec<StubResponse>>,
}

/// One recorded request.
#[derive(Debug, Clone)]
pub struct CapturedRequest {
    /// HTTP method.
    pub method: String,
    /// URL.
    pub url: String,
    /// `"Name: value"` headers.
    pub headers: Vec<String>,
    /// Body value.
    pub body: Option<Value>,
}

/// One canned HTTP response.
#[derive(Debug, Clone)]
pub struct StubResponse {
    /// Status code.
    pub status_code: i32,
    /// Body.
    pub response: Value,
    /// Lowercase response headers.
    pub headers: HashMap<String, String>,
}

impl SequenceClient {
    /// Create an empty sequence.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Push a canned response.
    pub fn push_stub(&self, stub: StubResponse) {
        self.stubs.lock().push(stub);
    }

    /// Snapshot captured requests.
    #[must_use]
    pub fn captured_requests(&self) -> Vec<CapturedRequest> {
        self.captured.lock().clone()
    }
}

impl HttpClient for SequenceClient {
    fn execute(&self, request: &PreparedRequest) -> HttpResult {
        self.captured.lock().push(CapturedRequest {
            method: request.method.clone(),
            url: request.url.clone(),
            headers: request.headers.clone(),
            body: request.body.clone(),
        });
        let stub = {
            let mut stubs = self.stubs.lock();
            if stubs.is_empty() {
                StubResponse {
                    status_code: 200,
                    response: Value::Object(Map::new()),
                    headers: HashMap::new(),
                }
            } else {
                stubs.remove(0)
            }
        };
        HttpResult {
            url: request.url.clone(),
            status_code: stub.status_code,
            response: stub.response,
            headers: stub.headers,
            error: String::new(),
            error_code: 0,
        }
    }
}

/// Always-200 client for benches (no network).
#[derive(Debug, Default, Clone, Copy)]
pub struct NoopClient;

impl HttpClient for NoopClient {
    fn execute(&self, request: &PreparedRequest) -> HttpResult {
        HttpResult {
            url: request.url.clone(),
            status_code: 200,
            response: Value::Object(Map::new()),
            headers: HashMap::new(),
            error: String::new(),
            error_code: 0,
        }
    }
}

/// Rewrite request URLs (host) so adapters with hardcoded hosts hit wiremock.
#[derive(Clone)]
pub struct RewriteClient {
    inner: Arc<dyn HttpClient>,
    /// `(original_prefix, replacement_base)` pairs.
    rewrites: Vec<(String, String)>,
    /// Original URLs as requested by adapters.
    pub originals: Arc<Mutex<Vec<String>>>,
}

impl std::fmt::Debug for RewriteClient {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RewriteClient")
            .field("rewrites", &self.rewrites)
            .finish_non_exhaustive()
    }
}

impl RewriteClient {
    /// Wrap an inner client with prefix rewrites.
    #[must_use]
    pub fn new(inner: Arc<dyn HttpClient>, rewrites: Vec<(String, String)>) -> Self {
        Self {
            inner,
            rewrites,
            originals: Arc::new(Mutex::new(Vec::new())),
        }
    }
}

impl HttpClient for RewriteClient {
    fn execute(&self, request: &PreparedRequest) -> HttpResult {
        self.originals.lock().push(request.url.clone());
        let mut rewritten = request.clone();
        for (from, to) in &self.rewrites {
            if let Some(rest) = rewritten.url.strip_prefix(from.as_str()) {
                rewritten.url = format!("{to}{rest}");
                break;
            }
        }
        let mut result = self.inner.execute(&rewritten);
        result.url.clone_from(&request.url);
        result
    }
}

pub(crate) fn default_factory() -> ClientFactory {
    Arc::new(|timeout, connect| {
        let client = UtopiaClient::new(timeout, connect);
        Arc::new(client)
    })
}

pub(crate) fn build_prepared(
    adapter_name: &str,
    method: &str,
    url: &str,
    headers: &[String],
    body: Option<Value>,
) -> PreparedRequest {
    let mut header_map: Vec<(String, String)> = Vec::new();
    for header in headers {
        if let Some((name, value)) = split_header(header) {
            header_map.push((name, value));
        } else if !header.is_empty() {
            // PHP foreach on an assoc array yields values only; Vonage passes
            // `['Content-Type' => 'application/x-www-form-urlencoded']`.
            header_map.push((String::new(), header.clone()));
        }
    }

    if !header_map
        .iter()
        .any(|(name, _)| name.eq_ignore_ascii_case("user-agent"))
    {
        header_map.push((
            "User-Agent".to_string(),
            format!("Appwrite {adapter_name} Message Sender"),
        ));
    }

    let encoding = if body.is_none() {
        BodyEncoding::None
    } else {
        detect_encoding(headers)
    };

    if encoding == BodyEncoding::Multipart {
        header_map.retain(|(name, _)| !name.eq_ignore_ascii_case("content-type"));
    }

    PreparedRequest {
        method: method.to_string(),
        url: url.to_string(),
        headers: headers.to_vec(),
        header_map,
        body,
        encoding,
    }
}

fn split_header(header: &str) -> Option<(String, String)> {
    let (name, value) = header.split_once(':')?;
    Some((name.trim().to_string(), value.trim().to_string()))
}

fn detect_encoding(headers: &[String]) -> BodyEncoding {
    for header in headers {
        if header.contains("application/json") {
            return BodyEncoding::Json;
        }
        if header.contains("application/x-www-form-urlencoded") {
            return BodyEncoding::Form;
        }
    }
    BodyEncoding::Multipart
}

fn execute_utopia(client: &Client<curl::Client>, request: &PreparedRequest) -> HttpResult {
    let method = request.method.parse::<Method>().unwrap_or(Method::POST);

    let mut builder = Request::builder().method(method).uri(&request.url);
    for (name, value) in &request.header_map {
        if name.is_empty() {
            continue;
        }
        builder = builder.header(name.as_str(), value.as_str());
    }

    let payload = match request.encoding {
        BodyEncoding::Json => {
            builder = builder.header("content-type", "application/json");
            match &request.body {
                Some(body) => match serde_json::to_vec(body) {
                    Ok(bytes) => bytes,
                    Err(error) => return transport_error(&request.url, &error.to_string()),
                },
                None => Vec::new(),
            }
        }
        BodyEncoding::Form => {
            builder = builder.header("content-type", "application/x-www-form-urlencoded");
            request
                .body
                .as_ref()
                .map(form_encode)
                .unwrap_or_default()
                .into_bytes()
        }
        BodyEncoding::Multipart => match &request.body {
            Some(body) => {
                let (bytes, content_type) = multipart_encode(body);
                builder = builder.header("content-type", content_type);
                bytes
            }
            None => Vec::new(),
        },
        BodyEncoding::None => Vec::new(),
    };

    let http_request = match builder.body(Bytes::from(payload)) {
        Ok(request) => request,
        Err(error) => return transport_error(&request.url, &error.to_string()),
    };
    match client.send_request(http_request) {
        Ok(response) => map_response(response, &request.url),
        Err(error) => transport_error(&request.url, &error.to_string()),
    }
}

fn map_response(response: http::Response<Bytes>, url: &str) -> HttpResult {
    let status_code = i32::from(response.status().as_u16());
    let mut headers = HashMap::new();
    for (name, value) in response.headers() {
        headers.insert(
            name.as_str().to_ascii_lowercase(),
            value.to_str().unwrap_or("").to_string(),
        );
    }
    let text = String::from_utf8_lossy(response.body()).into_owned();
    let parsed = serde_json::from_str::<Value>(&text).unwrap_or(Value::String(text));
    HttpResult {
        url: url.to_string(),
        status_code,
        response: parsed,
        headers,
        error: String::new(),
        error_code: 0,
    }
}

fn transport_error(url: &str, message: &str) -> HttpResult {
    HttpResult {
        url: url.to_string(),
        status_code: 0,
        response: Value::Null,
        headers: HashMap::new(),
        error: message.to_string(),
        error_code: 0,
    }
}

fn form_encode(body: &Value) -> String {
    let mut serializer = url::form_urlencoded::Serializer::new(String::new());
    match body {
        Value::Object(map) => {
            for (key, value) in map {
                push_form(&mut serializer, key, value);
            }
        }
        other => {
            serializer.append_pair("0", &value_to_form(other));
        }
    }
    serializer.finish()
}

fn push_form(
    serializer: &mut url::form_urlencoded::Serializer<'_, String>,
    key: &str,
    value: &Value,
) {
    match value {
        Value::Null => {
            serializer.append_pair(key, "");
        }
        Value::Array(items) => {
            for (index, item) in items.iter().enumerate() {
                push_form(serializer, &format!("{key}[{index}]"), item);
            }
        }
        Value::Object(map) => {
            for (child, item) in map {
                push_form(serializer, &format!("{key}[{child}]"), item);
            }
        }
        other => {
            serializer.append_pair(key, &value_to_form(other));
        }
    }
}

fn value_to_form(value: &Value) -> String {
    match value {
        Value::Null => String::new(),
        Value::Bool(true) => "1".into(),
        Value::Bool(false) => "0".into(),
        Value::Number(n) => n.to_string(),
        Value::String(s) => s.clone(),
        other => other.to_string(),
    }
}

fn multipart_encode(body: &Value) -> (Vec<u8>, String) {
    let boundary = format!("----UtopiaFormBoundary{}", rand_boundary());
    let mut out = Vec::new();
    match body {
        Value::Object(map) => {
            for (key, value) in map {
                write_multipart_field(&mut out, &boundary, key, value);
            }
        }
        other => write_multipart_field(&mut out, &boundary, "0", other),
    }
    out.extend_from_slice(format!("--{boundary}--\r\n").as_bytes());
    (out, format!("multipart/form-data; boundary={boundary}"))
}

fn write_multipart_field(out: &mut Vec<u8>, boundary: &str, name: &str, value: &Value) {
    out.extend_from_slice(format!("--{boundary}\r\n").as_bytes());
    out.extend_from_slice(
        format!("Content-Disposition: form-data; name=\"{name}\"\r\n\r\n").as_bytes(),
    );
    match value {
        Value::Null => {}
        Value::String(s) => out.extend_from_slice(s.as_bytes()),
        Value::Number(n) => out.extend_from_slice(n.to_string().as_bytes()),
        Value::Bool(b) => out.extend_from_slice(if *b { b"1" } else { b"0" }),
        other => out.extend_from_slice(other.to_string().as_bytes()),
    }
    out.extend_from_slice(b"\r\n");
}

fn rand_boundary() -> u64 {
    use rand::Rng;
    rand::thread_rng().gen()
}

/// Fan-out with at most [`MAX_CONCURRENT_REQUESTS`] concurrent workers.
pub fn run_multi(
    factory: &ClientFactory,
    method: &str,
    adapter_name: &str,
    urls: &[String],
    headers: &[String],
    bodies: &[Value],
    timeout: u64,
    connect_timeout: u64,
) -> Result<Vec<MultiResult>, MessagingError> {
    if urls.is_empty() {
        return Err(MessagingError::message(
            "No URLs provided. Must provide at least one URL.",
        ));
    }

    let url_count = urls.len();
    let body_count = bodies.len();
    if !(url_count == body_count || url_count == 1 || body_count == 1) {
        return Err(MessagingError::message(
            "URL and body counts must be equal or one must equal 1.",
        ));
    }

    let mut urls = urls.to_vec();
    let mut bodies = bodies.to_vec();
    if body_count > 0 && url_count > body_count {
        let first = bodies[0].clone();
        bodies.resize(url_count, first);
    } else if url_count < body_count {
        let first = urls[0].clone();
        urls.resize(body_count, first);
    }

    let mut prepared = Vec::with_capacity(urls.len());
    for (i, url) in urls.iter().enumerate() {
        let body = bodies.get(i).cloned();
        prepared.push(build_prepared(adapter_name, method, url, headers, body));
    }

    let pool = Pool::new(
        Stack::new(),
        "messaging-http",
        MAX_CONCURRENT_REQUESTS.min(prepared.len()).max(1),
        {
            let factory = Arc::clone(factory);
            move || PooledHttp(factory(timeout, connect_timeout))
        },
        30.0,
    )
    .map_err(|error| MessagingError::message(error.to_string()))?;

    let jobs: Vec<_> = prepared
        .into_iter()
        .map(|req| {
            let pool = pool.clone();
            move || {
                pool.use_sync(|client| client.0.execute(&req))
                    .unwrap_or_else(|error| transport_error(&req.url, &error.to_string()))
            }
        })
        .collect();

    let raw = bounded_run(MAX_CONCURRENT_REQUESTS, jobs);
    Ok(raw
        .into_iter()
        .enumerate()
        .map(|(index, result)| MultiResult { index, result })
        .collect())
}

fn bounded_run<T, F>(limit: usize, jobs: Vec<F>) -> Vec<T>
where
    T: Send,
    F: FnOnce() -> T + Send,
{
    let n = jobs.len();
    if n == 0 {
        return Vec::new();
    }
    let limit = limit.max(1).min(n);
    let results = Arc::new(Mutex::new((0..n).map(|_| None).collect::<Vec<Option<T>>>()));
    let (slot_tx, slot_rx) = std::sync::mpsc::sync_channel(limit);
    for _ in 0..limit {
        let _ = slot_tx.send(());
    }
    std::thread::scope(|scope| {
        for (i, job) in jobs.into_iter().enumerate() {
            slot_rx.recv().expect("http slot");
            let slot_tx = slot_tx.clone();
            let results = Arc::clone(&results);
            scope.spawn(move || {
                let value = job();
                results.lock()[i] = Some(value);
                let _ = slot_tx.send(());
            });
        }
    });
    let mut guard = results.lock();
    guard
        .iter_mut()
        .map(|slot| slot.take().expect("http job"))
        .collect()
}

struct PooledHttp(Arc<dyn HttpClient>);

impl Recover for PooledHttp {
    fn reset(&mut self) -> RecoverCall {
        RecoverCall::Succeeded
    }
}
