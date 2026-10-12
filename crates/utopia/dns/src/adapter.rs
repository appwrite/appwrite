//! Adapters: the transports a server listens on, on Tokio.

use std::convert::Infallible;
use std::future::Future;
use std::net::SocketAddr;
use std::path::PathBuf;
use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use hyper::body::Incoming;
use hyper::{Request, Response};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream, UdpSocket};

use crate::Error;
use crate::message::MAX_SIZE;
use crate::proxy_protocol::ProxyProtocol;
use crate::query::Protocol;
use crate::server::Handler;

/// A worker start callback: it gets the worker's id.
pub type WorkerStart = Box<dyn Fn(usize) + Send + Sync>;

/// Serves packets: listens on transports and hands every packet to a
/// [`Handler`], sending back what it returns.
///
/// PHP: `Utopia\DNS\Adapter`.
pub trait Adapter: Send + 'static {
    /// Adds a callback run when a worker starts.
    ///
    /// PHP: `onWorkerStart()`.
    fn on_worker_start(&mut self, callback: WorkerStart);

    /// Binds and serves until the future is dropped.
    ///
    /// PHP: `onPacket()` and `start()`.
    fn start<H: Handler>(self, handler: Arc<H>) -> impl Future<Output = Result<(), Error>> + Send;
}

/// The Tokio adapter: any number of UDP, TCP and DNS-over-HTTPS
/// transports in one runtime. UDP and TCP transports can share a port
/// number; each HTTP transport needs its own.
///
/// Packets are handled concurrently; frames on one TCP connection are
/// answered in order. Worker start callbacks run once, with id 0, after
/// every transport is bound.
///
/// ```no_run
/// use utopia_dns::adapter::{Tcp, Tokio, Transport, Udp};
/// use utopia_dns::resolver::Memory;
/// use utopia_dns::{Server, Zone};
/// # async fn serve(zone: Zone) -> Result<(), utopia_dns::Error> {
/// let adapter = Tokio::new(vec![
///     Transport::Udp(Udp::new("0.0.0.0", 5300)),
///     Transport::Tcp(Tcp::new("0.0.0.0", 5300)),
/// ])?;
/// Server::new(Memory::new(zone)).start(adapter).await;
/// # Ok(()) }
/// ```
///
/// ```php
/// use Utopia\DNS\Adapter\Native;
/// use Utopia\DNS\Resolver\Memory;
/// use Utopia\DNS\Server;
///
/// $adapter = new Native([new Native\Udp('0.0.0.0', 5300), new Native\Tcp('0.0.0.0', 5300)]);
/// // (new Server($adapter, new Memory($zone)))->start();
/// echo count([$adapter]), "\n"; // 1
/// ```
///
/// PHP: `Utopia\DNS\Adapter\Native` (a blocking select loop) and
/// `Utopia\DNS\Adapter\Swoole` (Swoole workers); TCP follows `Native\Tcp`.
pub struct Tokio {
    transports: Vec<Transport>,
    on_worker_start: Vec<WorkerStart>,
}

/// A transport of the [`Tokio`] adapter.
#[derive(Debug, Clone)]
pub enum Transport {
    /// DNS over UDP.
    Udp(Udp),
    /// DNS over TCP.
    Tcp(Tcp),
    /// DNS over HTTPS.
    Http(Http),
}

impl Transport {
    fn kind(&self) -> &'static str {
        match self {
            Transport::Udp(_) => "udp",
            Transport::Tcp(_) => "tcp",
            Transport::Http(_) => "http",
        }
    }
}

/// UDP (RFC 1035 §4.2.1): answers are cut to 512 bytes.
///
/// PHP: `Native\Udp`, `Swoole\Udp`.
#[derive(Debug, Clone)]
pub struct Udp {
    /// The address to bind.
    pub host: String,
    /// The port to bind (0 for any).
    pub port: u16,
}

impl Udp {
    /// A UDP transport on `host`:`port`.
    pub fn new(host: &str, port: u16) -> Udp {
        Udp { host: host.to_owned(), port }
    }
}

/// TCP with 2-byte length prefixes (RFC 1035 §4.2.2, RFC 7766).
///
/// A connection is closed when it sends a zero-length or oversized frame,
/// when its unanswered bytes exceed `max_buffer_size`, after `idle_timeout`
/// without data, or when an answer exceeds 65535 bytes. Connections beyond
/// `max_clients` are closed at once. With `proxy_protocol`, every
/// connection must start with a PROXY protocol header, whose source
/// address is the client's.
///
/// PHP: `Native\Tcp`, `Swoole\Tcp`.
#[derive(Debug, Clone)]
pub struct Tcp {
    /// The address to bind.
    pub host: String,
    /// The port to bind (0 for any).
    pub port: u16,
    /// The most connections open at once.
    pub max_clients: usize,
    /// The most bytes buffered for one connection.
    pub max_buffer_size: usize,
    /// The largest frame accepted.
    pub max_frame_size: usize,
    /// How long a connection may stay silent.
    pub idle_timeout: Duration,
    /// Whether connections start with a PROXY protocol header.
    pub proxy_protocol: bool,
}

impl Tcp {
    /// A TCP transport on `host`:`port`: 100 clients, 16 KiB buffers,
    /// 65535-byte frames, 30 seconds of idleness, no PROXY protocol.
    pub fn new(host: &str, port: u16) -> Tcp {
        Tcp {
            host: host.to_owned(),
            port,
            max_clients: 100,
            max_buffer_size: 16384,
            max_frame_size: MAX_SIZE,
            idle_timeout: Duration::from_secs(30),
            proxy_protocol: false,
        }
    }
}

/// DNS over HTTPS (RFC 8484): wire-format queries by `GET` (`?dns=` in
/// base64url) or `POST` (`application/dns-message`), on HTTP/1.1, or
/// HTTP/2 over TLS. Without a certificate it serves plain HTTP, behind a
/// TLS-terminating proxy.
///
/// PHP: `Swoole\Http`.
#[derive(Debug, Clone)]
pub struct Http {
    /// The address to bind.
    pub host: String,
    /// The port to bind (0 for any).
    pub port: u16,
    certificate: Option<(PathBuf, PathBuf)>,
    /// Whether the client's address is the last `X-Forwarded-For` entry.
    pub trust_proxy: bool,
}

/// The media type of DNS messages over HTTP.
pub const CONTENT_TYPE: &str = "application/dns-message";

impl Http {
    /// An HTTP transport on `host`:`port`, over TLS when both a certificate
    /// and a key (PEM files) are given.
    ///
    /// Fails with [`Error::Exception`] when only one of them is.
    ///
    /// PHP: `new Swoole\Http($host, $port, $certPath, $keyPath, $trustProxy)`.
    pub fn new(
        host: &str,
        port: u16,
        cert: Option<PathBuf>,
        key: Option<PathBuf>,
        trust_proxy: bool,
    ) -> Result<Http, Error> {
        let certificate = match (cert, key) {
            (None, None) => None,
            (Some(cert), Some(key)) => Some((cert, key)),
            _ => return Err(Error::exception("TLS requires both a certificate and a key path.")),
        };
        Ok(Http { host: host.to_owned(), port, certificate, trust_proxy })
    }
}

impl Tokio {
    /// An adapter serving `transports`.
    ///
    /// Fails with [`Error::Exception`] when there is none.
    ///
    /// PHP: `new Native($transports)`, `new Swoole($transports)`.
    pub fn new(transports: Vec<Transport>) -> Result<Tokio, Error> {
        if transports.is_empty() {
            return Err(Error::exception("At least one transport is required."));
        }
        Ok(Tokio { transports, on_worker_start: Vec::new() })
    }

    /// Binds every transport, so their addresses are known before serving.
    ///
    /// Fails with [`Error::Exception`] when one cannot be bound.
    pub async fn bind(self) -> Result<Bound, Error> {
        let mut listeners = Vec::with_capacity(self.transports.len());
        for transport in self.transports {
            let listener = match transport {
                Transport::Udp(udp) => {
                    let socket = UdpSocket::bind((udp.host.as_str(), udp.port)).await.map_err(|_| {
                        Error::exception(format!("Could not bind UDP socket to {}:{}.", udp.host, udp.port))
                    })?;
                    Listener::Udp(Arc::new(socket))
                }
                Transport::Tcp(tcp) => {
                    let listener = TcpListener::bind((tcp.host.as_str(), tcp.port)).await.map_err(|_| {
                        Error::exception(format!("Could not bind TCP socket to {}:{}.", tcp.host, tcp.port))
                    })?;
                    Listener::Tcp(listener, tcp)
                }
                Transport::Http(http) => {
                    let listener = TcpListener::bind((http.host.as_str(), http.port))
                        .await
                        .map_err(|_| Error::exception(format!("Could not listen on {}:{}.", http.host, http.port)))?;
                    let tls = match &http.certificate {
                        None => None,
                        Some((cert, key)) => Some(tls(cert, key)?),
                    };
                    Listener::Http(listener, http, tls)
                }
            };
            listeners.push(listener);
        }
        Ok(Bound { listeners, on_worker_start: self.on_worker_start })
    }
}

impl Adapter for Tokio {
    fn on_worker_start(&mut self, callback: WorkerStart) {
        self.on_worker_start.push(callback);
    }

    async fn start<H: Handler>(self, handler: Arc<H>) -> Result<(), Error> {
        self.bind().await?.serve(handler).await;
        Ok(())
    }
}

fn tls(cert: &PathBuf, key: &PathBuf) -> Result<tokio_rustls::TlsAcceptor, Error> {
    use rustls::pki_types::pem::PemObject;
    use rustls::pki_types::{CertificateDer, PrivateKeyDer};
    let failed = |what: &str| Error::exception(format!("Could not load the TLS {what}."));
    let certs = CertificateDer::pem_file_iter(cert)
        .map_err(|_| failed("certificate"))?
        .collect::<Result<Vec<_>, _>>()
        .map_err(|_| failed("certificate"))?;
    let key = PrivateKeyDer::from_pem_file(key).map_err(|_| failed("key"))?;
    let mut config = rustls::ServerConfig::builder_with_provider(Arc::new(rustls::crypto::ring::default_provider()))
        .with_safe_default_protocol_versions()
        .map_err(|_| failed("configuration"))?
        .with_no_client_auth()
        .with_single_cert(certs, key)
        .map_err(|_| failed("certificate"))?;
    config.alpn_protocols = vec![b"h2".to_vec(), b"http/1.1".to_vec()];
    Ok(tokio_rustls::TlsAcceptor::from(Arc::new(config)))
}

enum Listener {
    Udp(Arc<UdpSocket>),
    Tcp(TcpListener, Tcp),
    Http(TcpListener, Http, Option<tokio_rustls::TlsAcceptor>),
}

/// The transports of a [`Tokio`] adapter, bound.
pub struct Bound {
    listeners: Vec<Listener>,
    on_worker_start: Vec<WorkerStart>,
}

impl Bound {
    /// Each transport's kind (`udp`, `tcp`, `http`) and local address, in order.
    pub fn local_addrs(&self) -> Vec<(&'static str, SocketAddr)> {
        self.listeners
            .iter()
            .filter_map(|l| match l {
                Listener::Udp(s) => s.local_addr().ok().map(|a| ("udp", a)),
                Listener::Tcp(s, _) => s.local_addr().ok().map(|a| ("tcp", a)),
                Listener::Http(s, _, _) => s.local_addr().ok().map(|a| ("http", a)),
            })
            .collect()
    }

    /// Serves until the future is dropped.
    pub async fn serve<H: Handler>(self, handler: Arc<H>) {
        for callback in &self.on_worker_start {
            callback(0);
        }
        let mut tasks = tokio::task::JoinSet::new();
        for listener in self.listeners {
            let handler = handler.clone();
            match listener {
                Listener::Udp(socket) => {
                    tasks.spawn(serve_udp(socket, handler));
                }
                Listener::Tcp(listener, options) => {
                    tasks.spawn(serve_tcp(listener, options, handler));
                }
                Listener::Http(listener, options, tls) => {
                    tasks.spawn(serve_http(listener, options, tls, handler));
                }
            }
        }
        while tasks.join_next().await.is_some() {}
    }
}

async fn serve_udp<H: Handler>(socket: Arc<UdpSocket>, handler: Arc<H>) {
    let mut buffer = vec![0u8; 65535];
    loop {
        let Ok((length, peer)) = socket.recv_from(&mut buffer).await else {
            continue;
        };
        if length == 0 {
            continue;
        }
        let packet = buffer[..length].to_vec();
        let (socket, handler) = (socket.clone(), handler.clone());
        tokio::spawn(async move {
            let ip = peer.ip().to_string();
            let answer = handler.handle(&packet, &ip, peer.port(), Protocol::Udp).await;
            if !answer.is_empty() {
                let _ = socket.send_to(&answer, peer).await;
            }
        });
    }
}

async fn serve_tcp<H: Handler>(listener: TcpListener, options: Tcp, handler: Arc<H>) {
    let clients = Arc::new(AtomicUsize::new(0));
    let options = Arc::new(options);
    loop {
        let Ok((stream, peer)) = listener.accept().await else {
            continue;
        };
        if clients.load(Ordering::SeqCst) >= options.max_clients {
            drop(stream);
            continue;
        }
        clients.fetch_add(1, Ordering::SeqCst);
        let (clients, options, handler) = (clients.clone(), options.clone(), handler.clone());
        tokio::spawn(async move {
            let _ = stream.set_nodelay(true);
            connection(stream, peer, &options, handler.as_ref()).await;
            clients.fetch_sub(1, Ordering::SeqCst);
        });
    }
}

/// One TCP connection, served until it closes or breaks a limit.
async fn connection<H: Handler>(mut stream: TcpStream, peer: SocketAddr, options: &Tcp, handler: &H) {
    let mut buffer: Vec<u8> = Vec::new();
    let mut chunk = vec![0u8; 8192];
    let mut awaiting_proxy = options.proxy_protocol;
    let mut source: Option<(String, u16)> = None;
    loop {
        let read = match tokio::time::timeout(options.idle_timeout, stream.read(&mut chunk)).await {
            Ok(Ok(0)) | Ok(Err(_)) | Err(_) => return,
            Ok(Ok(n)) => n,
        };
        if buffer.len() + read > options.max_buffer_size {
            return;
        }
        buffer.extend_from_slice(&chunk[..read]);
        if awaiting_proxy {
            match ProxyProtocol::parse(&buffer) {
                Err(_) => return,
                Ok(None) => continue,
                Ok(Some(header)) => {
                    buffer.drain(..header.length.min(buffer.len()));
                    awaiting_proxy = false;
                    source = header.source;
                }
            }
        }
        while buffer.len() >= 2 {
            let length = usize::from(u16::from_be_bytes([buffer[0], buffer[1]]));
            if length == 0 || length > options.max_frame_size {
                return;
            }
            if buffer.len() < length + 2 {
                break;
            }
            let message: Vec<u8> = buffer.drain(..length + 2).skip(2).collect();
            let (ip, port) = match &source {
                Some((ip, port)) => (ip.clone(), *port),
                None => (peer.ip().to_string(), peer.port()),
            };
            let answer = handler.handle(&message, &ip, port, Protocol::Tcp).await;
            if answer.is_empty() {
                continue;
            }
            if answer.len() > MAX_SIZE {
                return;
            }
            let mut frame = Vec::with_capacity(answer.len() + 2);
            frame.extend_from_slice(&(answer.len() as u16).to_be_bytes());
            frame.extend_from_slice(&answer);
            if stream.write_all(&frame).await.is_err() {
                return;
            }
        }
    }
}

async fn serve_http<H: Handler>(
    listener: TcpListener,
    options: Http,
    tls: Option<tokio_rustls::TlsAcceptor>,
    handler: Arc<H>,
) {
    let options = Arc::new(options);
    loop {
        let Ok((stream, peer)) = listener.accept().await else {
            continue;
        };
        let (options, handler, tls) = (options.clone(), handler.clone(), tls.clone());
        tokio::spawn(async move {
            let service = hyper::service::service_fn(move |request: Request<Incoming>| {
                let (options, handler) = (options.clone(), handler.clone());
                async move { Ok::<_, Infallible>(doh(request, peer, &options, handler.as_ref()).await) }
            });
            let builder = hyper_util::server::conn::auto::Builder::new(hyper_util::rt::TokioExecutor::new());
            match tls {
                None => {
                    let _ = builder.serve_connection(hyper_util::rt::TokioIo::new(stream), service).await;
                }
                Some(tls) => {
                    if let Ok(stream) = tls.accept(stream).await {
                        let _ = builder.serve_connection(hyper_util::rt::TokioIo::new(stream), service).await;
                    }
                }
            }
        });
    }
}

/// Answers one DNS-over-HTTPS request.
async fn doh<H: Handler>(
    request: Request<Incoming>,
    peer: SocketAddr,
    options: &Http,
    handler: &H,
) -> Response<Full<Bytes>> {
    let mut ip = peer.ip().to_string();
    if options.trust_proxy
        && let Some(forwarded) = request.headers().get("x-forwarded-for").and_then(|v| v.to_str().ok())
        && !forwarded.is_empty()
        && let Some(last) = forwarded.split(',').next_back()
    {
        ip = String::from_utf8_lossy(php_std::string::trim(last.as_bytes(), php_std::string::TRIM_CHARACTERS))
            .into_owned();
    }
    let query = read_query(request).await;
    let Some(query) = query else {
        return bad_request();
    };
    let answer = handler.handle(&query, &ip, peer.port(), Protocol::Https).await;
    if answer.is_empty() {
        return bad_request();
    }
    let mut response = Response::new(Full::new(Bytes::from(answer)));
    response.headers_mut().insert(hyper::header::CONTENT_TYPE, hyper::header::HeaderValue::from_static(CONTENT_TYPE));
    response
}

fn bad_request() -> Response<Full<Bytes>> {
    let mut response = Response::new(Full::new(Bytes::new()));
    *response.status_mut() = hyper::StatusCode::BAD_REQUEST;
    response.headers_mut().insert(hyper::header::CONTENT_TYPE, hyper::header::HeaderValue::from_static("text/html"));
    response
}

/// The wire-format query of an RFC 8484 request, if it carries one.
async fn read_query(request: Request<Incoming>) -> Option<Vec<u8>> {
    match *request.method() {
        hyper::Method::GET => {
            let query = request.uri().query().unwrap_or_default();
            let params = php_std::encoding::parse_str(query.as_bytes());
            let Some(php_std::Value::Str(dns)) = params.get_str("dns") else {
                return None;
            };
            if dns.is_empty() {
                return None;
            }
            let standard = php_std::string::strtr(dns, b"-_", b"+/");
            php_std::encoding::base64_decode(&standard, true).filter(|d| !d.is_empty())
        }
        hyper::Method::POST => {
            let content_type = request.headers().get(hyper::header::CONTENT_TYPE)?.as_bytes();
            let media = content_type.split(|&b| b == b';').next().unwrap_or_default();
            let media = php_std::string::trim(media, php_std::string::TRIM_CHARACTERS).to_ascii_lowercase();
            if media != CONTENT_TYPE.as_bytes() {
                return None;
            }
            let body = request.into_body().collect().await.ok()?.to_bytes();
            if body.is_empty() { None } else { Some(body.to_vec()) }
        }
        _ => None,
    }
}

impl std::fmt::Debug for Tokio {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Tokio")
            .field("transports", &self.transports.iter().map(Transport::kind).collect::<Vec<_>>())
            .finish_non_exhaustive()
    }
}
