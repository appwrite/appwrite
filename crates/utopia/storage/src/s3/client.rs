//! The HTTP client [`S3`](crate::S3) sends its requests through.

use std::time::Duration;

use async_trait::async_trait;
use bytes::Bytes;

use super::retry::RetryStrategy;

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
    pub body: Bytes,
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

/// A response, as a [`Client`] returns it.
#[derive(Debug, Clone, Default)]
pub struct Response {
    /// The status code.
    pub status: u16,
    /// Header names and values, in the order received; repeated headers
    /// appear once per value.
    pub headers: Vec<(String, String)>,
    /// The body.
    pub body: Bytes,
}

impl Response {
    /// A response with this status, no headers and no body.
    pub fn new(status: u16) -> Self {
        Response { status, ..Response::default() }
    }

    /// The response with a header added.
    pub fn with_header(mut self, name: impl Into<String>, value: impl Into<String>) -> Self {
        self.headers.push((name.into(), value.into()));
        self
    }

    /// The response with this body.
    pub fn with_body(mut self, body: impl Into<Bytes>) -> Self {
        self.body = body.into();
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
    /// Sends a request and returns its response, whatever its status.
    async fn send(&self, request: &Request) -> Result<Response, TransportError>;
}

#[async_trait]
impl<C: Client + ?Sized> Client for std::sync::Arc<C> {
    async fn send(&self, request: &Request) -> Result<Response, TransportError> {
        (**self).send(request).await
    }
}

/// A client that retries what a [`RetryStrategy`] says to.
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
            let Some(delay) = delay else { return result };
            if delay > 0.0 {
                tokio::time::sleep(Duration::from_secs_f64(delay)).await;
            }
            attempt += 1;
        }
    }
}

/// The default client: HTTP/1.1 over plain TCP or TLS (the web PKI's
/// roots), keeping connections alive, with no overall deadline.
///
/// PHP: `utopia-php/client` with the cURL adapter, which `S3` wraps in
/// `Retry` with `S3\RetryStrategy` unless given a client.
pub struct HttpClient {
    inner: hyper_util::client::legacy::Client<
        hyper_rustls::HttpsConnector<hyper_util::client::legacy::connect::HttpConnector>,
        http_body_util::Full<Bytes>,
    >,
}

impl Default for HttpClient {
    fn default() -> Self {
        Self::new()
    }
}

impl HttpClient {
    /// A client with its own connection pool.
    pub fn new() -> Self {
        let mut http = hyper_util::client::legacy::connect::HttpConnector::new();
        http.enforce_http(false);
        http.set_keepalive(Some(Duration::from_secs(60)));
        let tls = hyper_rustls::HttpsConnectorBuilder::new()
            .with_webpki_roots()
            .https_or_http()
            .enable_http1()
            .wrap_connector(http);
        let inner = hyper_util::client::legacy::Client::builder(hyper_util::rt::TokioExecutor::new()).build(tls);
        HttpClient { inner }
    }
}

#[async_trait]
impl Client for HttpClient {
    async fn send(&self, request: &Request) -> Result<Response, TransportError> {
        use http_body_util::BodyExt;

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
            .body(http_body_util::Full::new(request.body.clone()))
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
        let body = response
            .into_body()
            .collect()
            .await
            .map_err(|e| TransportError::Connection { message: e.to_string(), code: 56 })?
            .to_bytes();
        Ok(Response { status, headers, body })
    }
}
