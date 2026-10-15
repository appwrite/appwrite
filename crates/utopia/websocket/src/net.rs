//! The network adapter on tokio and tokio-tungstenite, and the client's
//! network transport.

use std::collections::HashMap;
use std::io;
use std::net::SocketAddr;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex, MutexGuard, RwLock};
use std::time::Duration;

use bytes::{Bytes, BytesMut};
use futures_util::future::BoxFuture;
use futures_util::{SinkExt, StreamExt};
use tokio_tungstenite::tungstenite::error::ProtocolError;
use tokio_tungstenite::tungstenite::handshake::derive_accept_key;
use tokio_tungstenite::tungstenite::protocol::{Role, WebSocketConfig};
use tokio_tungstenite::tungstenite::{self, Message, Utf8Bytes};
use tokio_tungstenite::{MaybeTlsStream, WebSocketStream};
use utopia_async::io::{AsyncReadExt, AsyncWriteExt};
use utopia_async::net::{TcpListener, TcpStream};
use utopia_async::sync::{Notify, mpsc, watch};

use crate::Error;
use crate::adapter::{
    Adapter, CloseHook, ConnectionId, Hooks, MessageHook, OpenHook, Request, RequestHook, StartHook, WorkerHook,
    response,
};
use crate::client::Frame;
use crate::client::{NO_CONNECTION, Opcode, Received, TIMED_OUT, Transport, TransportError};

/// The send timeout when none is given: 5 seconds.
///
/// PHP: `Swoole::DEFAULT_SEND_TIMEOUT`.
pub const DEFAULT_SEND_TIMEOUT: f64 = 5.0;

/// The most connections served at once.
const MAX_CONNECTIONS: i64 = 1_000_000;

/// The largest HTTP request head read before a request is refused.
const MAX_HEAD: usize = 64 * 1024;

/// The configuration a [`Network`] server starts with.
///
/// PHP: the Swoole server settings the adapter passes to `Server::set()`
/// (`max_connection`, `send_timeout`, `package_max_length`,
/// `websocket_compression`, `worker_num`).
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Config {
    /// The most connections served at once (1,000,000, Swoole's limit).
    pub max_connection: i64,
    /// Seconds each send may wait for a connection's buffer to drain before
    /// the connection is reset.
    pub send_timeout: f64,
    /// The largest message accepted, in bytes (`None`: tungstenite's
    /// default, 64 MiB).
    pub package_max_length: Option<i64>,
    /// Whether compression was enabled. tungstenite does not implement
    /// `permessage-deflate`, so messages are sent uncompressed either way.
    pub compression: Option<bool>,
    /// The number of workers: the worker hooks are called once per worker
    /// id (one worker when unset). Every worker shares the runtime.
    pub workers: Option<i64>,
}

enum Command {
    Send(Bytes),
    Close,
}

struct Inner {
    host: String,
    port: u16,
    config: Mutex<Config>,
    hooks: RwLock<Hooks>,
    connections: Mutex<HashMap<ConnectionId, mpsc::UnboundedSender<Command>>>,
    next: AtomicI64,
    stop: Notify,
    addr: watch::Sender<Option<SocketAddr>>,
}

/// A WebSocket server on the network.
///
/// [`start`](Adapter::start) listens on the host and port, calls the start
/// hook and the worker start hook for each worker, then serves until
/// [`shutdown`](Adapter::shutdown). Each connection gets the next id from 1.
/// A request that asks for a WebSocket upgrade becomes a connection (the
/// open hook gets its handshake); any other request goes to the request
/// hook and is answered with the response it fills, then the connection is
/// closed.
///
/// [`send`](Adapter::send) queues the message for each connection and
/// returns at once; each connection writes its queue in order. A write that
/// waits longer than the send timeout for the peer to read resets the
/// connection (its queued messages are dropped and the close hook runs), so
/// a stalled client cannot hold memory or block others. A message is sent
/// as a text frame, or as a binary frame when it is not UTF-8.
///
/// [`close`](Adapter::close) writes the connection's queued messages, then
/// ends its TCP connection without a close frame: the close code is not
/// sent, and the client sees the connection end.
///
/// PHP: Swoole's `close($fd)` does the same, a quirk recorded in the compat
/// spec (`$code` is ignored by both PHP adapters).
///
/// ```no_run
/// use utopia_websocket::{Server, Network};
///
/// # async fn run() -> Result<(), utopia_websocket::Error> {
/// let adapter = Network::new("0.0.0.0", 8080, 5.0)?;
/// let server = Server::new(adapter);
/// let replies = server.clone();
/// server
///     .on_open(|connection, request| println!("{connection} opened {}", request.uri()))
///     .on_message(move |connection, message| replies.send(&[connection], message.to_vec()))
///     .error(|error, stage| eprintln!("{stage}: {error}"));
/// server.start().await;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\WebSocket\Adapter\Swoole;
/// use Utopia\WebSocket\Server;
///
/// $server = new Server(new Swoole('0.0.0.0', 8080, 5.0));
/// $server
///     ->onOpen(fn (int $connection, $request) => print("{$connection} opened\n"))
///     ->onMessage(fn (int $connection, string $message) => $server->send([$connection], $message))
///     ->error(fn (\Throwable $error, string $stage) => print("{$stage}: {$error->getMessage()}\n"));
/// // $server->start();
/// ```
///
/// PHP: `Utopia\WebSocket\Adapter\Swoole` (and `Adapter\Workerman`).
#[derive(Clone)]
pub struct Network {
    inner: Arc<Inner>,
}

impl Network {
    /// A server for `host` and `port` (`0` picks a free port; see
    /// [`Network::local_addr`]) whose sends wait at most `send_timeout`
    /// seconds.
    ///
    /// Fails with [`Error::InvalidArgument`] when `send_timeout` is not a
    /// finite positive number.
    ///
    /// PHP: `new Swoole(string $host = '0.0.0.0', int $port = 80, float
    /// $sendTimeout = 5.0)`.
    pub fn new(host: impl Into<String>, port: u16, send_timeout: f64) -> Result<Self, Error> {
        if !send_timeout.is_finite() || send_timeout <= 0.0 {
            return Err(Error::InvalidArgument("Send timeout must be a finite positive number of seconds".into()));
        }
        Ok(Self {
            inner: Arc::new(Inner {
                host: host.into(),
                port,
                config: Mutex::new(Config {
                    max_connection: MAX_CONNECTIONS,
                    send_timeout,
                    package_max_length: None,
                    compression: None,
                    workers: None,
                }),
                hooks: RwLock::default(),
                connections: Mutex::default(),
                next: AtomicI64::new(1),
                stop: Notify::new(),
                addr: watch::Sender::new(None),
            }),
        })
    }

    /// The configuration the server starts with.
    pub fn config(&self) -> Config {
        *self.inner.config.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// The address the server listens on, once started.
    pub fn local_addr(&self) -> Option<SocketAddr> {
        *self.inner.addr.borrow()
    }

    /// Waits until the server listens, and returns its address.
    pub async fn listening(&self) -> SocketAddr {
        let mut rx = self.inner.addr.subscribe();
        loop {
            if let Some(addr) = *rx.borrow_and_update() {
                return addr;
            }
            if rx.changed().await.is_err() {
                std::future::pending::<()>().await;
            }
        }
    }

    /// The open connections.
    pub fn connections(&self) -> Vec<ConnectionId> {
        let mut ids: Vec<_> = self.connections_map().keys().copied().collect();
        ids.sort_unstable();
        ids
    }

    fn connections_map(&self) -> MutexGuard<'_, HashMap<ConnectionId, mpsc::UnboundedSender<Command>>> {
        self.inner.connections.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn set_hook(&self, set: impl FnOnce(&mut Hooks)) -> Result<(), Error> {
        set(&mut self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()));
        Ok(())
    }

    fn update(&self, set: impl FnOnce(&mut Config)) {
        set(&mut self.inner.config.lock().unwrap_or_else(|e| e.into_inner()));
    }
}

impl Adapter for Network {
    async fn start(&self) -> Result<(), Error> {
        let listener = TcpListener::bind((self.inner.host.as_str(), self.inner.port)).await.map_err(|e| {
            Error::Exception(format!("failed to listen server port[{}:{}]: {e}", self.inner.host, self.inner.port))
        })?;
        let addr = listener.local_addr().map_err(|e| Error::Exception(e.to_string()))?;
        let hooks = Arc::new(self.inner.hooks.read().unwrap_or_else(|e| e.into_inner()).clone());
        let config = self.config();
        let workers = config.workers.unwrap_or(1);
        self.inner.addr.send_replace(Some(addr));
        if let Some(hook) = &hooks.start {
            hook();
        }
        if let Some(hook) = &hooks.worker_start {
            for worker in 0..workers {
                hook(worker);
            }
        }
        let stopped = self.inner.stop.notified();
        utopia_async::pin!(stopped);
        loop {
            utopia_async::select! {
                () = &mut stopped => break,
                accepted = listener.accept() => {
                    let Ok((stream, _)) = accepted else { continue };
                    if self.connections_map().len() as i64 >= config.max_connection {
                        continue;
                    }
                    let _ = stream.set_nodelay(true);
                    utopia_async::spawn(serve(self.clone(), hooks.clone(), config, stream));
                }
            }
        }
        drop(listener);
        for sender in std::mem::take(&mut *self.connections_map()).into_values() {
            let _ = sender.send(Command::Close);
        }
        self.inner.addr.send_replace(None);
        if let Some(hook) = &hooks.worker_stop {
            for worker in 0..workers {
                hook(worker);
            }
        }
        Ok(())
    }

    fn shutdown(&self) -> Result<(), Error> {
        // Wakes a running start(); a server not running is left as is.
        self.inner.stop.notify_waiters();
        Ok(())
    }

    fn send(&self, connections: &[ConnectionId], message: Bytes) -> Result<(), Error> {
        let map = self.connections_map();
        for connection in connections {
            if let Some(sender) = map.get(connection) {
                let _ = sender.send(Command::Send(message.clone()));
            }
        }
        Ok(())
    }

    fn close(&self, connection: ConnectionId, _code: i64) -> Result<(), Error> {
        if let Some(sender) = self.connections_map().get(&connection) {
            let _ = sender.send(Command::Close);
        }
        Ok(())
    }

    fn on_start(&self, hook: StartHook) -> Result<(), Error> {
        self.set_hook(|h| h.start = Some(hook))
    }

    fn on_worker_start(&self, hook: WorkerHook) -> Result<(), Error> {
        self.set_hook(|h| h.worker_start = Some(hook))
    }

    fn on_worker_stop(&self, hook: WorkerHook) -> Result<(), Error> {
        self.set_hook(|h| h.worker_stop = Some(hook))
    }

    fn on_open(&self, hook: OpenHook) -> Result<(), Error> {
        self.set_hook(|h| h.open = Some(hook))
    }

    fn on_message(&self, hook: MessageHook) -> Result<(), Error> {
        self.set_hook(|h| h.message = Some(hook))
    }

    fn on_close(&self, hook: CloseHook) -> Result<(), Error> {
        self.set_hook(|h| h.close = Some(hook))
    }

    fn on_request(&self, hook: RequestHook) -> Result<(), Error> {
        self.set_hook(|h| h.request = Some(hook))
    }

    fn set_package_max_length(&self, bytes: i64) {
        self.update(|c| c.package_max_length = Some(bytes));
    }

    fn set_compression_enabled(&self, enabled: bool) {
        self.update(|c| c.compression = Some(enabled));
    }

    fn set_worker_number(&self, workers: i64) {
        self.update(|c| c.workers = Some(workers));
    }
}

/// Reads the request head (and body), then serves a WebSocket connection
/// or answers a plain HTTP request.
async fn serve(server: Network, hooks: Arc<Hooks>, config: Config, mut stream: TcpStream) {
    let mut buffer = BytesMut::with_capacity(4096);
    let (request, head) = loop {
        if stream.read_buf(&mut buffer).await.unwrap_or(0) == 0 {
            return;
        }
        match parse(&buffer) {
            Ok(Some(parsed)) => break parsed,
            Ok(None) if buffer.len() < MAX_HEAD => continue,
            _ => {
                let _ = stream
                    .write_all(b"HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n")
                    .await;
                return;
            }
        }
    };
    let upgrade =
        request.headers().get(http::header::UPGRADE).is_some_and(|v| v.as_bytes().eq_ignore_ascii_case(b"websocket"));
    let key = request.headers().get("sec-websocket-key").map(|v| v.as_bytes().to_vec());
    let _ = buffer.split_to(head);
    match (upgrade, key) {
        (true, Some(key)) => websocket(server, hooks, config, stream, request, &key, buffer.to_vec()).await,
        _ => plain(&hooks, stream, request, buffer).await,
    }
}

/// A parsed request head: the request (without its body) and the head's
/// length.
fn parse(buffer: &[u8]) -> Result<Option<(Request, usize)>, httparse::Error> {
    let mut headers = [httparse::EMPTY_HEADER; 64];
    let mut req = httparse::Request::new(&mut headers);
    let httparse::Status::Complete(head) = req.parse(buffer)? else { return Ok(None) };
    let mut builder = http::Request::builder()
        .method(req.method.unwrap_or("GET"))
        .uri(req.path.unwrap_or("/"))
        .version(if req.version == Some(0) { http::Version::HTTP_10 } else { http::Version::HTTP_11 });
    for header in req.headers.iter() {
        builder = builder.header(header.name, header.value);
    }
    Ok(builder.body(Bytes::new()).ok().map(|r| (r, head)))
}

async fn plain(hooks: &Hooks, mut stream: TcpStream, mut request: Request, mut body: BytesMut) {
    let length = request
        .headers()
        .get(http::header::CONTENT_LENGTH)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.parse::<usize>().ok())
        .unwrap_or(0);
    while body.len() < length {
        if stream.read_buf(&mut body).await.unwrap_or(0) == 0 {
            return;
        }
    }
    *request.body_mut() = body.split_to(length).freeze();
    let mut out = response();
    if let Some(hook) = &hooks.request {
        hook(&request, &mut out);
    }
    let status = out.status();
    let mut head = format!("HTTP/1.1 {} {}\r\n", status.as_u16(), status.canonical_reason().unwrap_or(""));
    for (name, value) in out.headers() {
        head.push_str(name.as_str());
        head.push_str(": ");
        head.push_str(&String::from_utf8_lossy(value.as_bytes()));
        head.push_str("\r\n");
    }
    head.push_str(&format!("Content-Length: {}\r\nConnection: close\r\n\r\n", out.body().len()));
    let _ = stream.write_all(head.as_bytes()).await;
    let _ = stream.write_all(out.body()).await;
    let _ = stream.shutdown().await;
}

async fn websocket(
    server: Network,
    hooks: Arc<Hooks>,
    config: Config,
    mut stream: TcpStream,
    request: Request,
    key: &[u8],
    rest: Vec<u8>,
) {
    let accept = derive_accept_key(key);
    let reply = format!(
        "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {accept}\r\n\r\n"
    );
    if stream.write_all(reply.as_bytes()).await.is_err() {
        return;
    }
    let mut settings = WebSocketConfig::default();
    if let Some(max) = config.package_max_length.and_then(|m| usize::try_from(m).ok()).filter(|&m| m > 0) {
        settings = settings.max_message_size(Some(max)).max_frame_size(Some(max));
    }
    let mut ws = WebSocketStream::from_partially_read(stream, rest, Role::Server, Some(settings)).await;
    let id = server.inner.next.fetch_add(1, Ordering::Relaxed);
    let (sender, mut commands) = mpsc::unbounded_channel();
    server.connections_map().insert(id, sender);
    if let Some(hook) = &hooks.open {
        hook(id, &request);
    }
    let timeout = Duration::from_secs_f64(config.send_timeout);
    loop {
        utopia_async::select! {
            incoming = ws.next() => match incoming {
                Some(Ok(Message::Text(text))) => {
                    if let Some(hook) = &hooks.message {
                        hook(id, text.as_bytes());
                    }
                }
                Some(Ok(Message::Binary(data))) => {
                    if let Some(hook) = &hooks.message {
                        hook(id, &data);
                    }
                }
                Some(Ok(Message::Close(_))) | Some(Err(_)) | None => break,
                Some(Ok(_)) => {}
            },
            command = commands.recv() => match command {
                Some(Command::Send(data)) => {
                    let message = text(data);
                    match utopia_async::timeout(timeout, ws.send(message)).await {
                        Ok(Ok(())) => {}
                        Ok(Err(_)) => break,
                        Err(_) => {
                            // Stalled: drop the queued output with a reset,
                            // as Swoole's close($fd, true) does.
                            let _ = ws.get_ref().set_zero_linger();
                            break;
                        }
                    }
                }
                Some(Command::Close) => {
                    // As Swoole's close($fd): the queued output is written,
                    // then the TCP connection ends without a close frame.
                    let _ = utopia_async::timeout(timeout, ws.get_mut().shutdown()).await;
                    break;
                }
                None => break,
            },
        }
    }
    server.connections_map().remove(&id);
    drop(ws);
    if let Some(hook) = &hooks.close {
        hook(id);
    }
}

/// A text frame, or a binary frame for a message that is not UTF-8 (a text
/// frame must be).
fn text(data: Bytes) -> Message {
    if std::str::from_utf8(&data).is_err() {
        return Message::Binary(data);
    }
    match Utf8Bytes::try_from(data) {
        Ok(text) => Message::Text(text),
        Err(_) => Message::Binary(Bytes::new()),
    }
}

/// The client's network transport.
pub(crate) struct Wire {
    ws: WebSocketStream<MaybeTlsStream<TcpStream>>,
    /// The server ended the connection: the first receive after that
    /// reports nothing, the next ones [`NO_CONNECTION`], as Swoole's client.
    ended: bool,
}

/// What the Swoole client reports once the connection is gone.
fn gone() -> TransportError {
    TransportError::new(NO_CONNECTION, "Client no connection")
}

/// The `errno` (Linux numbering, as Swoole reports it) and message for an
/// I/O failure.
fn errno(e: &io::Error) -> TransportError {
    use io::ErrorKind::*;
    let (code, message) = match e.kind() {
        ConnectionRefused => (111, "Connection refused"),
        ConnectionReset => (104, "Connection reset by peer"),
        ConnectionAborted => (103, "Software caused connection abort"),
        TimedOut => (TIMED_OUT, "Connection timed out"),
        BrokenPipe => (32, "Broken pipe"),
        AddrNotAvailable => (99, "Cannot assign requested address"),
        NotConnected => (NO_CONNECTION, "Client no connection"),
        _ => return TransportError::new(e.raw_os_error().map_or(0, i64::from), e.to_string()),
    };
    TransportError::new(code, message)
}

fn failure(e: tungstenite::Error) -> TransportError {
    match e {
        tungstenite::Error::Io(e) => errno(&e),
        tungstenite::Error::ConnectionClosed | tungstenite::Error::AlreadyClosed => gone(),
        tungstenite::Error::Http(response) => {
            TransportError::new(i64::from(response.status().as_u16()), "Bad handshake response")
        }
        other => TransportError::new(0, other.to_string()),
    }
}

/// Connects, upgrades and returns the transport. TLS is used when the port
/// is 443, whatever the scheme (as Swoole's client decides).
pub(crate) async fn connect(
    host: &str,
    port: u16,
    path: &str,
    headers: &[(String, String)],
    timeout: Duration,
) -> Result<Wire, TransportError> {
    let scheme = if port == 443 { "wss" } else { "ws" };
    let mut builder = http::Request::builder()
        .uri(format!("{scheme}://{host}:{port}{path}"))
        .header("Host", host)
        .header("Connection", "Upgrade")
        .header("Upgrade", "websocket")
        .header("Sec-WebSocket-Version", "13")
        .header("Sec-WebSocket-Key", tungstenite::handshake::client::generate_key());
    for (name, value) in headers {
        builder = builder.header(name.as_str(), value.as_str());
    }
    let request = builder.body(()).map_err(|e| TransportError::new(0, e.to_string()))?;
    let config = WebSocketConfig::default().max_frame_size(Some(32 * 1024 * 1024));
    let addrs = match utopia_async::timeout(timeout, utopia_async::net::lookup_host((host, port))).await {
        Ok(Ok(addrs)) => addrs.collect::<Vec<_>>(),
        Ok(Err(_)) => return Err(TransportError::new(711, "DNS Lookup resolve failed")),
        Err(_) => return Err(TransportError::new(TIMED_OUT, "Connection timed out")),
    };
    let Some(addr) = addrs.first() else {
        return Err(TransportError::new(711, "DNS Lookup resolve failed"));
    };
    let stream = match utopia_async::timeout(timeout, TcpStream::connect(addr)).await {
        Ok(Ok(stream)) => stream,
        Ok(Err(e)) => return Err(errno(&e)),
        Err(_) => return Err(TransportError::new(TIMED_OUT, "Connection timed out")),
    };
    let _ = stream.set_nodelay(true);
    let handshake = tokio_tungstenite::client_async_tls_with_config(request, stream, Some(config), None);
    match utopia_async::timeout(timeout, handshake).await {
        Ok(Ok((ws, _))) => Ok(Wire { ws, ended: false }),
        Ok(Err(e)) => Err(failure(e)),
        Err(_) => Err(TransportError::new(TIMED_OUT, "Connection timed out")),
    }
}

impl Transport for Wire {
    fn recv(&mut self, timeout: Duration) -> BoxFuture<'_, Received> {
        Box::pin(async move {
            if self.ended {
                return Received::Failed(gone());
            }
            match utopia_async::timeout(timeout, self.ws.next()).await {
                Err(_) => Received::Failed(TransportError::new(TIMED_OUT, "Connection timed out")),
                Ok(None)
                | Ok(Some(Err(
                    tungstenite::Error::ConnectionClosed
                    | tungstenite::Error::AlreadyClosed
                    | tungstenite::Error::Protocol(ProtocolError::ResetWithoutClosingHandshake),
                ))) => {
                    self.ended = true;
                    Received::Empty
                }
                Ok(Some(Err(e))) => Received::Failed(failure(e)),
                Ok(Some(Ok(message))) => Received::Frame(match message {
                    Message::Text(text) => Frame { opcode: Opcode::Text, data: Bytes::from(text) },
                    Message::Binary(data) => Frame { opcode: Opcode::Binary, data },
                    Message::Ping(data) => Frame { opcode: Opcode::Ping, data },
                    Message::Pong(data) => Frame { opcode: Opcode::Pong, data },
                    Message::Close(frame) => {
                        Frame { opcode: Opcode::Close, data: frame.map(|f| Bytes::from(f.reason)).unwrap_or_default() }
                    }
                    Message::Frame(frame) => {
                        Frame { opcode: Opcode::from_code(u8::from(frame.header().opcode)), data: frame.into_payload() }
                    }
                }),
            }
        })
    }

    fn push(&mut self, data: Bytes, opcode: Opcode) -> BoxFuture<'_, Result<(), TransportError>> {
        Box::pin(async move {
            if self.ended {
                return Err(gone());
            }
            let message = match opcode {
                Opcode::Text => text(data),
                Opcode::Ping => Message::Ping(data),
                Opcode::Pong => Message::Pong(data),
                Opcode::Close => Message::Close(None),
                Opcode::Binary | Opcode::Other(_) => Message::Binary(data),
            };
            self.ws.send(message).await.map_err(failure)
        })
    }

    fn close(&mut self) -> BoxFuture<'_, ()> {
        Box::pin(async move {
            let _ = utopia_async::timeout(Duration::from_secs(1), self.ws.close(None)).await;
        })
    }
}
