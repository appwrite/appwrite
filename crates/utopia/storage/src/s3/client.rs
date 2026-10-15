//! The HTTP client [`S3`](crate::S3) sends its requests through.

use std::io;
use std::sync::Arc;
use std::time::Duration;

use async_trait::async_trait;
use bytes::Bytes;
use futures_util::stream::{self, BoxStream, StreamExt, TryStreamExt};
use utopia_async::sync::{Mutex, OwnedMutexGuard};

use super::retry::RetryStrategy;
use crate::{Body, Source};

/// How much of a streamed payload is read at a time: PHP's
/// `PIPE_CHUNK_SIZE`.
pub(crate) const CHUNK_SIZE: usize = 524_288;

/// An HTTP method S3 requests use.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Method {
    /// `GET`.
    Get,
    /// `HEAD`.
    Head,
    /// `PUT`.
    Put,
    /// `POST`.
    Post,
    /// `DELETE`.
    Delete,
}

impl Method {
    /// The method's name, such as `PUT`.
    pub const fn as_str(self) -> &'static str {
        match self {
            Method::Get => "GET",
            Method::Head => "HEAD",
            Method::Put => "PUT",
            Method::Post => "POST",
            Method::Delete => "DELETE",
        }
    }
}

/// A request, as a [`Client`] sends it.
///
/// PHP: the PSR-7 `Utopia\Psr7\Request` `S3::call()` builds.
#[derive(Debug, Clone)]
pub struct Request {
    /// The method.
    pub method: Method,
    /// The URL: scheme, authority, path and query.
    pub url: String,
    /// Header names (lowercase) and values, in the order they were set.
    pub headers: Vec<(String, String)>,
    /// The body.
    pub body: Payload,
}

impl Request {
    /// The value of a header (case-insensitive name).
    pub fn header(&self, name: &str) -> Option<&str> {
        header(&self.headers, name)
    }

    /// The query string of the URL, without its `?`.
    pub fn query(&self) -> &str {
        self.url.split_once('?').map(|(_, q)| q.split('#').next().unwrap_or_default()).unwrap_or_default()
    }
}

/// The body of a [`Request`]: bytes in memory, or a seekable [`Body`]
/// streamed from its start each time the request is sent, so a retry sends
/// the same bytes and only one chunk of a large upload is in memory at a
/// time.
///
/// ```
/// use utopia_storage::Body;
/// use utopia_storage::s3::Payload;
///
/// # utopia_async::Builder::current_thread().build().unwrap().block_on(async {
/// let payload = Payload::from_body(Body::from("Hello World"));
/// assert_eq!(&payload.bytes().await?[..], b"Hello World");
/// assert_eq!(&payload.bytes().await?[..], b"Hello World"); // read again from the start
/// # Ok::<(), std::io::Error>(())
/// # }).unwrap();
/// ```
///
/// ```php
/// use Utopia\Psr7\Stream;
///
/// $body = new Stream('Hello World');
/// echo $body->getContents(), "\n"; // Hello World
/// $body->rewind();
/// echo $body->getContents(), "\n"; // Hello World
/// ```
///
/// PHP: the PSR-7 `StreamInterface` body of the request, which the cURL
/// adapter rewinds before each send.
#[derive(Clone, Default)]
pub struct Payload(Contents);

#[derive(Clone)]
enum Contents {
    Bytes(Bytes),
    /// Shared by every attempt at the request, one at a time: each locks
    /// it, rewinds it and reads it to the end.
    Body(Arc<Mutex<Body>>),
}

impl Default for Contents {
    fn default() -> Self {
        Contents::Bytes(Bytes::new())
    }
}

impl Payload {
    /// A payload streamed from `body`, which must be seekable: every send
    /// rewinds it first.
    pub fn from_body(body: Body) -> Self {
        Payload(Contents::Body(Arc::new(Mutex::new(body))))
    }

    /// The payload as a stream of chunks, from its start. A streamed body
    /// stays locked until the stream ends or is dropped, so a retry waits
    /// for the attempt before it to let go.
    pub fn chunks(&self) -> BoxStream<'static, io::Result<Bytes>> {
        enum State {
            Start(Arc<Mutex<Body>>),
            Reading(OwnedMutexGuard<Body>),
            Done,
        }
        match &self.0 {
            Contents::Bytes(bytes) if bytes.is_empty() => stream::empty().boxed(),
            Contents::Bytes(bytes) => stream::once(std::future::ready(Ok(bytes.clone()))).boxed(),
            Contents::Body(body) => stream::unfold(State::Start(body.clone()), |state| async move {
                let mut body = match state {
                    State::Start(body) => {
                        let mut body = body.lock_owned().await;
                        if let Err(e) = body.rewind().await {
                            return Some((Err(e), State::Done));
                        }
                        body
                    }
                    State::Reading(body) => body,
                    State::Done => return None,
                };
                match body.read(CHUNK_SIZE).await {
                    Ok(chunk) if chunk.is_empty() => None,
                    Ok(chunk) => Some((Ok(chunk), State::Reading(body))),
                    Err(e) => Some((Err(e), State::Done)),
                }
            })
            .boxed(),
        }
    }

    /// The whole payload, from its start.
    pub async fn bytes(&self) -> io::Result<Bytes> {
        if let Contents::Bytes(bytes) = &self.0 {
            return Ok(bytes.clone());
        }
        let chunks: Vec<Bytes> = self.chunks().try_collect().await?;
        Ok(match <[Bytes; 1]>::try_from(chunks) {
            Ok([chunk]) => chunk,
            Err(chunks) => Bytes::from(chunks.concat()),
        })
    }

    /// The payload as a hyper request body.
    fn outgoing(&self) -> Outgoing {
        use http_body_util::BodyExt;
        match &self.0 {
            Contents::Bytes(bytes) => http_body_util::Full::new(bytes.clone()).map_err(|e| match e {}).boxed_unsync(),
            Contents::Body(_) => {
                http_body_util::StreamBody::new(self.chunks().map_ok(hyper::body::Frame::data)).boxed_unsync()
            }
        }
    }
}

impl std::fmt::Debug for Payload {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match &self.0 {
            Contents::Bytes(bytes) => f.debug_tuple("Payload").field(bytes).finish(),
            Contents::Body(_) => f.debug_tuple("Payload").field(&"streamed").finish(),
        }
    }
}

impl From<Bytes> for Payload {
    fn from(bytes: Bytes) -> Self {
        Payload(Contents::Bytes(bytes))
    }
}

/// A response, as a [`Client`] returns it: its body in memory (`Bytes`, from
/// [`Client::send`]) or still to read ([`Body`], from [`Client::stream`]).
#[derive(Debug, Clone, Default)]
pub struct Response<B = Bytes> {
    /// The status code.
    pub status: u16,
    /// Header names and values, in the order received; repeated headers
    /// appear once per value.
    pub headers: Vec<(String, String)>,
    /// The body.
    pub body: B,
}

impl Response {
    /// A response with this status, no headers and no body.
    pub fn new(status: u16) -> Self {
        Response { status, ..Response::default() }
    }

    /// The response with this body.
    pub fn with_body(mut self, body: impl Into<Bytes>) -> Self {
        self.body = body.into();
        self
    }
}

impl<B> Response<B> {
    /// The response with a header added.
    pub fn with_header(mut self, name: impl Into<String>, value: impl Into<String>) -> Self {
        self.headers.push((name.into(), value.into()));
        self
    }

    /// The values of a header (case-insensitive name), joined by `, `.
    ///
    /// PHP: `getHeaderLine()`.
    pub fn header_line(&self, name: &str) -> String {
        self.headers
            .iter()
            .filter(|(k, _)| k.eq_ignore_ascii_case(name))
            .map(|(_, v)| v.as_str())
            .collect::<Vec<_>>()
            .join(", ")
    }
}

pub(crate) fn header<'a>(headers: &'a [(String, String)], name: &str) -> Option<&'a str> {
    headers.iter().find(|(k, _)| k.eq_ignore_ascii_case(name)).map(|(_, v)| v.as_str())
}

/// Why a request got no response.
///
/// PHP: the `utopia-php/client` exceptions a PSR-18 client throws, each
/// with its message and error code (a cURL or socket error number).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum TransportError {
    /// The host name did not resolve.
    ///
    /// PHP: `Utopia\Client\Exception\DnsException`.
    #[error("{message}")]
    Dns {
        /// The message.
        message: String,
        /// The error number.
        code: i64,
    },
    /// The connection failed or dropped.
    ///
    /// PHP: `Utopia\Client\Exception\ConnectionException`.
    #[error("{message}")]
    Connection {
        /// The message.
        message: String,
        /// The error number: `CURLE_COULDNT_CONNECT` (7) or `ECONNREFUSED`
        /// (111) when nothing was sent.
        code: i64,
    },
    /// The request took too long.
    ///
    /// PHP: `Utopia\Client\Exception\TimeoutException`.
    #[error("{message}")]
    Timeout {
        /// The message.
        message: String,
        /// The error number.
        code: i64,
    },
    /// The TLS handshake failed.
    ///
    /// PHP: `Utopia\Client\Exception\TlsException`.
    #[error("{message}")]
    Tls {
        /// The message.
        message: String,
        /// The error number.
        code: i64,
    },
    /// Any other network failure.
    ///
    /// PHP: `Utopia\Client\Exception\NetworkException`.
    #[error("{message}")]
    Network {
        /// The message.
        message: String,
        /// The error number.
        code: i64,
    },
}

impl TransportError {
    /// The error number.
    pub fn code(&self) -> i64 {
        match self {
            TransportError::Dns { code, .. }
            | TransportError::Connection { code, .. }
            | TransportError::Timeout { code, .. }
            | TransportError::Tls { code, .. }
            | TransportError::Network { code, .. } => *code,
        }
    }
}

/// Sends HTTP requests. Implement it to route S3 traffic through a client of
/// your own (a proxy, a test double).
///
/// PHP: the PSR-18 `ClientInterface&StreamingClientInterface` passed to
/// `new S3(client: ...)`.
#[async_trait]
pub trait Client: Send + Sync {
    /// Sends a request and returns its response, whatever its status, with
    /// the body read whole.
    ///
    /// PHP: `sendRequest()`.
    async fn send(&self, request: &Request) -> Result<Response, TransportError>;

    /// Sends a request and returns its response, whatever its status, as
    /// soon as its head arrives: the body is read as the caller reads it.
    /// By default the body is read whole by [`Client::send`] first.
    ///
    /// PHP: `StreamingClientInterface::stream()`.
    async fn stream(&self, request: &Request) -> Result<Response<Body>, TransportError> {
        let Response { status, headers, body } = self.send(request).await?;
        Ok(Response { status, headers, body: Body::from(body) })
    }
}

#[async_trait]
impl<C: Client + ?Sized> Client for std::sync::Arc<C> {
    async fn send(&self, request: &Request) -> Result<Response, TransportError> {
        (**self).send(request).await
    }

    async fn stream(&self, request: &Request) -> Result<Response<Body>, TransportError> {
        (**self).stream(request).await
    }
}

/// A client that retries what a [`RetryStrategy`] says to. Each attempt
/// sends the request's [`Payload`] again from its start.
///
/// PHP: `Utopia\Client\Decorator\Retry`.
pub struct Retry<C> {
    inner: C,
    strategy: RetryStrategy,
}

impl<C: Client> Retry<C> {
    /// `inner`, retried by `strategy`.
    pub fn new(inner: C, strategy: RetryStrategy) -> Self {
        Retry { inner, strategy }
    }

    /// Waits before attempt `attempt + 1`, or says to give up.
    async fn wait(&self, delay: Option<f64>) -> bool {
        let Some(delay) = delay else { return false };
        if delay > 0.0 {
            utopia_async::sleep(Duration::from_secs_f64(delay)).await;
        }
        true
    }
}

#[async_trait]
impl<C: Client> Client for Retry<C> {
    async fn send(&self, request: &Request) -> Result<Response, TransportError> {
        let mut attempt = 1;
        loop {
            let result = self.inner.send(request).await;
            let delay = match &result {
                Ok(response) => self.strategy.delay(request, attempt, Some(response), None),
                Err(error) => self.strategy.delay(request, attempt, None, Some(error)),
            };
            if !self.wait(delay).await {
                return result;
            }
            attempt += 1;
        }
    }

    /// Decides on each attempt before its body is handed over: an error
    /// response (400 and up) is small XML, read whole so the strategy sees
    /// its error code; any other keeps streaming and its status alone
    /// decides (the strategy retries none below 400).
    async fn stream(&self, request: &Request) -> Result<Response<Body>, TransportError> {
        let mut attempt = 1;
        loop {
            let result = match self.inner.stream(request).await {
                Ok(Response { status, headers, body }) if status >= 400 => match body.bytes().await {
                    Ok(body) => Ok(Response { status, headers, body }),
                    Err(e) => Err(TransportError::Connection { message: e.to_string(), code: 56 }),
                }
                .map(|response| (response, None)),
                Ok(Response { status, headers, body }) => {
                    Ok((Response { status, headers, body: Bytes::new() }, Some(body)))
                }
                Err(error) => Err(error),
            };
            let delay = match &result {
                Ok((head, _)) => self.strategy.delay(request, attempt, Some(head), None),
                Err(error) => self.strategy.delay(request, attempt, None, Some(error)),
            };
            if !self.wait(delay).await {
                return result.map(|(Response { status, headers, body }, rest)| Response {
                    status,
                    headers,
                    body: rest.unwrap_or_else(|| Body::from(body)),
                });
            }
            attempt += 1;
        }
    }
}

/// What [`HttpClient`] sends: a [`Payload`] as a hyper body.
type Outgoing = http_body_util::combinators::UnsyncBoxBody<Bytes, io::Error>;

/// The default client: HTTP/1.1 over plain TCP or TLS (the web PKI's
/// roots), keeping connections alive, with no overall deadline. Request
/// payloads go out chunk by chunk, and [`Client::stream`] hands the
/// response body over as it arrives.
///
/// PHP: `utopia-php/client` with the cURL adapter, which `S3` wraps in
/// `Retry` with `S3\RetryStrategy` unless given a client.
pub struct HttpClient {
    inner: hyper_util::client::legacy::Client<hyper_rustls::HttpsConnector<utopia_async::hyper::Connector>, Outgoing>,
}

impl Default for HttpClient {
    fn default() -> Self {
        Self::new()
    }
}

impl HttpClient {
    /// A client with its own connection pool.
    pub fn new() -> Self {
        let mut http = utopia_async::hyper::Connector::new();
        http.enforce_http(false);
        http.set_keepalive(Some(Duration::from_secs(60)));
        let tls = hyper_rustls::HttpsConnectorBuilder::new()
            .with_webpki_roots()
            .https_or_http()
            .enable_http1()
            .wrap_connector(http);
        let inner = hyper_util::client::legacy::Client::builder(utopia_async::hyper::Executor).build(tls);
        HttpClient { inner }
    }

    /// Sends a request and waits for the head of its response.
    async fn request(
        &self,
        request: &Request,
    ) -> Result<(u16, Vec<(String, String)>, hyper::body::Incoming), TransportError> {
        let method = match request.method {
            Method::Get => hyper::Method::GET,
            Method::Head => hyper::Method::HEAD,
            Method::Put => hyper::Method::PUT,
            Method::Post => hyper::Method::POST,
            Method::Delete => hyper::Method::DELETE,
        };
        let mut builder = hyper::Request::builder().method(method).uri(&request.url);
        for (name, value) in &request.headers {
            builder = builder.header(name.as_str(), value.as_str());
        }
        let http = builder
            .body(request.body.outgoing())
            .map_err(|e| TransportError::Network { message: e.to_string(), code: 0 })?;
        let response = self.inner.request(http).await.map_err(|e| {
            let message = e.to_string();
            if e.is_connect() {
                let source = std::error::Error::source(&e).map(|s| s.to_string()).unwrap_or_default();
                if source.contains("dns") || source.contains("resolve") || source.contains("lookup") {
                    TransportError::Dns { message: format!("{message}: {source}"), code: 6 }
                } else if source.contains("tls") || source.contains("certificate") {
                    TransportError::Tls { message: format!("{message}: {source}"), code: 35 }
                } else {
                    TransportError::Connection { message: format!("{message}: {source}"), code: 7 }
                }
            } else {
                TransportError::Connection { message, code: 56 }
            }
        })?;
        let status = response.status().as_u16();
        let headers = response
            .headers()
            .iter()
            .map(|(k, v)| (k.as_str().to_owned(), String::from_utf8_lossy(v.as_bytes()).into_owned()))
            .collect();
        Ok((status, headers, response.into_body()))
    }
}

#[async_trait]
impl Client for HttpClient {
    async fn send(&self, request: &Request) -> Result<Response, TransportError> {
        use http_body_util::BodyExt;

        let (status, headers, body) = self.request(request).await?;
        let body = body
            .collect()
            .await
            .map_err(|e| TransportError::Connection { message: e.to_string(), code: 56 })?
            .to_bytes();
        Ok(Response { status, headers, body })
    }

    async fn stream(&self, request: &Request) -> Result<Response<Body>, TransportError> {
        let (status, headers, body) = self.request(request).await?;
        Ok(Response { status, headers, body: Body::new(Incoming { body, pending: Bytes::new() }) })
    }
}

/// A response body read as it arrives, frame by frame.
struct Incoming {
    body: hyper::body::Incoming,
    /// What is left of the last frame.
    pending: Bytes,
}

#[async_trait]
impl Source for Incoming {
    async fn read(&mut self, max: usize) -> io::Result<Bytes> {
        use http_body_util::BodyExt;

        if max == 0 {
            return Ok(Bytes::new());
        }
        while self.pending.is_empty() {
            match self.body.frame().await {
                None => return Ok(Bytes::new()),
                Some(Err(e)) => return Err(io::Error::other(e)),
                // Trailers carry no bytes.
                Some(Ok(frame)) => self.pending = frame.into_data().unwrap_or_default(),
            }
        }
        Ok(self.pending.split_to(max.min(self.pending.len())))
    }

    fn is_seekable(&self) -> bool {
        false
    }

    async fn rewind(&mut self) -> io::Result<()> {
        Ok(())
    }
}
