//! [`Transport`]: how OTLP payloads reach a collector, and [`HttpTransport`],
//! the default.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Mutex, MutexGuard};
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use hyper::client::conn::http1::{self, SendRequest};
use hyper_rustls::HttpsConnector;
use tower_service::Service;
use utopia_async::hyper::Connector;

use crate::{BoxFuture, Error};

/// Sends encoded OTLP requests to a collector. Implement it to export another
/// way (a test capture, a queue).
///
/// PHP: `OpenTelemetry\SDK\Common\Export\TransportInterface`.
pub trait Transport: Send + Sync {
    /// The media type of the payloads (`application/x-protobuf`).
    fn content_type(&self) -> &str;

    /// Sends one payload; the collector's response body on success.
    fn send(&self, payload: Vec<u8>) -> BoxFuture<'_, Result<Vec<u8>, Error>>;

    /// Stops sending: later sends fail. Returns whether it shut down cleanly.
    fn shutdown(&self) -> bool;

    /// Sends anything buffered. Returns whether it succeeded.
    fn force_flush(&self) -> bool {
        true
    }
}

/// The protobuf media type, the default content type.
const PROTOBUF: &str = "application/x-protobuf";

type Sender = SendRequest<Full<Bytes>>;

/// OTLP over HTTP/1.1 (TLS for `https`), reusing keep-alive connections from
/// a pool.
///
/// A request that fails on a reused connection before any answer (the
/// collector dropped the idle connection) is sent again once on a new one; a
/// timeout is not, since the collector may have accepted it. A connection
/// that failed, or got a 5xx answer, is closed.
///
/// ```
/// use utopia_telemetry::{HttpTransport, Transport};
///
/// let transport = HttpTransport::new("http://collector:4318/v1/metrics")?
///     .with_header("Authorization", "Bearer token");
/// assert_eq!(transport.content_type(), "application/x-protobuf");
/// # Ok::<(), utopia_telemetry::Error>(())
/// ```
///
/// ```php
/// use Utopia\Telemetry\Adapter\OpenTelemetry\Transport\Swoole;
///
/// $transport = new Swoole('http://collector:4318/v1/metrics', headers: ['Authorization' => 'Bearer token']);
/// echo $transport->contentType(), "\n"; // application/x-protobuf
/// ```
///
/// PHP: `Utopia\Telemetry\Adapter\OpenTelemetry\Transport\Swoole` (and the
/// SDK's default OTLP HTTP transport, which this replaces).
pub struct HttpTransport {
    url: hyper::Uri,
    authority: String,
    path: String,
    content_type: String,
    headers: Vec<(String, String)>,
    timeout: Duration,
    pool_size: usize,
    socket_buffer_size: usize,
    idle: Mutex<Vec<Sender>>,
    shutdown: AtomicBool,
}

impl std::fmt::Debug for HttpTransport {
    /// Writes the endpoint and content type.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("HttpTransport").field("url", &self.url).field("content_type", &self.content_type).finish()
    }
}

/// Why one attempt failed.
enum Failure {
    /// The connection broke before an answer: worth one more try on a new one.
    Dropped(Error),
    /// Anything else.
    Final(Error),
}

impl HttpTransport {
    /// A transport posting to `endpoint`: protobuf payloads, a 10 second
    /// timeout, at most 8 idle connections kept and 64 KiB socket buffers.
    ///
    /// Fails with [`Error::InvalidArgument`] (`Invalid endpoint URL: ...`)
    /// when `endpoint` is not a URL.
    ///
    /// PHP: `new Swoole($endpoint)`; the endpoint is read with `parse_url()`.
    pub fn new(endpoint: &str) -> Result<Self, Error> {
        let invalid = || Error::InvalidArgument(format!("Invalid endpoint URL: {endpoint}"));
        let parsed = php_std::url::parse_url(endpoint.as_bytes()).ok_or_else(invalid)?;
        let text = |c: Option<std::borrow::Cow<'_, [u8]>>| c.map(|c| String::from_utf8_lossy(&c).into_owned());
        let ssl = text(parsed.scheme()).as_deref() == Some("https");
        let host = text(parsed.host()).unwrap_or_else(|| "localhost".to_owned());
        let port = parsed.port().unwrap_or(if ssl { 443 } else { 80 });
        let mut path = text(parsed.path()).unwrap_or_else(|| "/".to_owned());
        if let Some(query) = text(parsed.query()) {
            path.push('?');
            path.push_str(&query);
        }
        let host = if host.contains(':') && !host.starts_with('[') { format!("[{host}]") } else { host };
        let authority = format!("{host}:{port}");
        let url =
            format!("{}://{authority}{path}", if ssl { "https" } else { "http" }).parse().map_err(|_| invalid())?;
        Ok(HttpTransport {
            url,
            authority,
            path,
            content_type: PROTOBUF.to_owned(),
            headers: Vec::new(),
            timeout: Duration::from_secs(10),
            pool_size: 8,
            socket_buffer_size: 64 * 1024,
            idle: Mutex::new(Vec::new()),
            shutdown: AtomicBool::new(false),
        })
    }

    /// Sends payloads as `content_type` (`application/json` for OTLP/JSON).
    ///
    /// PHP: the `$contentType` argument.
    pub fn with_content_type(mut self, content_type: &str) -> Self {
        content_type.clone_into(&mut self.content_type);
        self
    }

    /// Adds a header to every request.
    ///
    /// PHP: the `$headers` argument.
    pub fn with_header(mut self, name: &str, value: &str) -> Self {
        self.headers.push((name.to_owned(), value.to_owned()));
        self
    }

    /// Fails a request that takes longer than `timeout` (connecting: at
    /// least half a second).
    ///
    /// PHP: the `$timeout` argument, in seconds.
    pub fn with_timeout(mut self, timeout: Duration) -> Self {
        self.timeout = timeout;
        self
    }

    /// Keeps at most `size` idle connections for reuse.
    ///
    /// PHP: the `$poolSize` argument.
    pub fn with_pool_size(mut self, size: usize) -> Self {
        self.pool_size = size;
        self
    }

    /// Sets the socket send and receive buffers to `size` bytes.
    ///
    /// PHP: the `$socketBufferSize` argument.
    pub fn with_socket_buffer_size(mut self, size: usize) -> Self {
        self.socket_buffer_size = size;
        self
    }

    fn idle(&self) -> MutexGuard<'_, Vec<Sender>> {
        self.idle.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// An idle connection that is still open.
    fn pooled(&self) -> Option<Sender> {
        let mut idle = self.idle();
        while let Some(sender) = idle.pop() {
            if !sender.is_closed() {
                return Some(sender);
            }
        }
        None
    }

    async fn connect(&self) -> Result<Sender, Error> {
        let mut http = Connector::new();
        http.enforce_http(false);
        http.set_nodelay(true);
        http.set_keepalive(Some(Duration::from_secs(60)));
        http.set_connect_timeout(Some(self.timeout.max(Duration::from_millis(500))));
        http.set_send_buffer_size(Some(self.socket_buffer_size));
        http.set_recv_buffer_size(Some(self.socket_buffer_size));
        let mut https: HttpsConnector<Connector> = hyper_rustls::HttpsConnectorBuilder::new()
            .with_provider_and_webpki_roots(rustls::crypto::ring::default_provider())
            .map_err(|e| Error::Telemetry(format!("TLS setup failed: {e}")))?
            .https_or_http()
            .enable_http1()
            .wrap_connector(http);
        let stream = https.call(self.url.clone()).await.map_err(|e| connection_failed(&*e))?;
        let (sender, connection) = http1::handshake(stream).await.map_err(|e| connection_failed(&e))?;
        utopia_async::spawn(connection);
        Ok(sender)
    }

    async fn post(&self, sender: &mut Sender, payload: Bytes) -> Result<(u16, Bytes), Failure> {
        let mut request = hyper::Request::post(self.path.as_str())
            .header("Host", self.authority.as_str())
            .header("Content-Type", self.content_type.as_str())
            .header("Connection", "keep-alive");
        for (name, value) in &self.headers {
            request = request.header(name.as_str(), value.as_str());
        }
        let request = request
            .header("Content-Length", payload.len())
            .body(Full::new(payload))
            .map_err(|e| Failure::Final(Error::Telemetry(e.to_string())))?;
        let exchange = async {
            sender.ready().await?;
            let response = sender.send_request(request).await?;
            let status = response.status().as_u16();
            let body = response.into_body().collect().await?.to_bytes();
            Ok::<_, hyper::Error>((status, body))
        };
        match utopia_async::timeout(self.timeout, exchange).await {
            Err(_) => {
                Err(Failure::Final(Error::Telemetry("OTLP connection failed: Operation timed out (code: 110)".into())))
            }
            Ok(Ok(answer)) => Ok(answer),
            Ok(Err(e)) if e.is_canceled() || e.is_closed() || e.is_incomplete_message() || reset(&e) => {
                Err(Failure::Dropped(connection_failed(&e)))
            }
            Ok(Err(e)) => Err(Failure::Final(connection_failed(&e))),
        }
    }

    async fn deliver(&self, payload: Vec<u8>) -> Result<Vec<u8>, Error> {
        let payload = Bytes::from(payload);
        let (mut sender, reused) = match self.pooled() {
            Some(sender) => (sender, true),
            None => (self.connect().await?, false),
        };
        let mut answer = self.post(&mut sender, payload.clone()).await;
        if reused && matches!(answer, Err(Failure::Dropped(_))) {
            sender = self.connect().await?;
            answer = self.post(&mut sender, payload).await;
        }
        let (status, body) = match answer {
            Ok(answer) => answer,
            Err(Failure::Dropped(e) | Failure::Final(e)) => return Err(e),
        };
        if status < 500 && !self.shutdown.load(Ordering::Acquire) {
            let mut idle = self.idle();
            if idle.len() < self.pool_size {
                idle.push(sender);
            }
        }
        if (200..300).contains(&status) {
            return Ok(body.to_vec());
        }
        Err(Error::Telemetry(format!("OTLP export failed with status {status}: {}", String::from_utf8_lossy(&body))))
    }
}

/// Whether the I/O error behind `error` is a reset or broken connection.
fn reset(error: &(dyn std::error::Error + 'static)) -> bool {
    let mut source: Option<&(dyn std::error::Error + 'static)> = Some(error);
    while let Some(e) = source {
        if let Some(io) = e.downcast_ref::<std::io::Error>() {
            use std::io::ErrorKind::*;
            return matches!(io.kind(), ConnectionReset | BrokenPipe | ConnectionAborted | UnexpectedEof);
        }
        source = e.source();
    }
    false
}

/// `OTLP connection failed: <reason> (code: <errno>)`, from the I/O error
/// behind `error` when there is one.
fn connection_failed(error: &(dyn std::error::Error + 'static)) -> Error {
    let mut source: Option<&(dyn std::error::Error + 'static)> = Some(error);
    while let Some(e) = source {
        if let Some(io) = e.downcast_ref::<std::io::Error>() {
            let code = io.raw_os_error().unwrap_or(0);
            let text = io.to_string();
            let reason = text.split(" (os error").next().unwrap_or(&text).to_owned();
            return Error::Telemetry(format!("OTLP connection failed: {reason} (code: {code})"));
        }
        source = e.source();
    }
    Error::Telemetry(format!("OTLP connection failed: {error} (code: 0)"))
}

impl Transport for HttpTransport {
    /// The content type requests are sent with.
    fn content_type(&self) -> &str {
        &self.content_type
    }

    /// Posts `payload`; fails once shut down, when the collector cannot be
    /// reached or takes longer than the timeout, or on a non-2xx status.
    fn send(&self, payload: Vec<u8>) -> BoxFuture<'_, Result<Vec<u8>, Error>> {
        Box::pin(async move {
            if self.shutdown.load(Ordering::Acquire) {
                return Err(Error::Telemetry("Transport has been shut down".into()));
            }
            self.deliver(payload).await
        })
    }

    /// Refuses later sends and closes the idle connections. Safe to call more
    /// than once.
    fn shutdown(&self) -> bool {
        self.shutdown.store(true, Ordering::Release);
        self.idle().clear();
        true
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_a_malformed_endpoint() {
        let error = HttpTransport::new("http:///v1/metrics").unwrap_err();
        assert_eq!(error.to_string(), "Invalid endpoint URL: http:///v1/metrics");
    }

    #[test]
    fn keeps_path_and_query() {
        let t = HttpTransport::new("https://otel.example.com/v1/metrics?foo=bar").unwrap();
        assert_eq!(t.url.to_string(), "https://otel.example.com:443/v1/metrics?foo=bar");
    }

    #[utopia_async::test]
    async fn refuses_to_send_after_shutdown() {
        let t = HttpTransport::new("http://localhost:4318/v1/metrics").unwrap();
        assert!(t.shutdown());
        assert!(t.shutdown());
        assert_eq!(t.send(b"x".to_vec()).await.unwrap_err().to_string(), "Transport has been shut down");
    }
}
