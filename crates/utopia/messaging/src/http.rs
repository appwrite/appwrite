//! How adapters reach provider APIs: a [`Call`] (or a [`Multi`] of
//! concurrent calls) goes to a [`Requester`], which answers with a [`Reply`].
//!
//! The default requester, [`Http`], encodes the body the way the headers
//! say (JSON, form or multipart, as PHP's `Adapter::buildRequest()` does),
//! sends it through a [`Transport`] and decodes the answer
//! (`buildResult()`). A transport failure is a reply with status `0` and
//! the error, never an error of the send.

use std::fmt;
use std::future::Future;
use std::pin::Pin;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};

use indexmap::IndexMap;
use php_std::Value;
use utopia_client::{Anywhere, Destinations, PublicInternet};
use utopia_psr7::multipart::Part;
use utopia_psr7::{Message as _, request::Factory};

use crate::error::Error;

/// A boxed future, for the object-safe traits of this crate.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// The most requests a [`Multi`] keeps in flight.
///
/// PHP: `Adapter::MAX_CONCURRENT_REQUESTS`.
pub const MAX_CONCURRENT_REQUESTS: usize = 25;

/// One request an adapter makes.
///
/// PHP: the arguments of `Adapter::request()`.
#[derive(Debug, Clone)]
pub struct Call {
    /// The method (`POST`).
    pub method: String,
    /// The URL.
    pub url: String,
    /// Headers as `Name: value` lines. A line without a colon names no
    /// header, but still decides the body's encoding when it holds a content
    /// type (as Vonage's does).
    pub headers: Vec<String>,
    /// The body: a PHP array encoded as JSON, a form or multipart fields,
    /// as the headers say; `None` for no body.
    pub body: Option<Value>,
    /// Files sent as multipart parts after the body's fields.
    pub files: Vec<(String, Part)>,
    /// The whole-request timeout, in seconds.
    pub timeout: i64,
    /// The connect timeout, in seconds.
    pub connect_timeout: i64,
}

impl Call {
    /// A request with the default timeouts (30 seconds, 10 to connect).
    pub fn new(method: &str, url: impl Into<String>, headers: Vec<String>, body: Option<Value>) -> Call {
        Call {
            method: method.to_owned(),
            url: url.into(),
            headers,
            body,
            files: Vec::new(),
            timeout: 30,
            connect_timeout: 10,
        }
    }
}

/// Requests an adapter sends at once: one per URL and body, either side
/// repeated when it holds a single entry.
///
/// PHP: the arguments of `Adapter::requestMulti()`.
#[derive(Debug, Clone)]
pub struct Multi {
    /// The method.
    pub method: String,
    /// The URLs.
    pub urls: Vec<String>,
    /// Headers as `Name: value` lines, for every request.
    pub headers: Vec<String>,
    /// The bodies.
    pub bodies: Vec<Value>,
    /// The whole-request timeout, in seconds.
    pub timeout: i64,
    /// The connect timeout, in seconds.
    pub connect_timeout: i64,
}

impl Multi {
    /// Requests with the default timeouts.
    pub fn new(method: &str, urls: Vec<String>, headers: Vec<String>, bodies: Vec<Value>) -> Multi {
        Multi { method: method.to_owned(), urls, headers, bodies, timeout: 30, connect_timeout: 10 }
    }
}

/// A provider's answer, or the transport failure that stood in its way.
///
/// PHP: the array `request()` returns (`requestMulti()` adds `index`).
#[derive(Debug, Clone, PartialEq)]
pub struct Reply {
    /// Which request of a [`Multi`] this answers.
    pub index: Option<usize>,
    /// The URL requested.
    pub url: String,
    /// The HTTP status, `0` when no response came.
    pub status_code: i64,
    /// The body, decoded when it is JSON (`json_decode($body, true)`), the
    /// text otherwise; `null` without a response.
    pub response: Value,
    /// The response headers, names lower-cased, values joined.
    pub headers: IndexMap<String, String>,
    /// Why no response came, empty otherwise.
    pub error: String,
    /// The error's code (cURL's error number), `0` otherwise.
    pub error_code: i64,
}

impl Reply {
    /// A reply with a status and a decoded body.
    pub fn new(url: impl Into<String>, status_code: i64, response: Value) -> Reply {
        Reply {
            index: None,
            url: url.into(),
            status_code,
            response,
            headers: IndexMap::new(),
            error: String::new(),
            error_code: 0,
        }
    }

    /// A transport failure.
    pub fn failed(url: impl Into<String>, error: impl Into<String>, error_code: i64) -> Reply {
        Reply { error: error.into(), error_code, ..Reply::new(url, 0, Value::Null) }
    }

    /// Whether the status is 2xx.
    pub fn is_success(&self) -> bool {
        (200..300).contains(&self.status_code)
    }
}

/// Performs an adapter's requests. Implement it to answer them some other
/// way (canned replies in tests, a proxy, a queue).
///
/// PHP: the protected `request()` and `requestMulti()` of
/// `Utopia\Messaging\Adapter`, which subclasses override.
pub trait Requester: Send + Sync {
    /// Sends one request.
    ///
    /// PHP: `request()`.
    fn request<'a>(&'a self, call: Call) -> BoxFuture<'a, Result<Reply, Error>>;

    /// Sends requests concurrently. Without `on_result`, returns a reply
    /// per request, in request order. With it, hands each reply to
    /// `on_result` as its request completes, in completion order, and keeps
    /// and returns none: the first error `on_result` returns stops further
    /// requests, and is returned once those in flight are done and handed
    /// over too.
    ///
    /// PHP: `requestMulti()` and its `$onResult`.
    fn request_multi<'a>(
        &'a self,
        multi: Multi,
        on_result: Option<&'a OnResult<'a>>,
    ) -> BoxFuture<'a, Result<Vec<Reply>, Error>>;
}

/// Takes each reply of a [`Multi`] as its request completes.
///
/// PHP: the `$onResult` closure of `requestMulti()`.
pub type OnResult<'a> = dyn Fn(Reply) -> Result<(), Error> + Send + Sync + 'a;

/// Sends an HTTP request: any `utopia_client` client, or a transport of
/// your own.
///
/// PHP: the PSR-18 `ClientInterface` an adapter's `$clientFactory` returns.
pub trait Transport: Send + Sync {
    /// Sends the request; a 4xx or 5xx answer is a response.
    fn send<'a>(
        &'a self,
        request: utopia_client::Request,
    ) -> BoxFuture<'a, Result<utopia_client::Response, utopia_client::Error>>;
}

impl<A: utopia_client::Adapter + 'static> Transport for utopia_client::Client<A> {
    fn send<'a>(
        &'a self,
        request: utopia_client::Request,
    ) -> BoxFuture<'a, Result<utopia_client::Response, utopia_client::Error>> {
        Box::pin(utopia_client::Client::send(self, request))
    }
}

impl<T: Transport + ?Sized> Transport for Arc<T> {
    fn send<'a>(
        &'a self,
        request: utopia_client::Request,
    ) -> BoxFuture<'a, Result<utopia_client::Response, utopia_client::Error>> {
        (**self).send(request)
    }
}

/// Destinations shared between the clients a requester creates.
#[derive(Debug, Clone)]
struct Shared(Arc<dyn Destinations>);

impl Destinations for Shared {
    fn allows(&self, address: &str) -> bool {
        self.0.allows(address)
    }

    fn permits_proxy(&self) -> bool {
        self.0.permits_proxy()
    }
}

/// How the default requester reaches providers.
#[derive(Clone)]
enum Client {
    /// A new `utopia_client` client per request (per worker of a
    /// [`Multi`]), restricted to the destinations, or the HTTP/2 transport.
    Default { destinations: Arc<dyn Destinations>, http2: bool },
    /// The caller's transport.
    Custom(Arc<dyn Transport>),
}

/// The default [`Requester`]: encodes, sends and decodes over HTTP.
///
/// PHP: `Adapter::request()` and `requestMulti()`, with `buildRequest()`
/// and `buildResult()`.
#[derive(Clone)]
pub struct Http {
    user_agent: String,
    client: Client,
}

impl fmt::Debug for Http {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Http").field("user_agent", &self.user_agent).finish_non_exhaustive()
    }
}

impl Http {
    /// Requests for the adapter named `name` (its `User-Agent` is `Appwrite
    /// <name> Message Sender`), to the public internet only.
    ///
    /// PHP: the default client of `Adapter`, whose `destinations()` is
    /// `PublicInternet`.
    pub fn new(name: &str) -> Http {
        Http {
            user_agent: format!("Appwrite {name} Message Sender"),
            client: Client::Default { destinations: Arc::new(PublicInternet::default()), http2: false },
        }
    }

    /// Requests to any address: for an endpoint the operator configured.
    ///
    /// PHP: an adapter whose `destinations()` is `Anywhere`.
    pub fn anywhere(name: &str) -> Http {
        Http::new(name).with_destinations(Anywhere)
    }

    /// Where requests may connect.
    ///
    /// PHP: overriding `destinations()`.
    pub fn with_destinations(mut self, destinations: impl Destinations + 'static) -> Http {
        let http2 = matches!(self.client, Client::Default { http2: true, .. });
        self.client = Client::Default { destinations: Arc::new(destinations), http2 };
        self
    }

    /// Sends over HTTP/2 (TLS with ALPN `h2`), as APNs requires.
    ///
    /// PHP: the default cURL client asks for HTTP/2
    /// (`CURL_HTTP_VERSION_2_0`).
    pub fn with_http2(mut self) -> Http {
        if let Client::Default { http2, .. } = &mut self.client {
            *http2 = true;
        }
        self
    }

    /// Sends through `transport` instead, which owns its timeouts and
    /// destinations.
    ///
    /// PHP: the `$clientFactory` constructor argument.
    pub fn with_transport(mut self, transport: Arc<dyn Transport>) -> Http {
        self.client = Client::Custom(transport);
        self
    }

    fn transport(&self, timeout: i64, connect_timeout: i64, reuse: bool) -> Result<Arc<dyn Transport>, Error> {
        match &self.client {
            Client::Custom(transport) => Ok(transport.clone()),
            Client::Default { destinations, http2: true } => {
                Ok(Arc::new(crate::http2::Http2::new(Shared(destinations.clone()), timeout, connect_timeout)))
            }
            Client::Default { destinations, http2: false } => {
                let client = utopia_client::Client::new(utopia_client::Http::new())
                    .with_destinations(Shared(destinations.clone()))
                    .with_timeout(timeout as f64)
                    .and_then(|c| c.with_connect_timeout(connect_timeout as f64))
                    .map_err(client_error)?
                    .with_connection_reuse(reuse);
                Ok(Arc::new(client))
            }
        }
    }

    /// Builds the request a call describes.
    ///
    /// PHP: `buildRequest()`.
    pub fn build(&self, call: &Call) -> Result<utopia_client::Request, Error> {
        build_request(&self.user_agent, &call.method, &call.url, &call.headers, call.body.as_ref(), &call.files)
    }
}

impl Requester for Http {
    fn request<'a>(&'a self, call: Call) -> BoxFuture<'a, Result<Reply, Error>> {
        Box::pin(async move {
            let transport = self.transport(call.timeout, call.connect_timeout, false)?;
            let request = self.build(&call)?;
            match transport.send(request).await {
                Ok(response) => Ok(build_result(&response, call.url)),
                Err(e) if e.is_client() => Ok(Reply::failed(call.url, e.message(), curl_code(&e))),
                Err(e) => Err(client_error(e)),
            }
        })
    }

    fn request_multi<'a>(
        &'a self,
        multi: Multi,
        on_result: Option<&'a OnResult<'a>>,
    ) -> BoxFuture<'a, Result<Vec<Reply>, Error>> {
        Box::pin(async move {
            let Multi { method, urls, headers, bodies, timeout, connect_timeout } = multi;
            if urls.is_empty() {
                return Err(Error::Exception("No URLs provided. Must provide at least one URL.".into()));
            }
            let (url_count, body_count) = (urls.len(), bodies.len());
            if !(url_count == body_count || url_count == 1 || body_count == 1) {
                return Err(Error::Exception("URL and body counts must be equal or one must equal 1.".into()));
            }

            // Requests are built as they are sent, so a batch holds at most
            // one per worker rather than one per recipient.
            let count = url_count.max(body_count);
            let concurrency = count.clamp(1, MAX_CONCURRENT_REQUESTS);
            let next = AtomicUsize::new(0);
            let results: Mutex<Vec<Reply>> = Mutex::default();
            let failures: Mutex<Vec<Error>> = Mutex::default();
            let (method, urls, headers, bodies) = (&method, &urls, &headers, &bodies);
            let (next, results, failures) = (&next, &results, &failures);
            let workers = (0..concurrency).map(|_| async move {
                let transport = self.transport(timeout, connect_timeout, true);
                while locked(failures).is_empty() {
                    let index = next.fetch_add(1, Ordering::Relaxed);
                    if index >= count {
                        break;
                    }
                    let url = urls.get(index).unwrap_or(&urls[0]);
                    let body = bodies.get(index).or(bodies.first());
                    // Building, like acquiring a client and sending, fails
                    // into this request's reply.
                    let mut reply = match (build_request(&self.user_agent, method, url, headers, body, &[]), &transport)
                    {
                        (Err(e), _) => Reply::failed(url.clone(), e.message(), e.code()),
                        (Ok(_), Err(e)) => Reply::failed(url.clone(), e.message(), e.code()),
                        (Ok(request), Ok(transport)) => match transport.send(request).await {
                            Ok(response) => build_result(&response, url.clone()),
                            Err(e) => Reply::failed(url.clone(), e.message(), curl_code(&e)),
                        },
                    };
                    reply.index = Some(index);
                    match on_result {
                        None => locked(results).push(reply),
                        // Every worker stops, and the first error is returned
                        // once they are done.
                        Some(on_result) => {
                            if let Err(e) = on_result(reply) {
                                locked(failures).push(e);
                            }
                        }
                    }
                }
            });
            futures_util::future::join_all(workers).await;

            let mut failures = std::mem::take(&mut *locked(failures));
            if !failures.is_empty() {
                return Err(failures.swap_remove(0));
            }
            let mut replies = std::mem::take(&mut *locked(results));
            replies.sort_by_key(|r| r.index);
            Ok(replies)
        })
    }
}

/// The value behind a lock no holder can leave inconsistent.
fn locked<T>(mutex: &Mutex<T>) -> std::sync::MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

/// The error a send raises for a client error that is not a PSR-18 client
/// exception (configuration, a response header PHP cannot hold).
fn client_error(e: utopia_client::Error) -> Error {
    match e {
        utopia_client::Error::Type(m) => Error::Type(m),
        utopia_client::Error::Value(m) => Error::Value(m),
        utopia_client::Error::InvalidArgument(m) => Error::InvalidArgument(m),
        other => Error::Exception(other.message().to_owned()),
    }
}

/// The cURL error number PHP's exception carries for a transport failure
/// (`errorCode`), from the kind of failure: Rust's client reports the kind,
/// not cURL's number.
pub(crate) fn curl_code(e: &utopia_client::Error) -> i64 {
    use utopia_client::Error as E;
    match e {
        E::Dns(_) => 6,
        E::Connection(m) if m.contains("Empty reply") => 52,
        E::Connection(m) if m.starts_with("Failed to connect") => 7,
        E::Connection(m) if m.contains("Recv failure") => 56,
        E::Connection(_) => 0,
        E::Destination(_) => 7,
        E::Timeout(_) => 28,
        E::Tls(m) if m.contains("certificate") => 60,
        E::Tls(_) => 35,
        E::Proxy(_) => 5,
        E::Protocol(m) if m.starts_with("Maximum") => 47,
        E::Protocol(_) => 8,
        E::Network(m) if m.starts_with("URL rejected") => 3,
        _ => 0,
    }
}

/// `explode(':', $header, 2)` and `trim()` of each header line into PHP's
/// `$headerMap` (an assignment to an existing key replaces its value in
/// place).
fn header_map(headers: &[String], user_agent: &str) -> Vec<(String, String)> {
    const TRIM: &[u8] = b" \t\n\r\0\x0B";
    let mut map: Vec<(String, String)> = Vec::with_capacity(headers.len() + 1);
    for header in headers {
        if let Some((name, value)) = header.split_once(':') {
            let name = String::from_utf8_lossy(php_std::string::trim(name.as_bytes(), TRIM)).into_owned();
            let value = String::from_utf8_lossy(php_std::string::trim(value.as_bytes(), TRIM)).into_owned();
            match map.iter_mut().find(|(n, _)| *n == name) {
                Some(entry) => entry.1 = value,
                None => map.push((name, value)),
            }
        }
    }
    if !map.iter().any(|(n, _)| n.eq_ignore_ascii_case("user-agent")) {
        map.push(("User-Agent".to_owned(), user_agent.to_owned()));
    }
    map
}

/// PHP: `Adapter::buildRequest()`.
fn build_request(
    user_agent: &str,
    method: &str,
    url: &str,
    headers: &[String],
    body: Option<&Value>,
    files: &[(String, Part)],
) -> Result<utopia_client::Request, Error> {
    let factory = Factory::default();
    let mut map = header_map(headers, user_agent);
    let list = |map: &[(String, String)]| -> Vec<(String, Vec<String>)> {
        map.iter().map(|(n, v)| (n.clone(), vec![v.clone()])).collect()
    };
    fn borrowed(list: &[(String, Vec<String>)]) -> Vec<(&str, Vec<String>)> {
        list.iter().map(|(n, v)| (n.as_str(), v.clone())).collect()
    }

    let request = match body {
        None => {
            let owned = list(&map);
            factory.query(method, url, &serde_json::Value::Array(Vec::new()), &borrowed(&owned))?
        }
        Some(body) => {
            let json = headers.iter().position(|h| h.contains("application/json"));
            let form = headers.iter().position(|h| h.contains("application/x-www-form-urlencoded"));
            let owned = list(&map);
            match (json, form) {
                (Some(j), f) if f.is_none_or(|f| j <= f) => {
                    let encoded =
                        php_std::json::encode(body, php_std::json::Flags::THROW_ON_ERROR, php_std::json::DEFAULT_DEPTH)
                            .map_err(|e| Error::Json(e.message().to_owned()))?;
                    let request =
                        factory.body(method, url, encoded.as_bytes(), "application/json", &borrowed(&owned))?;
                    if request.has_header("Accept") {
                        request
                    } else {
                        request.with_header("Accept", "application/json")?
                    }
                }
                (_, Some(_)) => {
                    let data = body.to_json();
                    factory.form(method, url, &data, &borrowed(&owned))?
                }
                _ => {
                    map.retain(|(n, _)| !n.eq_ignore_ascii_case("content-type"));
                    let owned = list(&map);
                    let mut parts = fields(body)?;
                    parts.extend(files.iter().map(|(_, p)| p.clone()));
                    factory.multipart(method, url, parts, &borrowed(&owned))?
                }
            }
        }
    };

    let uri = request.uri().to_string();
    let mut out = utopia_client::Request::new(request.method(), &uri).map_err(client_error)?;
    for (name, values) in request.headers().iter() {
        out.headers_mut().set(name, values.iter().cloned()).map_err(client_error)?;
    }
    let bytes = request.body().to_bytes();
    Ok(out.body(bytes))
}

/// The multipart fields of a body: each value as `(string) $value`.
fn fields(body: &Value) -> Result<Vec<Part>, Error> {
    let Value::Array(array) = body else { return Ok(Vec::new()) };
    let mut parts = Vec::with_capacity(array.len());
    for (key, value) in array.iter() {
        let name = match key {
            php_std::types::KeyRef::Int(i) => i.to_string(),
            php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
        };
        let text = match value {
            Value::Array(_) => b"Array".to_vec(),
            other => other.to_php_string().map(|s| s.as_bytes().to_vec()).unwrap_or_default(),
        };
        parts.push(Part::field(&name, &text, Vec::new()));
    }
    Ok(parts)
}

/// PHP: `Adapter::buildResult()`.
pub(crate) fn build_result(response: &utopia_client::Response, url: String) -> Reply {
    let body = response.bytes();
    let decoded =
        php_std::json::decode(body, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::THROW_ON_ERROR)
            .unwrap_or_else(|_| Value::Str(php_std::Str::copy_from(body)));
    let mut headers = IndexMap::new();
    for (name, values) in response.headers().iter() {
        headers.insert(
            String::from_utf8_lossy(&php_std::string::strtolower(name.as_bytes())).into_owned(),
            values.join(", "),
        );
    }
    Reply {
        index: None,
        url,
        status_code: i64::from(response.status()),
        response: decoded,
        headers,
        error: String::new(),
        error_code: 0,
    }
}
