//! The network adapter: MQTT over TCP, TLS and WebSocket listeners on
//! tokio.

use std::collections::HashMap;
use std::net::SocketAddr;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex, MutexGuard, OnceLock, RwLock, Weak};

use futures_util::{SinkExt, StreamExt};
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::{Notify, mpsc, watch};
use tokio_rustls::TlsAcceptor;
use tokio_tungstenite::tungstenite::Message;
use tokio_tungstenite::tungstenite::handshake::server::{ErrorResponse, Request, Response};
use tokio_tungstenite::tungstenite::http::HeaderValue;
use tokio_tungstenite::tungstenite::protocol::WebSocketConfig;

use crate::adapter::{Adapter, ConnectionHook, Outbound, ReceiveHook, StartHook, Timer, WorkerHook};
use crate::packet::frames;
use crate::{Error, TimingWheel};

/// The largest packet a listener accepts when none is given, in bytes.
///
/// PHP: the `$maxPacketSize = 64000` default of each transport.
pub const DEFAULT_MAX_PACKET_SIZE: usize = 64000;

/// The most connections served at once.
///
/// PHP: `Swoole::MAX_CONNECTIONS`.
pub const MAX_CONNECTIONS: usize = 100_000;

/// A listener the [`Tokio`] adapter serves MQTT on.
///
/// - [`Tcp`](Transport::Tcp): MQTT on a TCP port (1883 by convention).
/// - [`Tls`](Transport::Tls): MQTT over TLS (8883), with a PEM certificate
///   chain and private key.
/// - [`WebSocket`](Transport::WebSocket): MQTT in WebSocket binary messages
///   (8083), as browsers speak it (MQTT.js, Paho): the handshake echoes the
///   `mqtt` subprotocol, and messages are reassembled into whole packets
///   (a message may hold several packets, or part of one).
///
/// A packet larger than the listener's maximum closes the connection; on
/// WebSocket, so does a partial packet that can no longer fit.
///
/// PHP: `Utopia\Mqtt\Adapter\Swoole\Tcp`, `Tls` and `WebSocket` (and their
/// base, `Transport`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Transport {
    /// Plain TCP.
    Tcp {
        /// The address to bind.
        host: String,
        /// The port (0 picks a free one).
        port: u16,
        /// The largest packet accepted, in bytes.
        max_packet_size: usize,
    },
    /// TLS over TCP.
    Tls {
        /// The address to bind.
        host: String,
        /// The port (0 picks a free one).
        port: u16,
        /// The PEM file of the certificate chain.
        cert: String,
        /// The PEM file of the private key.
        key: String,
        /// The largest packet accepted, in bytes.
        max_packet_size: usize,
    },
    /// WebSocket.
    WebSocket {
        /// The address to bind.
        host: String,
        /// The port (0 picks a free one).
        port: u16,
        /// The largest packet (and WebSocket message) accepted, in bytes.
        max_packet_size: usize,
    },
}

impl Transport {
    /// A TCP listener.
    ///
    /// PHP: `new Tcp(string $host = '0.0.0.0', int $port = 1883, int $maxPacketSize = 64000)`.
    pub fn tcp(host: &str, port: u16) -> Self {
        Transport::Tcp { host: host.to_owned(), port, max_packet_size: DEFAULT_MAX_PACKET_SIZE }
    }

    /// A TLS listener with a certificate chain and private key (PEM files).
    ///
    /// PHP: `new Tls(string $host = '0.0.0.0', int $port = 8883, string $cert = '', string $key = '', int $maxPacketSize = 64000)`.
    pub fn tls(host: &str, port: u16, cert: &str, key: &str) -> Self {
        Transport::Tls {
            host: host.to_owned(),
            port,
            cert: cert.to_owned(),
            key: key.to_owned(),
            max_packet_size: DEFAULT_MAX_PACKET_SIZE,
        }
    }

    /// A WebSocket listener.
    ///
    /// PHP: `new WebSocket(string $host = '0.0.0.0', int $port = 8083, int $maxPacketSize = 64000)`.
    pub fn websocket(host: &str, port: u16) -> Self {
        Transport::WebSocket { host: host.to_owned(), port, max_packet_size: DEFAULT_MAX_PACKET_SIZE }
    }

    /// The transport with another packet size limit.
    pub fn with_max_packet_size(mut self, size: usize) -> Self {
        match &mut self {
            Transport::Tcp { max_packet_size, .. }
            | Transport::Tls { max_packet_size, .. }
            | Transport::WebSocket { max_packet_size, .. } => *max_packet_size = size,
        }
        self
    }

    /// The address it binds.
    ///
    /// PHP: `$host`.
    pub fn host(&self) -> &str {
        match self {
            Transport::Tcp { host, .. } | Transport::Tls { host, .. } | Transport::WebSocket { host, .. } => host,
        }
    }

    /// The port it binds.
    ///
    /// PHP: `$port`.
    pub fn port(&self) -> u16 {
        match self {
            Transport::Tcp { port, .. } | Transport::Tls { port, .. } | Transport::WebSocket { port, .. } => *port,
        }
    }

    /// The largest packet it accepts.
    ///
    /// PHP: the `package_max_length` of `getSettings()`.
    pub fn max_packet_size(&self) -> usize {
        match self {
            Transport::Tcp { max_packet_size, .. }
            | Transport::Tls { max_packet_size, .. }
            | Transport::WebSocket { max_packet_size, .. } => *max_packet_size,
        }
    }
}

enum Command {
    Send(Vec<u8>),
    Close,
}

#[derive(Default)]
struct Hooks {
    start: Option<StartHook>,
    worker_start: Option<WorkerHook>,
    open: Option<ConnectionHook>,
    receive: Option<ReceiveHook>,
    close: Option<ConnectionHook>,
}

struct Inner {
    transports: Vec<Transport>,
    workers: i64,
    timer: Arc<dyn Timer>,
    hooks: RwLock<Hooks>,
    connections: Mutex<HashMap<i64, mpsc::UnboundedSender<Command>>>,
    next: AtomicI64,
    stopped: watch::Sender<bool>,
    addrs: watch::Sender<Vec<SocketAddr>>,
}

/// An MQTT adapter on the network, on tokio: one listener per
/// [`Transport`], all feeding the same hooks.
///
/// [`start`](Adapter::start) binds every listener, calls the start hook and
/// the worker start hook for each worker, then serves until
/// [`shutdown`](Adapter::shutdown). Each connection gets the next id from 1;
/// the receive hook gets one whole MQTT packet at a time.
/// [`send`](Outbound::send) queues bytes for a connection and returns at
/// once (a WebSocket connection gets a binary message);
/// [`close`](Outbound::close) writes what is queued, then closes the
/// connection. The timer (a [`TimingWheel`] by default) closes a connection
/// when it ticks.
///
/// ```no_run
/// use utopia_mqtt::{Tokio, Transport};
///
/// # async fn run() -> Result<(), utopia_mqtt::Error> {
/// let adapter = Tokio::new(
///     vec![Transport::tcp("0.0.0.0", 1883), Transport::websocket("0.0.0.0", 8083)],
///     1,
///     None,
/// )?;
/// // let server = utopia_mqtt::Server::new(adapter, handler);
/// // server.start().await;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Mqtt\Adapter\Swoole;
///
/// $adapter = new Swoole([new Swoole\Tcp('0.0.0.0', 1883), new Swoole\WebSocket('0.0.0.0', 8083)], workers: 1);
/// ```
///
/// PHP: `Utopia\Mqtt\Adapter\Swoole`, whose Swoole server runs worker
/// processes; this adapter serves every connection on the tokio runtime it
/// starts on.
#[derive(Clone)]
pub struct Tokio {
    inner: Arc<Inner>,
}

impl Tokio {
    /// An adapter serving `transports`, calling the worker start hook for
    /// `workers` worker ids, on `timer` (a default [`TimingWheel`] when
    /// `None`). An empty list is an [`Error::InvalidArgument`].
    ///
    /// PHP: `new Swoole(array $transports, int $workers = 1, ?Timer $timer = null)`.
    pub fn new(transports: Vec<Transport>, workers: i64, timer: Option<Arc<dyn Timer>>) -> Result<Self, Error> {
        if transports.is_empty() {
            return Err(Error::InvalidArgument("At least one transport is required.".into()));
        }
        let timer = timer.unwrap_or_else(|| Arc::new(TimingWheel::default()));
        let inner = Arc::new(Inner {
            transports,
            workers,
            timer,
            hooks: RwLock::default(),
            connections: Mutex::default(),
            next: AtomicI64::new(1),
            stopped: watch::channel(false).0,
            addrs: watch::channel(Vec::new()).0,
        });
        let weak: Weak<Inner> = Arc::downgrade(&inner);
        inner.timer.on_tick(Arc::new(move |id| {
            if let Some(inner) = weak.upgrade() {
                let _ = Tokio { inner }.close(id);
            }
        }));
        Ok(Self { inner })
    }

    /// The listeners, in the order given.
    pub fn transports(&self) -> &[Transport] {
        &self.inner.transports
    }

    /// The addresses bound, one per transport in order, once started.
    pub fn local_addrs(&self) -> Vec<SocketAddr> {
        self.inner.addrs.borrow().clone()
    }

    /// Waits until every listener is bound and returns their addresses.
    pub async fn listening(&self) -> Vec<SocketAddr> {
        let mut addrs = self.inner.addrs.subscribe();
        let ready = addrs.wait_for(|a| !a.is_empty()).await.map(|a| a.clone());
        ready.unwrap_or_default()
    }

    /// The open connections.
    pub fn connections(&self) -> Vec<i64> {
        self.connections_map().keys().copied().collect()
    }

    fn connections_map(&self) -> MutexGuard<'_, HashMap<i64, mpsc::UnboundedSender<Command>>> {
        self.inner.connections.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn hook<T: Clone>(&self, pick: impl Fn(&Hooks) -> &Option<T>) -> Option<T> {
        pick(&self.inner.hooks.read().unwrap_or_else(|e| e.into_inner())).clone()
    }

    async fn accept(self, listener: TcpListener, transport: Transport) {
        let tls = match &transport {
            Transport::Tls { cert, key, .. } => match acceptor(cert, key) {
                Ok(acceptor) => Some(acceptor),
                Err(_) => return,
            },
            _ => None,
        };
        let mut stopped = self.inner.stopped.subscribe();
        loop {
            let (stream, _) = tokio::select! {
                accepted = listener.accept() => match accepted {
                    Ok(accepted) => accepted,
                    Err(_) => continue,
                },
                _ = stopped.wait_for(|s| *s) => return,
            };
            if self.connections_map().len() >= MAX_CONNECTIONS {
                continue;
            }
            let _ = stream.set_nodelay(true);
            let adapter = self.clone();
            let transport = transport.clone();
            let tls = tls.clone();
            tokio::spawn(async move {
                match transport {
                    Transport::Tcp { max_packet_size, .. } => adapter.serve_stream(stream, max_packet_size).await,
                    Transport::Tls { max_packet_size, .. } => {
                        let Some(tls) = tls else { return };
                        if let Ok(stream) = tls.accept(stream).await {
                            adapter.serve_stream(stream, max_packet_size).await;
                        }
                    }
                    Transport::WebSocket { max_packet_size, .. } => {
                        adapter.serve_websocket(stream, max_packet_size).await;
                    }
                }
            });
        }
    }

    fn register(&self) -> (i64, mpsc::UnboundedReceiver<Command>) {
        let id = self.inner.next.fetch_add(1, Ordering::Relaxed);
        let (tx, rx) = mpsc::unbounded_channel();
        self.connections_map().insert(id, tx);
        (id, rx)
    }

    fn closed(&self, id: i64) {
        if self.connections_map().remove(&id).is_some()
            && let Some(hook) = self.hook(|h| &h.close)
        {
            let _ = hook(id);
        }
    }

    fn received(&self, id: i64, packet: &[u8]) {
        if let Some(hook) = self.hook(|h| &h.receive) {
            let _ = hook(id, packet);
        }
    }

    /// MQTT on a byte stream: packets are framed by their fixed header.
    async fn serve_stream<S>(&self, stream: S, max_packet_size: usize)
    where
        S: AsyncRead + AsyncWrite + Unpin + Send + 'static,
    {
        let (mut reader, mut writer) = tokio::io::split(stream);
        let (id, mut commands) = self.register();
        let done = Arc::new(Notify::new());
        let finished = done.clone();
        let writing = tokio::spawn(async move {
            while let Some(command) = commands.recv().await {
                match command {
                    Command::Send(bytes) => {
                        if writer.write_all(&bytes).await.is_err() {
                            break;
                        }
                    }
                    Command::Close => break,
                }
            }
            let _ = writer.flush().await;
            let _ = writer.shutdown().await;
            finished.notify_one();
        });
        if let Some(hook) = self.hook(|h| &h.open) {
            let _ = hook(id);
        }
        let mut buffer: Vec<u8> = Vec::with_capacity(1024);
        let mut chunk = vec![0u8; 16 * 1024];
        'read: loop {
            let read = tokio::select! {
                read = reader.read(&mut chunk) => read,
                _ = done.notified() => break,
            };
            let n = match read {
                Ok(0) | Err(_) => break,
                Ok(n) => n,
            };
            buffer.extend_from_slice(&chunk[..n]);
            let mut start = 0;
            loop {
                match frame_length(&buffer[start..]) {
                    Framed::Packet(length) if length > max_packet_size => break 'read,
                    Framed::Packet(length) if buffer.len() - start >= length => {
                        self.received(id, &buffer[start..start + length]);
                        start += length;
                    }
                    Framed::Malformed => break 'read,
                    _ => break,
                }
            }
            buffer.drain(..start);
        }
        if let Some(tx) = self.connections_map().get(&id) {
            let _ = tx.send(Command::Close);
        }
        let _ = writing.await;
        self.closed(id);
    }

    /// MQTT over WebSocket: binary (or text) messages carry the MQTT byte
    /// stream, reassembled into whole packets.
    async fn serve_websocket(&self, stream: TcpStream, max_packet_size: usize) {
        let config = WebSocketConfig::default()
            .max_message_size(Some(max_packet_size.max(1)))
            .max_frame_size(Some(max_packet_size.max(1)));
        let Ok(socket) = tokio_tungstenite::accept_hdr_async_with_config(stream, echo_mqtt, Some(config)).await else {
            return;
        };
        let (mut sink, mut messages) = socket.split();
        let (id, mut commands) = self.register();
        let done = Arc::new(Notify::new());
        let finished = done.clone();
        let writing = tokio::spawn(async move {
            while let Some(command) = commands.recv().await {
                match command {
                    Command::Send(bytes) => {
                        if sink.send(Message::Binary(bytes.into())).await.is_err() {
                            break;
                        }
                    }
                    Command::Close => break,
                }
            }
            let _ = sink.flush().await;
            finished.notify_one();
            sink
        });
        if let Some(hook) = self.hook(|h| &h.open) {
            let _ = hook(id);
        }
        let mut buffer: Vec<u8> = Vec::new();
        loop {
            let message = tokio::select! {
                message = messages.next() => message,
                _ = done.notified() => break,
            };
            let data = match message {
                Some(Ok(Message::Binary(data))) => data.to_vec(),
                Some(Ok(Message::Text(text))) => text.as_bytes().to_vec(),
                Some(Ok(Message::Ping(_) | Message::Pong(_) | Message::Frame(_))) => continue,
                Some(Ok(Message::Close(_))) | Some(Err(_)) | None => break,
            };
            buffer.extend_from_slice(&data);
            let (packets, rest) = frames(&buffer);
            for packet in packets {
                self.received(id, packet);
            }
            if rest.len() > max_packet_size {
                break;
            }
            buffer = rest.to_vec();
        }
        if let Some(tx) = self.connections_map().get(&id) {
            let _ = tx.send(Command::Close);
        }
        if let Ok(sink) = writing.await {
            // Dropping both halves ends the TCP connection without a close
            // frame, as Swoole's close() does.
            drop(sink.reunite(messages));
        }
        self.closed(id);
    }
}

/// The handshake answer: echoes the `mqtt` subprotocol a client asks for, as
/// MQTT-over-WebSocket clients (MQTT.js, Paho) require. The error type is
/// tungstenite's callback contract.
#[allow(clippy::result_large_err)]
fn echo_mqtt(request: &Request, mut response: Response) -> Result<Response, ErrorResponse> {
    if request.headers().contains_key("sec-websocket-protocol") {
        response.headers_mut().insert("Sec-WebSocket-Protocol", HeaderValue::from_static("mqtt"));
    }
    Ok(response)
}

enum Framed {
    /// A whole packet of this many bytes (header included) starts here.
    Packet(usize),
    /// Not enough bytes to know.
    Incomplete,
    /// A remaining length longer than four bytes.
    Malformed,
}

/// The length of the packet at the start of `buffer`, from its fixed header.
fn frame_length(buffer: &[u8]) -> Framed {
    let mut remaining = 0usize;
    for i in 0..4 {
        let Some(&b) = buffer.get(1 + i) else {
            return Framed::Incomplete;
        };
        remaining += usize::from(b & 0x7F) << (7 * i);
        if b & 0x80 == 0 {
            return Framed::Packet(2 + i + remaining);
        }
    }
    Framed::Malformed
}

fn provider() -> Arc<rustls::crypto::CryptoProvider> {
    static PROVIDER: OnceLock<Arc<rustls::crypto::CryptoProvider>> = OnceLock::new();
    PROVIDER.get_or_init(|| Arc::new(rustls::crypto::ring::default_provider())).clone()
}

/// A TLS acceptor for a PEM certificate chain and key.
fn acceptor(cert: &str, key: &str) -> Result<TlsAcceptor, Error> {
    use rustls::pki_types::pem::PemObject;
    use rustls::pki_types::{CertificateDer, PrivateKeyDer};
    let fail = |e: &dyn std::fmt::Display| Error::Runtime(format!("Could not load the certificate: {e}"));
    let chain = CertificateDer::pem_file_iter(cert)
        .map_err(|e| fail(&e))?
        .collect::<Result<Vec<_>, _>>()
        .map_err(|e| fail(&e))?;
    let key = PrivateKeyDer::from_pem_file(key).map_err(|e| fail(&e))?;
    let config = rustls::ServerConfig::builder_with_provider(provider())
        .with_safe_default_protocol_versions()
        .map_err(|e| fail(&e))?
        .with_no_client_auth()
        .with_single_cert(chain, key)
        .map_err(|e| fail(&e))?;
    Ok(TlsAcceptor::from(Arc::new(config)))
}

impl Outbound for Tokio {
    fn send(&self, connection: i64, message: &[u8]) -> Result<(), Error> {
        if let Some(tx) = self.connections_map().get(&connection) {
            let _ = tx.send(Command::Send(message.to_vec()));
        }
        Ok(())
    }

    fn close(&self, connection: i64) -> Result<(), Error> {
        if let Some(tx) = self.connections_map().get(&connection) {
            let _ = tx.send(Command::Close);
        }
        Ok(())
    }
}

impl Adapter for Tokio {
    fn on_start(&self, hook: StartHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).start = Some(hook);
    }

    fn on_worker_start(&self, hook: WorkerHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).worker_start = Some(hook);
    }

    fn on_open(&self, hook: ConnectionHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).open = Some(hook);
    }

    fn on_receive(&self, hook: ReceiveHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).receive = Some(hook);
    }

    fn on_close(&self, hook: ConnectionHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).close = Some(hook);
    }

    fn timer(&self) -> &dyn Timer {
        &*self.inner.timer
    }

    async fn start(&self) -> Result<(), Error> {
        let mut listeners = Vec::with_capacity(self.inner.transports.len());
        let mut addrs = Vec::with_capacity(self.inner.transports.len());
        for transport in &self.inner.transports {
            let (host, port) = (transport.host(), transport.port());
            let listener = TcpListener::bind((host, port))
                .await
                .map_err(|_| Error::Runtime(format!("Could not listen on {host}:{port}.")))?;
            if let Transport::Tls { cert, key, .. } = transport {
                acceptor(cert, key)?;
            }
            addrs.push(listener.local_addr().map_err(|e| Error::Runtime(e.to_string()))?);
            listeners.push((listener, transport.clone()));
        }
        self.inner.stopped.send_replace(false);
        self.inner.addrs.send_replace(addrs);
        if let Some(start) = self.hook(|h| &h.start) {
            start();
        }
        if let Some(worker) = self.hook(|h| &h.worker_start) {
            for id in 0..self.inner.workers.max(1) {
                worker(id);
            }
        }
        let tasks: Vec<_> = listeners
            .into_iter()
            .map(|(listener, transport)| tokio::spawn(self.clone().accept(listener, transport)))
            .collect();
        let mut stopped = self.inner.stopped.subscribe();
        let _ = stopped.wait_for(|s| *s).await;
        for task in tasks {
            let _ = task.await;
        }
        let open: Vec<i64> = self.connections();
        for id in open {
            let _ = self.close(id);
        }
        Ok(())
    }

    fn shutdown(&self) -> Result<(), Error> {
        self.inner.stopped.send_replace(true);
        Ok(())
    }
}
