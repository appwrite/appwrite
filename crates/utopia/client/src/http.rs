//! The HTTP/1.1 transport: TCP and TLS on tokio, written to send the same
//! bytes, and read responses the same way, as PHP's cURL adapter.

use std::io::Write as _;
use std::net::SocketAddr;
use std::path::Path;
use std::pin::Pin;
use std::sync::Arc;
use std::task::{Context, Poll};
use std::time::{Duration, Instant};

use bytes::{Bytes, BytesMut};
use std::sync::Mutex;
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt, ReadBuf};
use tokio::net::TcpStream;
use tokio_rustls::client::TlsStream;

use crate::adapter::{Adapter, Options, Sink, Tls};
use crate::message::{Body, Headers, Request, Response};
use crate::{Error, Uri, redirect};

/// The codecs the transport decodes, advertised when the request has no
/// `Accept-Encoding` of its own.
///
/// PHP: `CURLOPT_ENCODING => ''` (every codec cURL was built with).
const ACCEPT_ENCODING: &str = "deflate, gzip, br, zstd";

/// What cURL skips around a header value (blanks and line breaks): a request header with
/// nothing else is not sent.
const CURL_SPACE: [char; 4] = [' ', '\t', '\n', '\r'];

/// How many idle connections a reusing transport keeps, as cURL's
/// connection cache (`CURLOPT_MAXCONNECTS`).
const KEPT: usize = 5;

/// Bodies larger than this are sent after a `100 Continue` (or a second
/// without an answer), as cURL does.
const EXPECT_THRESHOLD: u64 = 1024 * 1024;

/// How long to wait for `100 Continue` before sending the body anyway.
const EXPECT_TIMEOUT: Duration = Duration::from_secs(1);

/// The HTTP/1.1 transport: TCP and TLS (rustls) on tokio.
///
/// It sends what PHP's cURL adapter sends: `Host` first, `Accept: */*` and
/// `Accept-Encoding` unless the request sets them, then the request's own
/// headers as spelled, `Content-Length` for a body (and `Expect:
/// 100-continue` past 1 MiB), and decodes gzip, deflate, Brotli and Zstandard
/// responses unless the request negotiated an encoding itself. Every
/// connection is checked against the [`Destinations`](crate::Destinations)
/// once connected, before anything is sent. With connection reuse on, one
/// connection is kept and reused while it stays open.
///
/// PHP: `Utopia\Client\Adapter\Curl\Client` (and the Swoole coroutine
/// adapter, which does the same on Swoole's client). Their constructor's
/// native options have no counterpart: the [`Options`] are the
/// transport-neutral settings.
///
/// ```no_run
/// use utopia_client::{Client, Http, Request};
///
/// # async fn run() -> Result<(), utopia_client::Error> {
/// let client = Client::new(Http::new()).with_timeout(10.0)?;
/// let response = client.send(Request::new("GET", "https://example.com/")?).await?;
/// println!("{} {}", response.status(), response.text());
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Client\Adapter\Curl\Client as Curl;
/// use Utopia\Client\Client;
/// use Utopia\Psr7\Request;
/// use Utopia\Psr7\Uri;
///
/// $client = (new Client(new Curl()))->withTimeout(10.0);
/// $request = new Request('GET', Uri::parse('https://example.com/'));
/// // $client->sendRequest($request) returns the response
/// ```
#[derive(Debug, Default)]
pub struct Http {
    options: Options,
    kept: Arc<Mutex<Vec<Connection>>>,
}

impl Http {
    /// A transport with the default options.
    pub fn new() -> Http {
        Http::default()
    }
}

impl Clone for Http {
    /// A copy with the same options and no connection of its own yet.
    fn clone(&self) -> Http {
        Http { options: self.options.clone(), kept: Arc::default() }
    }
}

impl Adapter for Http {
    async fn send(&self, request: Request) -> Result<Response, Error> {
        let mut body = BytesMut::new();
        let mut collect = |chunk: &[u8]| body.extend_from_slice(chunk);
        let response = self.transfer(request, &mut collect).await?;
        Ok(Response::from_parts(response.protocol, response.status, response.reason, response.headers, body.freeze()))
    }

    async fn stream<S: Sink>(&self, request: Request, sink: &mut S) -> Result<Response, Error> {
        let response = self.transfer(request, sink).await?;
        Ok(Response::from_parts(response.protocol, response.status, response.reason, response.headers, Bytes::new()))
    }

    fn options(&self) -> &Options {
        &self.options
    }

    fn with_options(&self, options: Options) -> Http {
        Http { options, kept: Arc::default() }
    }
}

/// A response's status line and headers.
struct Head {
    protocol: String,
    status: u16,
    /// The status as cURL read it, which decides framing and redirects: 0
    /// when cURL did not parse the status line (a lowercase `http/`).
    code: u16,
    reason: String,
    headers: Headers,
    /// The header block exactly as received (interim responses included).
    raw: Vec<u8>,
    /// Whether the body ended with trailer fields.
    trailed: bool,
}

/// When the request must end, and when it started (for timeout messages).
#[derive(Clone, Copy)]
struct Clock {
    started: Instant,
    deadline: Option<Instant>,
}

impl Clock {
    fn elapsed_ms(&self) -> u128 {
        self.started.elapsed().as_millis()
    }

    fn timeout(&self, received: usize) -> Error {
        Error::Timeout(format!(
            "Operation timed out after {} milliseconds with {received} bytes received",
            self.elapsed_ms()
        ))
    }

    async fn run<T>(&self, received: usize, work: impl Future<Output = T>) -> Result<T, Error> {
        match self.deadline {
            None => Ok(work.await),
            Some(deadline) => tokio::time::timeout_at(deadline.into(), work).await.map_err(|_| self.timeout(received)),
        }
    }
}

impl Http {
    async fn transfer<S: Sink + ?Sized>(&self, request: Request, sink: &mut S) -> Result<Head, Error> {
        let uri = request.uri();
        if !matches!(uri.scheme(), "http" | "https") || uri.host().is_empty() {
            return Err(Error::InvalidUri("Requests must use an absolute URI.".into()));
        }
        check_host(uri.host())?;
        // Skipped when the caller negotiates encoding themselves.
        let decompress = !request.headers().has("Accept-Encoding");
        let started = Instant::now();
        let clock = Clock { started, deadline: self.options.timeout.and_then(|t| started.checked_add(t)) };

        let mut current = request;
        let mut hops = 0u32;
        let mut bodiless = false;
        let mut received = Vec::new();
        loop {
            let follow = self.options.follow;
            let decode = decompress && self.options.negotiate_encoding;
            let (head, location) = self.exchange(&current, sink, decode, clock, follow, bodiless).await?;
            received.extend_from_slice(&head.raw);
            let Some(location) = location else {
                return self.complete(head, &received, decompress);
            };
            if hops == self.options.max_hops {
                return Err(Error::Protocol(format!("Maximum ({}) redirects followed", self.options.max_hops)));
            }
            hops += 1;
            let method = current.method().to_ascii_uppercase();
            bodiless = bodiless || (matches!(head.code, 301..=303) && method != "GET" && method != "HEAD");
            current = follow_to(current, &head, &location)?;
        }
    }

    /// Checks the final response as PHP's adapter does once cURL is done,
    /// in its order: a complete header block somewhere in what was received
    /// (`\r\n\r\n`, `\n\n` or `\r\r`), a status line in the last block (trailers
    /// make a last block without one), a status in 100–599, then valid header
    /// names. Drops `Content-Encoding` and `Content-Length` of a decoded body:
    /// they describe the encoded one.
    ///
    /// PHP: `transfer()`, `parseHeaderBlock()`, `decoded($headers)` and the
    /// response builder.
    fn complete(&self, mut head: Head, received: &[u8], decompress: bool) -> Result<Head, Error> {
        if !terminated(received) {
            return Err(Error::Connection("Connection closed before a complete HTTP response was received.".into()));
        }
        if head.trailed || !(100..=599).contains(&head.status) {
            return Err(Error::InvalidResponse("Received an invalid HTTP response.".into()));
        }
        if decompress && head.headers.iter().any(|(name, _)| php_std::types::numeric_key(name.as_bytes()).is_some()) {
            return Err(Error::Type("strcasecmp(): Argument #1 ($string1) must be of type string, int given".into()));
        }
        if decompress && head.headers.has("Content-Encoding") {
            head.headers.remove("Content-Encoding");
            head.headers.remove("Content-Length");
        }
        head.headers.check()?;
        Ok(head)
    }

    /// One request and its response. Returns the redirect location to follow
    /// next, when following redirects and the response is one; its body is
    /// then discarded instead of reaching the sink.
    async fn exchange<S: Sink + ?Sized>(
        &self,
        request: &Request,
        sink: &mut S,
        decompress: bool,
        clock: Clock,
        follow: bool,
        bodiless: bool,
    ) -> Result<(Head, Option<Uri>), Error> {
        let uri = request.uri();
        let key = format!("{}://{}:{}", uri.scheme(), uri.host(), uri.effective_port());
        let size = body_size(request).await?;
        let head = encode_head(request, &self.options, decompress, size, !bodiless);

        let reused = if self.options.reuse {
            let mut kept = self.kept.lock().unwrap_or_else(|e| e.into_inner());
            kept.iter().position(|c| c.key == key && c.open).map(|i| kept.remove(i))
        } else {
            None
        };
        let mut connection = match reused {
            Some(c) => c,
            None => self.connect(uri, &key, clock).await?,
        };

        // A kept connection the server closed while idle fails before any
        // response byte arrives: send again on a fresh one, as cURL does.
        let outcome = match self.attempt(&mut connection, request, &head, size, sink, decompress, clock, follow).await {
            Err(Retry::Fresh) => {
                connection = self.connect(uri, &key, clock).await?;
                self.attempt(&mut connection, request, &head, size, sink, decompress, clock, follow).await
            }
            other => other,
        };
        let outcome = match outcome {
            Ok(outcome) => outcome,
            Err(Retry::Fresh) => return Err(Error::Connection("Empty reply from server".into())),
            Err(Retry::Fail(error)) => return Err(error),
        };
        if self.options.reuse && connection.open {
            let mut kept = self.kept.lock().unwrap_or_else(|e| e.into_inner());
            if kept.len() >= KEPT {
                kept.remove(0);
            }
            kept.push(connection);
        }
        Ok(outcome)
    }

    #[allow(clippy::too_many_arguments)]
    async fn attempt<S: Sink + ?Sized>(
        &self,
        connection: &mut Connection,
        request: &Request,
        head: &[u8],
        size: u64,
        sink: &mut S,
        decompress: bool,
        clock: Clock,
        follow: bool,
    ) -> Result<(Head, Option<Uri>), Retry> {
        let fresh = connection.requests == 0;
        connection.requests += 1;
        let retry = |error: Error| if fresh { Retry::Fail(error) } else { Retry::Fresh };

        clock
            .run(0, connection.stream.write_all(head))
            .await
            .map_err(Retry::Fail)?
            .map_err(|e| retry(send_error(e)))?;
        let expect = size > EXPECT_THRESHOLD && !request.headers().has("Expect");
        let mut early = None;
        let mut interim = Vec::new();
        if size > 0 {
            if expect {
                clock
                    .run(0, connection.stream.flush())
                    .await
                    .map_err(Retry::Fail)?
                    .map_err(|e| retry(send_error(e)))?;
                // Wait for 100 Continue; a final answer instead means the body is not wanted.
                let waited = tokio::time::timeout(EXPECT_TIMEOUT, connection.read_head(clock, request.method())).await;
                match waited {
                    Ok(Ok(head)) if head.code == 100 => interim.extend_from_slice(&head.raw),
                    Ok(Ok(head)) => early = Some(head),
                    Ok(Err(HeadError::Empty)) => {
                        return Err(retry(Error::Connection("Empty reply from server".into())));
                    }
                    Ok(Err(HeadError::Fail(e))) => return Err(Retry::Fail(e)),
                    Err(_) => {}
                }
            }
            if early.is_none() {
                send_body(connection, request.get_body(), clock).await.map_err(Retry::Fail)?;
            }
        }
        clock.run(0, connection.stream.flush()).await.map_err(Retry::Fail)?.map_err(|e| retry(send_error(e)))?;

        let mut head = match early {
            Some(head) => {
                connection.open = false;
                head
            }
            None => loop {
                match connection.read_head(clock, request.method()).await {
                    Ok(head) if (100..200).contains(&head.code) && head.code != 101 => {
                        interim.extend_from_slice(&head.raw)
                    }
                    Ok(head) => break head,
                    Err(HeadError::Empty) => return Err(retry(Error::Connection("Empty reply from server".into()))),
                    Err(HeadError::Fail(e)) => return Err(Retry::Fail(e)),
                }
            },
        };
        if !interim.is_empty() {
            interim.extend_from_slice(&head.raw);
            head.raw = interim;
        }
        if !(100..=599).contains(&head.status) {
            connection.open = false;
        }

        let location = if follow { follow_location(request.uri(), &head).map_err(Retry::Fail)? } else { None };
        let framing = framing(&head, request.method()).map_err(Retry::Fail)?;
        if location.is_some() {
            let mut discard = |_: &[u8]| {};
            connection.read_body(framing, &mut discard, None, clock).await.map_err(Retry::Fail)?;
        } else {
            let decodes = decompress && !matches!(framing, Framing::None);
            let mut decoder = if decodes { Decoder::for_headers(&head.headers).map_err(Retry::Fail)? } else { None };
            let (trailers, bytes) =
                connection.read_body(framing, sink, decoder.as_mut(), clock).await.map_err(Retry::Fail)?;
            head.trailed = trailers;
            let received = bytes > 0;
            if let Some(decoder) = decoder {
                decoder.finish(sink, received).map_err(Retry::Fail)?;
            }
        }
        if head.headers.line("Connection").eq_ignore_ascii_case("close") || head.protocol == "1.0" {
            connection.open = false;
        }
        Ok((head, location))
    }

    async fn connect(&self, uri: &Uri, key: &str, clock: Clock) -> Result<Connection, Error> {
        let host = uri.host().trim_start_matches('[').trim_end_matches(']').to_owned();
        let port = uri.effective_port();
        let connect_deadline = self.options.connect_timeout.and_then(|t| clock.started.checked_add(t));
        let deadline = match (connect_deadline, clock.deadline) {
            (Some(a), Some(b)) => Some(a.min(b)),
            (a, b) => a.or(b),
        };
        let timed_out = || {
            if clock.deadline.is_some() && deadline == clock.deadline && connect_deadline != clock.deadline {
                clock.timeout(0)
            } else {
                Error::Timeout(format!("Connection timed out after {} milliseconds", clock.elapsed_ms()))
            }
        };

        let addresses: Vec<SocketAddr> = match within(deadline, tokio::net::lookup_host((host.as_str(), port))).await {
            Err(()) => return Err(timed_out()),
            Ok(Err(_)) => return Err(Error::Dns(format!("Could not resolve host: {host} (Domain name not found)"))),
            Ok(Ok(found)) => found.collect(),
        };
        if addresses.is_empty() {
            return Err(Error::Dns(format!("Could not resolve host: {host} (Domain name not found)")));
        }

        let mut tcp = None;
        for address in &addresses {
            match within(deadline, TcpStream::connect(address)).await {
                Err(()) => return Err(timed_out()),
                Ok(Ok(stream)) => {
                    tcp = Some(stream);
                    break;
                }
                Ok(Err(_)) => continue,
            }
        }
        let Some(tcp) = tcp else {
            return Err(Error::Connection(format!(
                "Failed to connect to {host}:{port} after {} ms: Could not connect to server",
                clock.elapsed_ms()
            )));
        };
        let _ = tcp.set_nodelay(true);
        let peer = tcp.peer_addr().map(|a| a.ip().to_canonical().to_string()).unwrap_or_default();
        if !self.options.destinations.allows(&peer) {
            return Err(Error::Destination(format!("Connection to {peer} refused: not an allowed destination.")));
        }

        let stream = if uri.scheme() == "https" {
            let config = tls_config(&self.options)?;
            let name = rustls::pki_types::ServerName::try_from(host.clone())
                .map_err(|_| Error::Tls(format!("Invalid TLS server name: {host}")))?;
            let handshake = tokio_rustls::TlsConnector::from(Arc::new(config)).connect(name, tcp);
            match within(deadline, handshake).await {
                Err(()) => return Err(timed_out()),
                Ok(Err(error)) => return Err(tls_error(&error, &host)),
                Ok(Ok(tls)) => Stream::Tls(Box::new(tls)),
            }
        } else {
            Stream::Plain(tcp)
        };
        Ok(Connection {
            key: key.to_owned(),
            stream,
            buffer: BytesMut::with_capacity(16 * 1024),
            open: true,
            requests: 0,
            capture: None,
        })
    }
}

/// Rejects a host name cURL's URL parser refuses.
///
/// PHP: libcurl's `hostname_check()` (`CURLUE_BAD_HOSTNAME`, a
/// `NetworkException`).
fn check_host(host: &str) -> Result<(), Error> {
    let bad = if host.starts_with('[') {
        !host.ends_with(']')
            || !host[1..host.len() - 1].bytes().all(|b| b.is_ascii_hexdigit() || b == b':' || b == b'.')
    } else {
        host.bytes().any(|b| b" \r\n\t/:#?!@{}[]\\$'\"^`*<>=;,+&()%".contains(&b))
    };
    if bad {
        return Err(Error::Network("URL rejected: Bad hostname".into()));
    }
    Ok(())
}

/// Whether PHP's adapter finds a complete header block in what cURL passed
/// to its header callback.
///
/// PHP: `preg_match("/\r\n\r\n|\n\n|\r\r/", $headers)`.
fn terminated(received: &[u8]) -> bool {
    received.windows(2).any(|w| w == b"\n\n" || w == b"\r\r") || received.windows(4).any(|w| w == b"\r\n\r\n")
}

/// Runs `work` until `deadline`; `Err(())` when it passes first.
async fn within<F: Future>(deadline: Option<Instant>, work: F) -> Result<F::Output, ()> {
    match deadline {
        None => Ok(work.await),
        Some(d) => tokio::time::timeout_at(d.into(), work).await.map_err(|_| ()),
    }
}

/// Why an attempt on a connection failed.
enum Retry {
    /// A reused connection was found closed: send again on a fresh one.
    Fresh,
    /// The request failed.
    Fail(Error),
}

/// Why no response head was read.
enum HeadError {
    /// The connection closed before any byte of a response.
    Empty,
    /// The request failed.
    Fail(Error),
}

fn send_error(error: std::io::Error) -> Error {
    Error::Connection(format!("Send failure: {}", error.kind()))
}

async fn body_size(request: &Request) -> Result<u64, Error> {
    match request.get_body() {
        Body::Empty => Ok(0),
        Body::Bytes(b) => Ok(b.len() as u64),
        Body::File(path) => tokio::fs::metadata(path).await.map(|m| m.len()).map_err(|e| {
            Error::InvalidArgument(format!("Unable to read the request body from {}: {e}", path.display()))
        }),
    }
}

/// The request line and headers.
///
/// PHP: the request cURL builds from `CURLOPT_HTTPHEADER`, `CURLOPT_ENCODING`
/// and the body options.
fn encode_head(request: &Request, options: &Options, decompress: bool, size: u64, declare: bool) -> Vec<u8> {
    let uri = request.uri();
    let headers = request.headers();
    let mut out = Vec::with_capacity(256);
    let path = if uri.path().is_empty() {
        "/".to_owned()
    } else if options.path_as_is {
        uri.path().to_owned()
    } else {
        redirect::remove_dot_segments(uri.path())
    };
    let path = if path.is_empty() { "/" } else { path.as_str() };
    out.extend_from_slice(request.method().as_bytes());
    out.push(b' ');
    out.extend_from_slice(path.as_bytes());
    if !uri.query().is_empty() {
        out.push(b'?');
        out.extend_from_slice(uri.query().as_bytes());
    }
    out.extend_from_slice(b" HTTP/1.1\r\n");

    let line = |out: &mut Vec<u8>, name: &str, value: &str| {
        out.extend_from_slice(name.as_bytes());
        out.extend_from_slice(b": ");
        out.extend_from_slice(value.as_bytes());
        out.extend_from_slice(b"\r\n");
    };
    match headers.get("Host").first() {
        Some(host) => line(&mut out, "Host", host.split('\0').next().unwrap_or_default()),
        None => match uri.port() {
            Some(port) => line(&mut out, "Host", &format!("{}:{port}", uri.host())),
            None => line(&mut out, "Host", uri.host()),
        },
    }
    if let Some(agent) = &options.user_agent
        && !headers.has("User-Agent")
    {
        line(&mut out, "User-Agent", agent);
    }
    if !headers.has("Accept") {
        line(&mut out, "Accept", "*/*");
    }
    if decompress {
        line(&mut out, "Accept-Encoding", ACCEPT_ENCODING);
    }
    for (name, values) in headers.iter() {
        if name.eq_ignore_ascii_case("Host") {
            continue;
        }
        for value in values {
            // cURL reads each header as a C string, and drops one with nothing after its colon.
            let value = value.split('\0').next().unwrap_or_default();
            if !value.trim_matches(CURL_SPACE).is_empty() {
                line(&mut out, name, value);
            }
        }
    }
    let carries = size > 0 || (declare && matches!(request.method(), "POST" | "PUT" | "PATCH"));
    if carries && !headers.has("Content-Length") {
        line(&mut out, "Content-Length", &size.to_string());
    }
    if size > EXPECT_THRESHOLD && !headers.has("Expect") {
        line(&mut out, "Expect", "100-continue");
    }
    out.extend_from_slice(b"\r\n");
    out
}

async fn send_body(connection: &mut Connection, body: &Body, clock: Clock) -> Result<(), Error> {
    match body {
        Body::Empty => Ok(()),
        Body::Bytes(bytes) => clock.run(0, connection.stream.write_all(bytes)).await?.map_err(send_error),
        Body::File(path) => {
            let mut file = open(path).await?;
            let mut chunk = vec![0u8; 64 * 1024];
            loop {
                let n = file.read(&mut chunk).await.map_err(|e| Error::InvalidArgument(e.to_string()))?;
                if n == 0 {
                    return Ok(());
                }
                clock.run(0, connection.stream.write_all(&chunk[..n])).await?.map_err(send_error)?;
            }
        }
    }
}

async fn open(path: &Path) -> Result<tokio::fs::File, Error> {
    tokio::fs::File::open(path)
        .await
        .map_err(|e| Error::InvalidArgument(format!("Unable to read the request body from {}: {e}", path.display())))
}

/// The redirect to follow, when the response is one cURL follows.
fn follow_location(from: &Uri, head: &Head) -> Result<Option<Uri>, Error> {
    let location = head.headers.line("Location");
    if !(300..400).contains(&head.code) || location.is_empty() {
        return Ok(None);
    }
    let to = redirect::resolve(from, &location).map_err(|_| Error::InvalidUri("Invalid redirect location.".into()))?;
    if !matches!(to.scheme(), "http" | "https") || to.host().is_empty() {
        return Err(Error::Protocol("The redirect target URL could not be parsed: Unsupported URL scheme".into()));
    }
    Ok(Some(to))
}

/// The request for the next hop: GET without a body after a 301, 302 or 303
/// to anything but a GET or HEAD, credentials dropped on a change of origin.
fn follow_to(mut request: Request, head: &Head, to: &Uri) -> Result<Request, Error> {
    let from = request.uri().clone();
    let method = request.method().to_ascii_uppercase();
    if matches!(head.code, 301..=303) && method != "GET" && method != "HEAD" {
        // cURL switches to a bodiless request but keeps the custom method.
        request = request.body(Body::Empty);
    }
    let mut request = request.with_uri(to.clone());
    request.headers_mut().remove("Host");
    if redirect::should_strip_sensitive_headers(&from, to) {
        redirect::without_sensitive_headers(request.headers_mut());
    }
    Ok(request)
}

/// How a response body is delimited.
#[derive(Clone, Copy)]
enum Framing {
    None,
    Length(u64),
    Chunked,
    Close,
}

fn framing(head: &Head, method: &str) -> Result<Framing, Error> {
    if method.eq_ignore_ascii_case("HEAD") || matches!(head.code, 100..=199 | 204 | 304) {
        return Ok(Framing::None);
    }
    let encoding = head.headers.line("Transfer-Encoding");
    if encoding.rsplit(',').next().is_some_and(|last| last.trim().eq_ignore_ascii_case("chunked")) {
        return Ok(Framing::Chunked);
    }
    let lengths = head.headers.get("Content-Length");
    if lengths.is_empty() {
        return Ok(Framing::Close);
    }
    let mut length = None;
    for value in lengths {
        let value = value.trim();
        if value.is_empty() || !value.bytes().all(|b| b.is_ascii_digit()) {
            return Err(Error::Protocol("Invalid Content-Length: value".into()));
        }
        let parsed = value.parse::<u64>().map_err(|_| Error::Protocol("Invalid Content-Length: value".into()))?;
        if length.is_some_and(|l| l != parsed) {
            return Err(Error::Protocol("Invalid Content-Length: value".into()));
        }
        length = Some(parsed);
    }
    Ok(Framing::Length(length.unwrap_or(0)))
}

/// A response body decoder for one `Content-Encoding`.
enum Decoder {
    Gzip(flate2::write::GzDecoder<Vec<u8>>),
    Deflate(flate2::write::ZlibDecoder<Vec<u8>>),
    Brotli(Box<brotli::DecompressorWriter<Vec<u8>>>),
    Zstd(zstd::stream::write::Decoder<'static, Vec<u8>>),
    Identity,
}

impl Decoder {
    fn for_headers(headers: &Headers) -> Result<Option<Decoder>, Error> {
        let encoding = headers.line("Content-Encoding");
        let encoding = encoding.trim();
        if encoding.is_empty() {
            return Ok(None);
        }
        Ok(Some(match encoding.to_ascii_lowercase().as_str() {
            "gzip" | "x-gzip" => Decoder::Gzip(flate2::write::GzDecoder::new(Vec::new())),
            "deflate" => Decoder::Deflate(flate2::write::ZlibDecoder::new(Vec::new())),
            "br" => Decoder::Brotli(Box::new(brotli::DecompressorWriter::new(Vec::new(), 4096))),
            "zstd" => Decoder::Zstd(
                zstd::stream::write::Decoder::new(Vec::new())
                    .map_err(|_| Error::Protocol("Unrecognized content encoding type".into()))?,
            ),
            "identity" | "none" => Decoder::Identity,
            _ => return Err(Error::Protocol("Unrecognized content encoding type".into())),
        }))
    }

    /// Decodes a chunk, passing what it yields on.
    fn write<S: Sink + ?Sized>(&mut self, chunk: &[u8], sink: &mut S) -> Result<(), Error> {
        let fail = |_| Error::Protocol("Error while processing content unencoding: incorrect header check".into());
        let out = match self {
            Decoder::Identity => {
                sink.write(chunk);
                return Ok(());
            }
            Decoder::Gzip(d) => {
                d.write_all(chunk).map_err(fail)?;
                d.get_mut()
            }
            Decoder::Deflate(d) => {
                d.write_all(chunk).map_err(fail)?;
                d.get_mut()
            }
            Decoder::Brotli(d) => {
                d.write_all(chunk).map_err(fail)?;
                d.get_mut()
            }
            Decoder::Zstd(d) => {
                d.write_all(chunk).map_err(fail)?;
                d.get_mut()
            }
        };
        if !out.is_empty() {
            sink.write(out);
            out.clear();
        }
        Ok(())
    }

    fn finish<S: Sink + ?Sized>(self, sink: &mut S, received: bool) -> Result<(), Error> {
        if !received {
            return Ok(());
        }
        let fail = |_| Error::Protocol("Error while processing content unencoding: incorrect header check".into());
        let rest = match self {
            Decoder::Identity => return Ok(()),
            Decoder::Gzip(d) => d.finish().map_err(fail)?,
            Decoder::Deflate(d) => d.finish().map_err(fail)?,
            Decoder::Brotli(d) => d
                .into_inner()
                .map_err(|_| Error::Protocol("Error while processing content unencoding: brotli".into()))?,
            Decoder::Zstd(mut d) => {
                d.flush().map_err(fail)?;
                d.into_inner()
            }
        };
        if !rest.is_empty() {
            sink.write(&rest);
        }
        Ok(())
    }
}

/// A TCP or TLS stream.
enum Stream {
    Plain(TcpStream),
    Tls(Box<TlsStream<TcpStream>>),
}

impl AsyncRead for Stream {
    fn poll_read(self: Pin<&mut Self>, cx: &mut Context<'_>, buf: &mut ReadBuf<'_>) -> Poll<std::io::Result<()>> {
        match self.get_mut() {
            Stream::Plain(s) => Pin::new(s).poll_read(cx, buf),
            Stream::Tls(s) => Pin::new(s.as_mut()).poll_read(cx, buf),
        }
    }
}

impl AsyncWrite for Stream {
    fn poll_write(self: Pin<&mut Self>, cx: &mut Context<'_>, buf: &[u8]) -> Poll<std::io::Result<usize>> {
        match self.get_mut() {
            Stream::Plain(s) => Pin::new(s).poll_write(cx, buf),
            Stream::Tls(s) => Pin::new(s.as_mut()).poll_write(cx, buf),
        }
    }

    fn poll_flush(self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<std::io::Result<()>> {
        match self.get_mut() {
            Stream::Plain(s) => Pin::new(s).poll_flush(cx),
            Stream::Tls(s) => Pin::new(s.as_mut()).poll_flush(cx),
        }
    }

    fn poll_shutdown(self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<std::io::Result<()>> {
        match self.get_mut() {
            Stream::Plain(s) => Pin::new(s).poll_shutdown(cx),
            Stream::Tls(s) => Pin::new(s.as_mut()).poll_shutdown(cx),
        }
    }
}

/// An open connection and the bytes read past the last response.
struct Connection {
    key: String,
    stream: Stream,
    buffer: BytesMut,
    open: bool,
    requests: u32,
    /// Collects the raw lines read while reading a header block.
    capture: Option<Vec<u8>>,
}

impl std::fmt::Debug for Connection {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Connection").field("key", &self.key).field("open", &self.open).finish()
    }
}

impl Connection {
    /// Reads more bytes into the buffer; `false` at the end of the stream.
    async fn fill(&mut self, clock: Clock, received: usize) -> Result<bool, Error> {
        self.buffer.reserve(16 * 1024);
        let n = clock
            .run(received, self.stream.read_buf(&mut self.buffer))
            .await?
            .map_err(|e| Error::Connection(format!("Recv failure: {}", e.kind())))?;
        if n == 0 {
            self.open = false;
        }
        Ok(n > 0)
    }

    /// Reads one line (through `\n`), without its line ending; `None` at
    /// the end of the stream.
    async fn line(&mut self, clock: Clock) -> Result<Option<Vec<u8>>, Error> {
        loop {
            if let Some(i) = self.buffer.iter().position(|&b| b == b'\n') {
                let mut line = self.buffer.split_to(i + 1).to_vec();
                if let Some(capture) = self.capture.as_mut() {
                    capture.extend_from_slice(&line);
                }
                line.pop();
                if line.last() == Some(&b'\r') {
                    line.pop();
                }
                return Ok(Some(line));
            }
            if !self.fill(clock, 0).await? {
                return Ok(None);
            }
        }
    }

    /// Reads a response's status line and headers.
    ///
    /// PHP: cURL's header parsing, then `parseHeaderBlock()`.
    async fn read_head(&mut self, clock: Clock, method: &str) -> Result<Head, HeadError> {
        let _ = method;
        loop {
            if self.buffer.len() >= 5 || self.buffer.contains(&b'\n') {
                break;
            }
            if !self.fill(clock, 0).await.map_err(HeadError::Fail)? {
                if self.buffer.is_empty() {
                    return Err(HeadError::Empty);
                }
                break;
            }
        }
        let prefix = self.buffer[..self.buffer.len().min(5)].to_ascii_lowercase();
        if !b"http/".starts_with(&prefix) || (prefix.len() < 5 && self.buffer.contains(&b'\n')) {
            self.open = false;
            return Err(HeadError::Fail(Error::Protocol("Received HTTP/0.9 when not allowed".into())));
        }
        self.capture = Some(Vec::with_capacity(256));
        let incomplete = || {
            HeadError::Fail(Error::Connection("Connection closed before a complete HTTP response was received.".into()))
        };
        // cURL counts header bytes by complete lines: a reply that ends inside
        // its status line got nothing.
        let status_line = self.line(clock).await.map_err(HeadError::Fail)?.ok_or(HeadError::Empty)?;
        let (protocol, status, reason, code) = status(&status_line).map_err(|e| {
            self.open = false;
            HeadError::Fail(e)
        })?;

        // cURL reads no body for these, and so does not check their Content-Length.
        let bodyless = matches!(code, 100..=199 | 204 | 304);
        let fail = |connection: &mut Connection, message: &str| {
            connection.open = false;
            HeadError::Fail(Error::Protocol(message.to_owned()))
        };
        let mut fields: Vec<(String, String)> = Vec::new();
        let mut length: Option<u64> = None;
        let mut location = false;
        // cURL processes a header line once the next one shows it is not
        // folded: the last line before the connection closes is never checked.
        let mut pending: Option<Vec<u8>> = None;
        let mut lines = 0usize;
        loop {
            let next = self.line(clock).await.map_err(HeadError::Fail)?.ok_or_else(incomplete)?;
            if matches!(next.first(), Some(b' ' | b'\t'))
                && let Some(held) = pending.as_mut()
            {
                let start = next.iter().position(|b| *b != b' ' && *b != b'\t').unwrap_or(next.len());
                held.push(b' ');
                held.extend_from_slice(&next[start..]);
                continue;
            }
            let Some(line) = std::mem::replace(&mut pending, if next.is_empty() { None } else { Some(next.clone()) })
            else {
                if next.is_empty() {
                    break;
                }
                continue;
            };
            lines += 1;
            if line.contains(&0) {
                return Err(fail(self, "Nul byte in header"));
            }
            let line = String::from_utf8_lossy(&line).into_owned();
            let Some((name, value)) = line.split_once(':') else {
                return Err(fail(self, "Header without colon"));
            };
            let trimmed = value.trim_matches([' ', '\t']);
            if name.eq_ignore_ascii_case("Content-Length") && !bodyless {
                let parsed = if !trimmed.is_empty() && trimmed.bytes().all(|b| b.is_ascii_digit()) {
                    trimmed.parse::<u64>().ok()
                } else {
                    None
                };
                match (parsed, length) {
                    (None, _) => return Err(fail(self, "Invalid Content-Length: value")),
                    (Some(a), Some(b)) if a != b => return Err(fail(self, "Invalid Content-Length: value")),
                    (Some(a), _) => length = Some(a),
                }
            }
            if name.eq_ignore_ascii_case("Location") && !trimmed.is_empty() {
                if location {
                    return Err(fail(self, "Multiple Location headers"));
                }
                location = true;
            }
            fields.push((name.to_owned(), value.to_owned()));
            if next.is_empty() {
                break;
            }
        }
        if code != 0 && code < 100 {
            return Err(fail(self, "Unsupported response code in HTTP response"));
        }

        // PHP groups the lines by name as spelled, then the PSR-7 message
        // merges names that differ only in case: all values of the first
        // spelling come before those of a later one.
        let mut grouped: Vec<(String, Vec<String>)> = Vec::new();
        for (name, value) in fields {
            let name = php_trim(&name);
            if name.is_empty() {
                continue;
            }
            let value = php_trim(&value).to_owned();
            match grouped.iter_mut().find(|(n, _)| n == name) {
                Some((_, values)) => values.push(value),
                None => grouped.push((name.to_owned(), vec![value])),
            }
        }
        let mut headers = Headers::new();
        for (name, values) in grouped {
            for value in values {
                headers.add_unchecked(&name, value);
            }
        }
        let raw = self.capture.take().unwrap_or_default();
        // PHP trims the whole header text before splitting it: a status line
        // with no header after it loses its trailing whitespace.
        let reason = if lines == 0 { php_trim(&reason).to_owned() } else { reason };
        Ok(Head { protocol, status, code, reason, headers, raw, trailed: false })
    }

    /// Reads a body, decoding it when there is a decoder, into the sink.
    async fn read_body<S: Sink + ?Sized>(
        &mut self,
        framing: Framing,
        sink: &mut S,
        mut decoder: Option<&mut Decoder>,
        clock: Clock,
    ) -> Result<(bool, usize), Error> {
        let mut received = 0usize;
        let mut deliver = |chunk: &[u8], decoder: &mut Option<&mut Decoder>| -> Result<(), Error> {
            match decoder {
                Some(d) => d.write(chunk, sink),
                None => {
                    sink.write(chunk);
                    Ok(())
                }
            }
        };
        match framing {
            Framing::None => Ok((false, 0)),
            Framing::Length(mut remaining) => {
                while remaining > 0 {
                    if self.buffer.is_empty() && !self.fill(clock, received).await? {
                        self.open = false;
                        return Err(Error::Protocol(format!("end of response with {remaining} bytes missing")));
                    }
                    let take = (self.buffer.len() as u64).min(remaining) as usize;
                    let chunk = self.buffer.split_to(take);
                    remaining -= take as u64;
                    received += take;
                    deliver(&chunk, &mut decoder)?;
                }
                Ok((false, received))
            }
            Framing::Close => {
                self.open = false;
                loop {
                    if !self.buffer.is_empty() {
                        let chunk = self.buffer.split();
                        received += chunk.len();
                        deliver(&chunk, &mut decoder)?;
                    }
                    if !self.fill(clock, received).await? {
                        return Ok((false, received));
                    }
                }
            }
            Framing::Chunked => {
                let truncated = || Error::Protocol("transfer closed with outstanding read data remaining".into());
                loop {
                    let line = self.line(clock).await?.ok_or_else(truncated)?;
                    let digits = line.split(|&b| b == b';').next().unwrap_or_default();
                    let digits = php_std::string::trim(digits, b" \t");
                    if let Some(&bad) = digits.iter().find(|b| !b.is_ascii_hexdigit()) {
                        self.open = false;
                        return Err(Error::Connection(format!("chunk hex-length char not a hex digit: 0x{bad:x}")));
                    }
                    if digits.is_empty() {
                        self.open = false;
                        return Err(Error::Connection("invalid chunk size: ''".into()));
                    }
                    let size = u64::from_str_radix(std::str::from_utf8(digits).unwrap_or("0"), 16)
                        .map_err(|_| Error::Connection("invalid chunk size".into()))?;
                    if size == 0 {
                        // Trailers, up to the blank line.
                        let mut trailed = false;
                        while let Some(line) = self.line(clock).await? {
                            if line.is_empty() {
                                break;
                            }
                            trailed = true;
                        }
                        return Ok((trailed, received));
                    }
                    let mut remaining = size;
                    while remaining > 0 {
                        if self.buffer.is_empty() && !self.fill(clock, received).await? {
                            return Err(truncated());
                        }
                        let take = (self.buffer.len() as u64).min(remaining) as usize;
                        let chunk = self.buffer.split_to(take);
                        remaining -= take as u64;
                        received += take;
                        deliver(&chunk, &mut decoder)?;
                    }
                    // The CRLF after the data.
                    self.line(clock).await?.ok_or_else(truncated)?;
                }
            }
        }
    }
}

/// PHP `trim()` with its default characters.
fn php_trim(s: &str) -> &str {
    s.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
}

/// Parses a status line as cURL accepts it, and as PHP's
/// `parseHeaderBlock()` then splits it.
fn status(line: &[u8]) -> Result<(String, u16, String, u16), Error> {
    if !line.starts_with(b"HTTP/") {
        // cURL takes a lowercase prefix for a status line without parsing it;
        // PHP's case-insensitive pattern then reads it.
        let (protocol, status, reason) = php_status(line);
        return Ok((protocol, status, reason, 0));
    }
    let rest = &line[5.min(line.len())..];
    let digits = |s: &[u8]| s.len() >= 3 && s[..3].iter().all(u8::is_ascii_digit);
    let (protocol, after) =
        if rest.len() >= 4 && rest[0] == b'1' && rest[1] == b'.' && rest[2].is_ascii_digit() && rest[3] == b' ' {
            if !(rest[2] == b'0' || rest[2] == b'1') || !digits(&rest[4..]) {
                return Err(Error::Protocol("Unsupported HTTP/1 subversion in response".into()));
            }
            (String::from_utf8_lossy(&rest[..3]).into_owned(), &rest[4..])
        } else if rest.len() >= 2 && (rest[0] == b'2' || rest[0] == b'3') && rest[1] == b' ' {
            if !digits(&rest[2..]) {
                return Err(Error::Protocol("Unsupported HTTP version in response".into()));
            }
            (String::from_utf8_lossy(&rest[..1]).into_owned(), &rest[2..])
        } else if rest.first() == Some(&b'1') {
            return Err(Error::Protocol("Unsupported HTTP/1 subversion in response".into()));
        } else {
            return Err(Error::Protocol("Unsupported HTTP version in response".into()));
        };
    if after.len() > 3 && after[3] != b' ' {
        return Err(Error::Protocol("Invalid status line".into()));
    }
    let code = std::str::from_utf8(&after[..3]).unwrap_or("0").parse::<u16>().unwrap_or(0);
    let reason = if after.len() > 3 {
        String::from_utf8_lossy(after[3..].trim_ascii_start()).into_owned()
    } else {
        String::new()
    };
    Ok((protocol, code, reason, code))
}

/// PHP's `/^HTTP\/(?P<protocol>\d+(?:\.\d+)?)\s+(?P<status>\d{3})(?:\s+(?P<reason>.*))?$/i`
/// on a status line: protocol `1.1`, status 0 and no reason when it does not
/// match.
fn php_status(line: &[u8]) -> (String, u16, String) {
    let fail = || ("1.1".to_owned(), 0, String::new());
    let space = |b: &u8| matches!(b, b' ' | b'\t' | b'\x0B' | b'\x0C' | b'\r' | b'\n');
    if line.len() < 5 || !line[..5].eq_ignore_ascii_case(b"http/") {
        return fail();
    }
    let rest = &line[5..];
    let major = rest.iter().take_while(|b| b.is_ascii_digit()).count();
    if major == 0 {
        return fail();
    }
    let mut end = major;
    if rest.get(end) == Some(&b'.') {
        let minor = rest[end + 1..].iter().take_while(|b| b.is_ascii_digit()).count();
        if minor > 0 {
            end += 1 + minor;
        }
    }
    let protocol = String::from_utf8_lossy(&rest[..end]).into_owned();
    let spaces = rest[end..].iter().take_while(|b| space(b)).count();
    if spaces == 0 {
        return fail();
    }
    let after = &rest[end + spaces..];
    if after.len() < 3 || !after[..3].iter().all(u8::is_ascii_digit) {
        return fail();
    }
    let status = std::str::from_utf8(&after[..3]).unwrap_or("0").parse().unwrap_or(0);
    let tail = &after[3..];
    if tail.is_empty() {
        return (protocol, status, String::new());
    }
    let gap = tail.iter().take_while(|b| space(b)).count();
    if gap == 0 {
        return fail();
    }
    (protocol, status, String::from_utf8_lossy(&tail[gap..]).into_owned())
}

fn tls_config(options: &Options) -> Result<rustls::ClientConfig, Error> {
    let provider = Arc::new(rustls::crypto::ring::default_provider());
    let versions: &[&rustls::SupportedProtocolVersion] = match options.min_tls {
        Some(Tls::V1_3) => &[&rustls::version::TLS13],
        _ => &[&rustls::version::TLS12, &rustls::version::TLS13],
    };
    let builder = rustls::ClientConfig::builder_with_provider(provider.clone())
        .with_protocol_versions(versions)
        .map_err(|e| Error::Tls(e.to_string()))?;
    let builder = if options.verify {
        let mut roots = rustls::RootCertStore::empty();
        match &options.ca {
            Some(path) => {
                use rustls::pki_types::pem::PemObject;
                let certs = rustls::pki_types::CertificateDer::pem_file_iter(path)
                    .map_err(|_| Error::Tls(format!("error setting certificate file: {}", path.display())))?;
                for cert in certs.flatten() {
                    let _ = roots.add(cert);
                }
            }
            None => roots.extend(webpki_roots::TLS_SERVER_ROOTS.iter().cloned()),
        }
        builder.with_root_certificates(roots)
    } else {
        builder.dangerous().with_custom_certificate_verifier(Arc::new(Unverified(provider)))
    };
    match &options.certificate {
        None => Ok(builder.with_no_client_auth()),
        Some(certificate) => {
            use rustls::pki_types::pem::PemObject;
            let certs = rustls::pki_types::CertificateDer::pem_file_iter(&certificate.cert)
                .and_then(|i| i.collect::<Result<Vec<_>, _>>())
                .map_err(|_| {
                    Error::Tls(format!("could not load PEM client certificate from {}", certificate.cert.display()))
                })?;
            let key = rustls::pki_types::PrivateKeyDer::from_pem_file(&certificate.key).map_err(|_| {
                Error::Tls(format!("unable to set private key file: '{}' type PEM", certificate.key.display()))
            })?;
            builder.with_client_auth_cert(certs, key).map_err(|e| Error::Tls(e.to_string()))
        }
    }
}

/// The error for a failed handshake, worded as cURL on OpenSSL words it.
fn tls_error(error: &std::io::Error, host: &str) -> Error {
    let inner = error.get_ref().and_then(|e| e.downcast_ref::<rustls::Error>());
    match inner {
        Some(rustls::Error::InvalidCertificate(rustls::CertificateError::UnknownIssuer)) => {
            Error::Tls("SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)".into())
        }
        Some(rustls::Error::InvalidCertificate(rustls::CertificateError::Expired)) => {
            Error::Tls("SSL certificate OpenSSL verify result: certificate has expired (10)".into())
        }
        Some(rustls::Error::InvalidCertificate(
            rustls::CertificateError::NotValidForName | rustls::CertificateError::NotValidForNameContext { .. },
        )) => Error::Tls(format!("SSL: no alternative certificate subject name matches target hostname '{host}'")),
        Some(other) => Error::Tls(format!("TLS connect error: {other}")),
        None => Error::Tls(format!("TLS connect error: {error}")),
    }
}

/// Accepts any certificate (verification off), still checking handshake
/// signatures.
#[derive(Debug)]
struct Unverified(Arc<rustls::crypto::CryptoProvider>);

impl rustls::client::danger::ServerCertVerifier for Unverified {
    fn verify_server_cert(
        &self,
        _end_entity: &rustls::pki_types::CertificateDer<'_>,
        _intermediates: &[rustls::pki_types::CertificateDer<'_>],
        _server_name: &rustls::pki_types::ServerName<'_>,
        _ocsp_response: &[u8],
        _now: rustls::pki_types::UnixTime,
    ) -> Result<rustls::client::danger::ServerCertVerified, rustls::Error> {
        Ok(rustls::client::danger::ServerCertVerified::assertion())
    }

    fn verify_tls12_signature(
        &self,
        message: &[u8],
        cert: &rustls::pki_types::CertificateDer<'_>,
        dss: &rustls::DigitallySignedStruct,
    ) -> Result<rustls::client::danger::HandshakeSignatureValid, rustls::Error> {
        rustls::crypto::verify_tls12_signature(message, cert, dss, &self.0.signature_verification_algorithms)
    }

    fn verify_tls13_signature(
        &self,
        message: &[u8],
        cert: &rustls::pki_types::CertificateDer<'_>,
        dss: &rustls::DigitallySignedStruct,
    ) -> Result<rustls::client::danger::HandshakeSignatureValid, rustls::Error> {
        rustls::crypto::verify_tls13_signature(message, cert, dss, &self.0.signature_verification_algorithms)
    }

    fn supported_verify_schemes(&self) -> Vec<rustls::SignatureScheme> {
        self.0.signature_verification_algorithms.supported_schemes()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn status_lines_as_curl_reads_them() {
        assert_eq!(status(b"HTTP/1.1 200 OK").unwrap(), ("1.1".into(), 200, "OK".into(), 200));
        assert_eq!(status(b"HTTP/2 204").unwrap(), ("2".into(), 204, String::new(), 204));
        assert_eq!(status(b"http/1.1 404 nope").unwrap(), ("1.1".into(), 404, "nope".into(), 0));
        assert_eq!(status(b"HTTP/1.1 abc").unwrap_err().message(), "Unsupported HTTP/1 subversion in response");
        assert_eq!(status(b"HTTP/1.1 1000 X").unwrap_err().message(), "Invalid status line");
        assert_eq!(status(b"HTTP/4 200").unwrap_err().message(), "Unsupported HTTP version in response");
    }

    #[test]
    fn php_reads_a_status_line_case_insensitively() {
        assert_eq!(php_status(b"Http/1.0\t301 Moved  "), ("1.0".into(), 301, "Moved  ".into()));
        assert_eq!(php_status(b"HTTP/1. 200"), ("1.1".into(), 0, String::new()));
        assert_eq!(php_status(b"HTTP/1.1 2000"), ("1.1".into(), 0, String::new()));
    }

    #[test]
    fn a_header_block_ends_as_php_finds_it() {
        assert!(terminated(b"HTTP/1.1 200 OK\r\n\r\n"));
        assert!(terminated(b"HTTP/1.1 200 OK\n\n"));
        assert!(!terminated(b"HTTP/1.1 200 OK\nX: 1\n\r\n"));
    }

    #[test]
    fn hosts_curl_refuses() {
        assert!(check_host("example.com").is_ok());
        assert!(check_host("[::1]").is_ok());
        assert_eq!(check_host("exa{mple").unwrap_err().message(), "URL rejected: Bad hostname");
    }
}
