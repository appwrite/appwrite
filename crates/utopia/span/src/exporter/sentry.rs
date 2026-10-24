//! Errors as Sentry issues.

use std::sync::Arc;
use std::time::{SystemTime, UNIX_EPOCH};

use php_std::json::{self, Flags};
use php_std::{Array, ArrayKey, Value as Php};

use super::{Exporter, Sampler};
use crate::{Error, Failure, Level, Span, Value};

/// Where a span attribute goes in a Sentry event.
///
/// PHP: `Utopia\Span\Exporter\SentryField`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum SentryField {
    /// Indexed and searchable: for low-cardinality values (environment,
    /// version, tenant). Values are cut to 200 bytes.
    Tag,
    /// Not indexed; recommended for debugging data. The default.
    Context,
    /// Not indexed; deprecated by Sentry in favour of [`SentryField::Context`].
    Extra,
}

/// The levels of Sentry's event API.
///
/// PHP: `Utopia\Span\Exporter\Sentry\Level`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum SentryLevel {
    /// `debug`.
    Debug,
    /// `info`.
    Info,
    /// `warning`.
    Warning,
    /// `error`.
    Error,
    /// `fatal`.
    Fatal,
}

impl SentryLevel {
    /// The Sentry level of a span level (`warn` is `warning`).
    ///
    /// PHP: `Sentry\Level::fromSpan($level)`.
    ///
    /// ```
    /// use utopia_span::Level;
    /// use utopia_span::exporter::SentryLevel;
    ///
    /// assert_eq!(SentryLevel::from_span(Level::Warn).as_str(), "warning");
    /// ```
    ///
    /// ```php
    /// use Utopia\Span\Exporter\Sentry\Level as SentryLevel;
    /// use Utopia\Span\Level;
    ///
    /// echo SentryLevel::fromSpan(Level::Warn)->value, "\n"; // warning
    /// ```
    pub const fn from_span(level: Level) -> Self {
        match level {
            Level::Debug => SentryLevel::Debug,
            Level::Info => SentryLevel::Info,
            Level::Warn => SentryLevel::Warning,
            Level::Error => SentryLevel::Error,
            Level::Fatal => SentryLevel::Fatal,
        }
    }

    /// The level's name in Sentry's API.
    ///
    /// PHP: `$level->value`.
    pub const fn as_str(self) -> &'static str {
        match self {
            SentryLevel::Debug => "debug",
            SentryLevel::Info => "info",
            SentryLevel::Warning => "warning",
            SentryLevel::Error => "error",
            SentryLevel::Fatal => "fatal",
        }
    }
}

/// An HTTP request to Sentry's envelope endpoint.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    /// The method: `POST`.
    pub method: &'static str,
    /// The envelope endpoint, `{scheme}://{host}{:port}/api/{project}/envelope/`.
    pub url: String,
    /// `Content-Type` and `X-Sentry-Auth`.
    pub headers: Vec<(&'static str, String)>,
    /// The envelope: header, item header and event, one JSON document per line.
    pub body: Vec<u8>,
}

/// Sends envelopes to Sentry.
///
/// A transport reports its own failures: tracing never fails the
/// application.
///
/// PHP: the PSR-18 `ClientInterface` the exporter sends with.
pub trait Transport: Send + Sync {
    /// Sends one request.
    fn send(&self, request: Request);
}

impl<F: Fn(Request) + Send + Sync> Transport for F {
    fn send(&self, request: Request) {
        self(request)
    }
}

type Classifier = Box<dyn Fn(&str) -> SentryField + Send + Sync>;

/// Span levels Sentry receives.
const EXPORT_LEVELS: [Level; 3] = [Level::Warn, Level::Error, Level::Fatal];

/// Chained causes sent per event, at most.
const MAX_CHAIN_DEPTH: usize = 10;

/// Attributes mapped onto the event's `request` and `response`.
const HANDLED_HTTP_KEYS: [&str; 4] = ["http.url", "http.method", "http.query", "http.response.status_code"];

/// Reports spans at warning level or above that carry an error as Sentry
/// issues.
///
/// Each event carries the error and its chain of causes (at most 10, root
/// cause first), the trace context, `http.url`, `http.method` and
/// `http.query` as the request, `http.response.status_code` as the response,
/// and every other attribute (except `span.*` and `level`) as a context, tag
/// or extra, as the classifier decides. A boolean `span.handled` attribute
/// sets whether the error was handled; otherwise fatal spans are unhandled.
///
/// PHP: `Utopia\Span\Exporter\Sentry`. It sends with a PSR-18 client and
/// waits for the reply; here the default [`HttpTransport`] sends in the
/// background on the current runtime.
///
/// ```
/// use std::sync::{Arc, Mutex};
/// use utopia_span::exporter::Request;
/// use utopia_span::{Exporter, Failure, Level, Sentry, Span};
///
/// let sent = Arc::new(Mutex::new(Vec::<Request>::new()));
/// let log = sent.clone();
/// let sentry = Sentry::new("https://publickey@sentry.example.com:9000/123")?
///     .transport(move |request: Request| log.lock().unwrap().push(request));
///
/// let span = Span::new("test");
/// span.finish_with(Some(Level::Error), Some(Failure::new("RuntimeException", "Test error")));
/// assert!(sentry.sample(&span));
/// sentry.export(&span);
///
/// let request = &sent.lock().unwrap()[0];
/// assert_eq!(request.url, "https://sentry.example.com:9000/api/123/envelope/");
/// assert!(String::from_utf8_lossy(&request.body).contains(r#""value":"Test error""#));
/// # Ok::<(), utopia_span::Error>(())
/// ```
///
/// ```php
/// use Utopia\Span\Exporter\Sentry;
/// use Utopia\Span\Span;
///
/// $sentry = new Sentry(dsn: 'https://publickey@sentry.example.com:9000/123');
/// $span = new Span('test');
/// $span->finish(error: new \RuntimeException('Test error'));
/// var_dump($sentry->sample($span)); // bool(true)
/// // $sentry->export($span) POSTs to https://sentry.example.com:9000/api/123/envelope/
/// ```
pub struct Sentry {
    dsn: String,
    endpoint: String,
    public_key: String,
    environment: Option<String>,
    release: Option<String>,
    server_name: Option<String>,
    sampler: Option<Sampler>,
    classifier: Classifier,
    transport: Arc<dyn Transport>,
}

impl std::fmt::Debug for Sentry {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Sentry").field("endpoint", &self.endpoint).finish()
    }
}

fn text(bytes: Option<std::borrow::Cow<'_, [u8]>>) -> String {
    bytes.map(|b| String::from_utf8_lossy(&b).into_owned()).unwrap_or_default()
}

fn key(k: &str) -> ArrayKey {
    ArrayKey::from(k)
}

fn object(entries: Vec<(&str, Php)>) -> Php {
    let mut array = Array::default();
    for (k, v) in entries {
        array.set(key(k), v);
    }
    Php::Array(array)
}

impl Sentry {
    /// An exporter to the project of `dsn` (`https://<key>@<host>/<project>`).
    ///
    /// Fails with [`Error::InvalidArgument`] when the DSN is empty,
    /// malformed, or lacks the key, host or project.
    ///
    /// PHP: `new Sentry(dsn: $dsn)`.
    ///
    /// ```
    /// use utopia_span::Sentry;
    ///
    /// assert!(Sentry::new("https://key@sentry.io/123").is_ok());
    /// assert_eq!(Sentry::new("").unwrap_err().to_string(), "Sentry DSN is required");
    /// assert_eq!(
    ///     Sentry::new("https://sentry.io/123").unwrap_err().to_string(),
    ///     "Invalid Sentry DSN: must include public key, host, and project ID",
    /// );
    /// ```
    ///
    /// ```php
    /// use Utopia\Span\Exporter\Sentry;
    ///
    /// try {
    ///     new Sentry(dsn: 'https://sentry.io/123');
    /// } catch (\InvalidArgumentException $e) {
    ///     echo $e->getMessage(), "\n"; // Invalid Sentry DSN: must include public key, host, and project ID
    /// }
    /// ```
    pub fn new(dsn: &str) -> Result<Self, Error> {
        if dsn.is_empty() {
            return Err(Error::InvalidArgument("Sentry DSN is required"));
        }
        let url = php_std::url::parse_url(dsn.as_bytes()).ok_or(Error::InvalidArgument("Invalid Sentry DSN"))?;
        let public_key = text(url.user());
        let host = text(url.host());
        let path = text(url.path());
        let project = path.trim_start_matches('/');
        if public_key.is_empty() || host.is_empty() || project.is_empty() {
            return Err(Error::InvalidArgument("Invalid Sentry DSN: must include public key, host, and project ID"));
        }
        let scheme =
            url.scheme().map_or_else(|| "https".to_owned(), |s| String::from_utf8_lossy(&s).to_ascii_lowercase());
        // The request URI as an HTTP client normalises it: lowercase scheme
        // and host, no default port, the path percent-encoded.
        let port = match (scheme.as_str(), url.port()) {
            (_, None) | ("http", Some(80)) | ("https", Some(443)) => String::new(),
            (_, Some(p)) => format!(":{p}"),
        };
        let host = host.to_ascii_lowercase();
        Ok(Self {
            dsn: dsn.to_owned(),
            endpoint: format!("{scheme}://{host}{port}{}", encode_path(&format!("/api/{project}/envelope/"))),
            public_key,
            environment: None,
            release: None,
            server_name: None,
            sampler: None,
            classifier: Box::new(|_| SentryField::Context),
            transport: default_transport(),
        })
    }

    /// Exports only the spans `sampler` accepts, among those at warning level
    /// or above: it narrows what is sent, never widens it.
    ///
    /// PHP: the `$sampler` argument.
    pub fn sampler(mut self, sampler: impl Fn(&Span) -> bool + Send + Sync + 'static) -> Self {
        self.sampler = Some(Box::new(sampler));
        self
    }

    /// The environment events are tagged with (`production`).
    ///
    /// PHP: the `$environment` argument.
    pub fn environment(mut self, environment: impl Into<String>) -> Self {
        self.environment = Some(environment.into());
        self
    }

    /// The release events are tagged with (a version or commit).
    ///
    /// PHP: the `$release` argument.
    pub fn release(mut self, release: impl Into<String>) -> Self {
        self.release = Some(release.into());
        self
    }

    /// The server name events are tagged with.
    ///
    /// PHP: the `$serverName` argument.
    pub fn server_name(mut self, server_name: impl Into<String>) -> Self {
        self.server_name = Some(server_name.into());
        self
    }

    /// Decides where each attribute goes; the default puts every attribute in
    /// the event's context.
    ///
    /// PHP: the `$classifier` argument.
    pub fn classifier(mut self, classifier: impl Fn(&str) -> SentryField + Send + Sync + 'static) -> Self {
        self.classifier = Box::new(classifier);
        self
    }

    /// Sends envelopes with `transport` instead of the default HTTP client.
    ///
    /// PHP: the `$client` argument.
    pub fn transport(mut self, transport: impl Transport + 'static) -> Self {
        self.transport = Arc::new(transport);
        self
    }

    /// The request this exporter sends for `span`, or `None` when the span
    /// has no error or its event cannot be encoded.
    pub fn request(&self, span: &Span) -> Option<Request> {
        let body = self.envelope(span)?;
        Some(Request {
            method: "POST",
            url: self.endpoint.clone(),
            headers: vec![
                ("Content-Type", "application/x-sentry-envelope".to_owned()),
                ("X-Sentry-Auth", format!("Sentry sentry_version=7, sentry_key={}", self.public_key)),
            ],
            body,
        })
    }

    fn envelope(&self, span: &Span) -> Option<Vec<u8>> {
        let error = span.error()?;
        let attributes = span.attributes();
        let get = |k: &str| attributes.iter().find(|(key, _)| &**key == k).map(|(_, v)| v.clone()).unwrap_or_default();
        let now = || {
            let d = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
            d.as_secs() as f64 + f64::from(d.subsec_micros()) / 1e6
        };
        let or_now = |v: Value| if v.is_null() { now() } else { v.to_number() };

        let trace_id = get("span.trace_id").to_php_string();
        let span_id = get("span.id").to_php_string();
        let started = or_now(get("span.started_at"));
        let finished = or_now(get("span.finished_at"));

        let mut trace = vec![("trace_id", Php::Str(trace_id.clone().into())), ("span_id", Php::Str(span_id.into()))];
        if let Value::Str(parent) = get("span.parent_id") {
            trace.push(("parent_span_id", Php::Str(parent)));
        }

        let event_id: Vec<u8> = trace_id.iter().copied().filter(|&b| b != b'-').collect();
        let sent_at = SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| d.as_secs() as i64);
        let header = object(vec![
            ("event_id", Php::Str(event_id.into())),
            ("sent_at", Php::Str(php_std::datetime::date(b"c", sent_at, &php_std::datetime::TzInfo::utc()).into())),
            ("dsn", Php::from(self.dsn.as_str())),
        ]);
        let item = object(vec![("type", Php::from("event")), ("content_type", Php::from("application/json"))]);

        let mut contexts = vec![
            ("trace", object(trace)),
            (
                "runtime",
                object(vec![
                    ("name", Php::from("rust")),
                    ("version", Php::from(option_env!("CARGO_PKG_RUST_VERSION").unwrap_or("unknown"))),
                    ("sapi", Php::from("native")),
                ]),
            ),
        ];
        if let Value::Int(status) = get("http.response.status_code") {
            contexts.push(("response", object(vec![("status_code", Php::Int(status))])));
        }

        let mut tags = Array::default();
        let mut custom = Array::default();
        let mut extra = Array::default();
        for (k, value) in &attributes {
            if k.starts_with("span.") || &**k == "level" || HANDLED_HTTP_KEYS.contains(&&**k) {
                continue;
            }
            match (self.classifier)(k) {
                SentryField::Tag => {
                    let text = value.to_php_string();
                    tags.set(key(k), Php::Str(text[..text.len().min(200)].into()));
                }
                SentryField::Context => {
                    custom.set(key(k), value.to_php());
                }
                SentryField::Extra => {
                    extra.set(key(k), value.to_php());
                }
            }
        }
        if !custom.is_empty() {
            contexts.push(("custom", Php::Array(custom)));
        }

        let level = Level::parse(&get("level").to_php_string()).unwrap_or(Level::Error);
        let handled = get("span.handled").as_bool();
        let mut payload = vec![
            ("level", Php::from(SentryLevel::from_span(level).as_str())),
            ("platform", Php::from("php")),
            (
                "sdk",
                object(vec![("name", Php::from("utopia-php/span")), ("version", Php::from(env!("CARGO_PKG_VERSION")))]),
            ),
            ("start_timestamp", Php::Float(started)),
            ("timestamp", Php::Float(finished)),
            ("transaction", Php::from(span.action())),
            ("message", Php::Str(error.message_bytes().into())),
            ("contexts", object(contexts)),
            ("exception", object(vec![("values", exception_values(&error, level, handled))])),
        ];
        if !tags.is_empty() {
            payload.push(("tags", Php::Array(tags)));
        }
        if !extra.is_empty() {
            payload.push(("extra", Php::Array(extra)));
        }
        let mut request = Vec::new();
        for (attribute, field) in [("http.url", "url"), ("http.method", "method"), ("http.query", "query_string")] {
            if let Value::Str(s) = get(attribute) {
                request.push((field, Php::Str(s)));
            }
        }
        if !request.is_empty() {
            payload.push(("request", object(request)));
        }
        for (field, value) in
            [("environment", &self.environment), ("release", &self.release), ("server_name", &self.server_name)]
        {
            if let Some(value) = value {
                payload.push((field, Php::from(value.as_str())));
            }
        }

        let encode = |v: &Php| json::encode(v, Flags::NONE, json::DEFAULT_DEPTH).ok();
        let (header, item, payload) = (encode(&header)?, encode(&item)?, encode(&object(payload))?);
        Some(format!("{header}\n{item}\n{payload}").into_bytes())
    }
}

/// Percent-encodes what a URI path may not hold, as PSR-7 URIs do: bytes
/// outside the unreserved, sub-delimiter, `:`, `@`, `/` and `%` characters,
/// and a `%` not followed by two hex digits.
fn encode_path(path: &str) -> String {
    let bytes = path.as_bytes();
    let mut out = String::with_capacity(path.len());
    for (i, &b) in bytes.iter().enumerate() {
        let keep = b.is_ascii_alphanumeric()
            || b"_-.~!$&'()*+,;=:@/".contains(&b)
            || (b == b'%'
                && bytes.len() > i + 2
                && bytes[i + 1].is_ascii_hexdigit()
                && bytes[i + 2].is_ascii_hexdigit());
        if keep {
            out.push(b as char);
        } else {
            out.push_str(&format!("%{b:02X}"));
        }
    }
    out
}

/// The values of the event's `exception`: the error and its causes, root
/// cause first.
fn exception_values(error: &Failure, level: Level, handled: Option<bool>) -> Php {
    let mut chain = Vec::new();
    let mut current = Some(error);
    while let Some(e) = current {
        if chain.len() >= MAX_CHAIN_DEPTH {
            break;
        }
        chain.push(e);
        current = e.cause();
    }
    let handled = handled.unwrap_or(level != Level::Fatal);
    let chained = chain.len() > 1;
    let mut values: Vec<Php> = chain
        .iter()
        .enumerate()
        .map(|(id, exception)| {
            let mut mechanism = vec![("type", Php::from("generic")), ("handled", Php::Bool(handled))];
            if chained {
                mechanism.push(("exception_id", Php::Int(id as i64)));
                if id > 0 {
                    mechanism.push(("parent_id", Php::Int(id as i64 - 1)));
                    mechanism.push(("source", Php::from("__previous__")));
                }
            }
            let mut value = vec![
                ("type", Php::from(exception.class())),
                ("value", Php::Str(exception.message_bytes().into())),
                ("stacktrace", object(vec![("frames", frames(exception))])),
                ("mechanism", object(mechanism)),
            ];
            if let Some(end) = exception.class().rfind('\\') {
                value.push(("module", Php::from(&exception.class()[..end])));
            }
            object(value)
        })
        .collect();
    values.reverse();
    Php::Array(values.into_iter().collect())
}

fn function(frame: &crate::Frame) -> String {
    match &frame.class {
        Some(class) => format!("{class}{}{}", frame.call.as_deref().unwrap_or("::"), frame.function),
        None => frame.function.clone(),
    }
}

/// The stack frames of one error, outermost call first, ending with where it
/// was raised.
fn frames(error: &Failure) -> Php {
    let in_app = |file: &str| Php::Bool(!file.contains("/vendor/"));
    let mut frames: Array = error
        .frames()
        .iter()
        .rev()
        .filter_map(|frame| {
            let file = frame.file.as_deref()?;
            Some(object(vec![
                ("filename", Php::from(file)),
                ("lineno", Php::Int(frame.line.map_or(0, i64::from))),
                ("in_app", in_app(file)),
                ("function", Php::from(function(frame).as_str())),
            ]))
        })
        .collect();
    let mut site = vec![
        ("filename", Php::from(error.file())),
        ("lineno", Php::Int(i64::from(error.line()))),
        ("in_app", in_app(error.file())),
    ];
    if let Some(first) = error.frames().first() {
        site.push(("function", Php::from(function(first).as_str())));
    }
    let _ = frames.push(object(site));
    Php::Array(frames)
}

impl Exporter for Sentry {
    fn sample(&self, span: &Span) -> bool {
        let level = Level::parse(&span.get("level").to_php_string());
        if !level.is_some_and(|l| EXPORT_LEVELS.contains(&l)) {
            return false;
        }
        self.sampler.as_ref().is_none_or(|sampler| sampler(span))
    }

    fn export(&self, span: &Span) {
        if let Some(request) = self.request(span) {
            self.transport.send(request);
        }
    }
}

#[cfg(feature = "http")]
fn default_transport() -> Arc<dyn Transport> {
    Arc::new(HttpTransport::new())
}

#[cfg(not(feature = "http"))]
fn default_transport() -> Arc<dyn Transport> {
    Arc::new(|_request: Request| {})
}

#[cfg(feature = "http")]
pub use http::HttpTransport;

#[cfg(feature = "http")]
mod http {
    use std::time::Duration;

    use bytes::Bytes;
    use http_body_util::{BodyExt, Full};
    use hyper_rustls::HttpsConnector;
    use hyper_util::client::legacy::Client;
    use utopia_async::hyper::{Connector, Executor};

    use super::{Request, Transport};

    type HyperClient = Client<HttpsConnector<Connector>, Full<Bytes>>;

    /// Sends envelopes over HTTP/1.1 (TLS with the WebPKI roots) in the
    /// background: on the current runtime, or on a thread of its own
    /// outside one. Failures are written to standard error. The whole
    /// exchange times out after one second, the connection after half a
    /// second.
    ///
    /// PHP: the default `utopia-php/client` cURL client, with
    /// `CURLOPT_TIMEOUT_MS => 1000` and `CURLOPT_CONNECTTIMEOUT_MS => 500`;
    /// failures go to `error_log()`.
    #[derive(Debug, Default)]
    pub struct HttpTransport {
        client: std::sync::OnceLock<Option<HyperClient>>,
    }

    impl HttpTransport {
        /// A transport.
        pub fn new() -> Self {
            Self::default()
        }

        fn client(&self) -> Option<HyperClient> {
            self.client
                .get_or_init(|| {
                    let mut http = Connector::new();
                    http.set_connect_timeout(Some(Duration::from_millis(500)));
                    http.enforce_http(false);
                    let https = hyper_rustls::HttpsConnectorBuilder::new()
                        .with_provider_and_webpki_roots(rustls::crypto::ring::default_provider())
                        .ok()?
                        .https_or_http()
                        .enable_http1()
                        .wrap_connector(http);
                    Some(Client::builder(Executor).build(https))
                })
                .clone()
        }
    }

    async fn exchange(client: HyperClient, request: Request) -> Result<(), String> {
        let mut builder = hyper::Request::builder().method(request.method).uri(request.url.as_str());
        for (name, value) in &request.headers {
            builder = builder.header(*name, value.as_str());
        }
        let req = builder.body(Full::new(Bytes::from(request.body))).map_err(|e| e.to_string())?;
        let res = client.request(req).await.map_err(|e| e.to_string())?;
        let status = res.status().as_u16();
        if status >= 400 {
            let body = res.into_body().collect().await.map(|b| b.to_bytes()).unwrap_or_default();
            return Err(format!("HTTP {status} - {}", String::from_utf8_lossy(&body)));
        }
        Ok(())
    }

    impl Transport for HttpTransport {
        fn send(&self, request: Request) {
            let Some(client) = self.client() else {
                eprintln!("Sentry exporter: TLS setup failed");
                return;
            };
            let job = async move {
                let result = utopia_async::timeout(Duration::from_secs(1), exchange(client, request)).await;
                match result {
                    Ok(Ok(())) => {}
                    Ok(Err(message)) => eprintln!("Sentry exporter: {message}"),
                    Err(_) => eprintln!("Sentry exporter: Operation timed out after 1000 milliseconds"),
                }
            };
            match utopia_async::Handle::current() {
                Some(runtime) => {
                    runtime.spawn(job);
                }
                None => {
                    std::thread::spawn(move || {
                        if let Ok(runtime) = utopia_async::Builder::current_thread().build() {
                            runtime.block_on(job);
                        }
                    });
                }
            }
        }
    }
}
