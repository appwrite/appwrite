use std::fmt;
use std::net::SocketAddr;
use std::sync::Arc;
use std::time::{Duration, Instant};

use parking_lot::Mutex;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream, UdpSocket};
use tokio::sync::watch;

use crate::adapter::{Adapter, PacketHandler, WorkerStartHandler};
use crate::error::{Error, Result};
use crate::message::Message;
use crate::protocol::Protocol;
use crate::proxy_protocol::ProxyProtocol;

/// Native PHP-sockets adapter, implemented with Tokio.
pub struct Native {
    inner: Arc<Inner>,
}

impl fmt::Debug for Native {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Native").finish_non_exhaustive()
    }
}

struct Inner {
    transports: Mutex<Vec<BoundTransport>>,
    on_packet: Mutex<Option<PacketHandler>>,
    on_worker_start: Mutex<Vec<WorkerStartHandler>>,
    shutdown: Mutex<Option<watch::Sender<bool>>>,
}

enum BoundTransport {
    Udp(UdpConfig),
    Tcp(TcpConfig),
}

struct UdpConfig {
    host: String,
    port: u16,
    socket: Mutex<Option<Arc<UdpSocket>>>,
}

struct TcpConfig {
    host: String,
    port: u16,
    max_clients: usize,
    max_buffer_size: usize,
    max_frame_size: usize,
    idle_timeout: Duration,
    proxy_protocol: bool,
    listener: Mutex<Option<Arc<TcpListener>>>,
}

impl Native {
    /// PHP `Native::__construct`.
    pub fn new(transports: Vec<Transport>) -> Result<Self> {
        if transports.is_empty() {
            return Err(Error::other("At least one transport is required."));
        }
        let transports = transports
            .into_iter()
            .map(|t| match t {
                Transport::Udp(u) => BoundTransport::Udp(UdpConfig {
                    host: u.host,
                    port: u.port,
                    socket: Mutex::new(None),
                }),
                Transport::Tcp(t) => BoundTransport::Tcp(TcpConfig {
                    host: t.host,
                    port: t.port,
                    max_clients: t.max_clients,
                    max_buffer_size: t.max_buffer_size,
                    max_frame_size: t.max_frame_size,
                    idle_timeout: Duration::from_secs(t.idle_timeout),
                    proxy_protocol: t.proxy_protocol,
                    listener: Mutex::new(None),
                }),
            })
            .collect();
        Ok(Self {
            inner: Arc::new(Inner {
                transports: Mutex::new(transports),
                on_packet: Mutex::new(None),
                on_worker_start: Mutex::new(Vec::new()),
                shutdown: Mutex::new(None),
            }),
        })
    }

    /// Bound UDP address after `start_async` has bound sockets.
    #[must_use]
    pub fn udp_addr(&self) -> Option<SocketAddr> {
        for t in self.inner.transports.lock().iter() {
            if let BoundTransport::Udp(u) = t {
                if let Some(s) = u.socket.lock().as_ref() {
                    return s.local_addr().ok();
                }
            }
        }
        None
    }

    /// Bound TCP address after `start_async` has bound sockets.
    #[must_use]
    pub fn tcp_addr(&self) -> Option<SocketAddr> {
        for t in self.inner.transports.lock().iter() {
            if let BoundTransport::Tcp(t) = t {
                if let Some(s) = t.listener.lock().as_ref() {
                    return s.local_addr().ok();
                }
            }
        }
        None
    }

    /// Signal a running `start_async` loop to exit.
    pub fn stop(&self) {
        if let Some(tx) = self.inner.shutdown.lock().as_ref() {
            let _ = tx.send(true);
        }
    }

    /// Async start for tests. Call [`Self::stop`] or drop the shutdown watch to exit.
    pub async fn start_async(&self) -> Result<()> {
        let (tx, mut rx) = watch::channel(false);
        *self.inner.shutdown.lock() = Some(tx);

        bind_all(&self.inner).await?;
        for cb in self.inner.on_worker_start.lock().iter() {
            cb(0);
        }

        let packet = self
            .inner
            .on_packet
            .lock()
            .clone()
            .unwrap_or_else(|| Arc::new(|_, _, _, _| Vec::new()));

        let mut tasks = Vec::new();
        {
            let transports = self.inner.transports.lock();
            for t in transports.iter() {
                match t {
                    BoundTransport::Udp(u) => {
                        if let Some(sock) = u.socket.lock().clone() {
                            let packet = Arc::clone(&packet);
                            let mut rx = rx.clone();
                            tasks.push(tokio::spawn(async move {
                                loop {
                                    tokio::select! {
                                        _ = rx.changed() => {
                                            if *rx.borrow() { break; }
                                        }
                                        result = recv_udp(&sock, &packet) => {
                                            if result.is_err() { break; }
                                        }
                                    }
                                }
                            }));
                        }
                    }
                    BoundTransport::Tcp(t) => {
                        if let Some(listener) = t.listener.lock().clone() {
                            let packet = Arc::clone(&packet);
                            let mut rx = rx.clone();
                            let cfg = TcpRuntime {
                                max_clients: t.max_clients,
                                max_buffer_size: t.max_buffer_size,
                                max_frame_size: t.max_frame_size,
                                idle_timeout: t.idle_timeout,
                                proxy_protocol: t.proxy_protocol,
                            };
                            tasks.push(tokio::spawn(async move {
                                tcp_accept_loop(listener, packet, cfg, &mut rx).await;
                            }));
                        }
                    }
                }
            }
        }

        let _ = rx.changed().await;
        for task in tasks {
            task.abort();
        }
        Ok(())
    }
}

impl Clone for Native {
    fn clone(&self) -> Self {
        Self {
            inner: Arc::clone(&self.inner),
        }
    }
}

#[async_trait::async_trait]
impl Adapter for Native {
    fn on_worker_start(&self, callback: WorkerStartHandler) {
        self.inner.on_worker_start.lock().push(callback);
    }

    fn on_packet(&self, callback: PacketHandler) {
        *self.inner.on_packet.lock() = Some(callback);
    }

    fn start(&self) -> Result<()> {
        let rt = tokio::runtime::Builder::new_multi_thread()
            .enable_all()
            .build()
            .map_err(|e| Error::other(e.to_string()))?;
        rt.block_on(self.start_async())
    }

    async fn start_async(&self) -> Result<()> {
        Native::start_async(self).await
    }

    fn stop(&self) {
        Native::stop(self);
    }

    fn udp_addr(&self) -> Option<SocketAddr> {
        Native::udp_addr(self)
    }

    fn tcp_addr(&self) -> Option<SocketAddr> {
        Native::tcp_addr(self)
    }
}

/// PHP `Utopia\DNS\Adapter\Native\Transport` used to construct [`Native`].
#[derive(Debug)]
pub enum Transport {
    Udp(Udp),
    Tcp(Tcp),
}

/// PHP `Utopia\DNS\Adapter\Native\Udp`.
#[derive(Debug, Clone)]
pub struct Udp {
    pub host: String,
    pub port: u16,
}

impl Udp {
    #[must_use]
    pub fn new(host: impl Into<String>, port: u16) -> Self {
        Self {
            host: host.into(),
            port,
        }
    }
}

impl From<Udp> for Transport {
    fn from(value: Udp) -> Self {
        Self::Udp(value)
    }
}

/// PHP `Utopia\DNS\Adapter\Native\Tcp`.
#[derive(Debug, Clone)]
pub struct Tcp {
    pub host: String,
    pub port: u16,
    pub max_clients: usize,
    pub max_buffer_size: usize,
    pub max_frame_size: usize,
    pub idle_timeout: u64,
    pub proxy_protocol: bool,
}

impl Tcp {
    #[must_use]
    pub fn new(host: impl Into<String>, port: u16) -> Self {
        Self {
            host: host.into(),
            port,
            max_clients: 100,
            max_buffer_size: 16_384,
            max_frame_size: Message::MAX_SIZE,
            idle_timeout: 30,
            proxy_protocol: false,
        }
    }

    #[must_use]
    pub fn proxy_protocol(mut self, enabled: bool) -> Self {
        self.proxy_protocol = enabled;
        self
    }

    #[must_use]
    pub fn idle_timeout(mut self, secs: u64) -> Self {
        self.idle_timeout = secs;
        self
    }
}

impl From<Tcp> for Transport {
    fn from(value: Tcp) -> Self {
        Self::Tcp(value)
    }
}

async fn bind_all(inner: &Inner) -> Result<()> {
    let specs: Vec<(bool, String, u16)> = {
        let transports = inner.transports.lock();
        transports
            .iter()
            .map(|t| match t {
                BoundTransport::Udp(u) => (true, u.host.clone(), u.port),
                BoundTransport::Tcp(t) => (false, t.host.clone(), t.port),
            })
            .collect()
    };
    let mut assigned_port: Option<u16> = None;
    let mut udp_socks = Vec::new();
    let mut tcp_listeners = Vec::new();
    for (is_udp, host, port) in specs {
        if is_udp {
            let addr = format!("{host}:{port}");
            let sock = UdpSocket::bind(&addr)
                .await
                .map_err(|e| Error::other(format!("Could not bind UDP socket to {addr}. {e}")))?;
            assigned_port = sock.local_addr().ok().map(|a| a.port()).or(assigned_port);
            udp_socks.push(Some(Arc::new(sock)));
            tcp_listeners.push(None);
        } else {
            let port = if port == 0 {
                assigned_port.unwrap_or(0)
            } else {
                port
            };
            let addr = format!("{host}:{port}");
            let listener = TcpListener::bind(&addr)
                .await
                .map_err(|e| Error::other(format!("Could not bind TCP socket to {addr}. {e}")))?;
            udp_socks.push(None);
            tcp_listeners.push(Some(Arc::new(listener)));
        }
    }
    let mut transports = inner.transports.lock();
    for (i, t) in transports.iter_mut().enumerate() {
        match t {
            BoundTransport::Udp(u) => u.socket.lock().clone_from(&udp_socks[i]),
            BoundTransport::Tcp(t) => t.listener.lock().clone_from(&tcp_listeners[i]),
        }
    }
    Ok(())
}

async fn recv_udp(sock: &UdpSocket, packet: &PacketHandler) -> Result<()> {
    let mut buf = [0u8; 4096];
    let (n, peer) = sock
        .recv_from(&mut buf)
        .await
        .map_err(|e| Error::other(e.to_string()))?;
    if n > 0 {
        let answer = packet(
            &buf[..n],
            &peer.ip().to_string(),
            peer.port(),
            Protocol::Udp,
        );
        if !answer.is_empty() {
            let _ = sock.send_to(&answer, peer).await;
        }
    }
    Ok(())
}

struct TcpRuntime {
    max_clients: usize,
    max_buffer_size: usize,
    max_frame_size: usize,
    idle_timeout: Duration,
    proxy_protocol: bool,
}

async fn tcp_accept_loop(
    listener: Arc<TcpListener>,
    packet: PacketHandler,
    cfg: TcpRuntime,
    rx: &mut watch::Receiver<bool>,
) {
    let clients = Arc::new(Mutex::new(0usize));
    loop {
        tokio::select! {
            _ = rx.changed() => {
                if *rx.borrow() { break; }
            }
            accept = listener.accept() => {
                let Ok((stream, peer)) = accept else { continue; };
                {
                    let mut n = clients.lock();
                    if *n >= cfg.max_clients {
                        continue;
                    }
                    *n += 1;
                }
                let packet = Arc::clone(&packet);
                let clients = Arc::clone(&clients);
                let cfg_idle = cfg.idle_timeout;
                let cfg_buf = cfg.max_buffer_size;
                let cfg_frame = cfg.max_frame_size;
                let proxy = cfg.proxy_protocol;
                tokio::spawn(async move {
                    handle_tcp_client(stream, peer, packet, cfg_idle, cfg_buf, cfg_frame, proxy).await;
                    *clients.lock() -= 1;
                });
            }
        }
    }
}

#[allow(clippy::too_many_arguments)]
async fn handle_tcp_client(
    mut stream: TcpStream,
    peer: SocketAddr,
    packet: PacketHandler,
    idle_timeout: Duration,
    max_buffer: usize,
    max_frame: usize,
    proxy_protocol: bool,
) {
    let _ = stream.set_nodelay(true);
    let mut buffer = Vec::new();
    let mut awaiting_proxy = proxy_protocol;
    let mut reported_peer = (peer.ip().to_string(), peer.port());
    let mut last_activity = Instant::now();
    let mut tmp = [0u8; 8192];

    loop {
        if last_activity.elapsed() > idle_timeout {
            break;
        }
        let remaining = idle_timeout.saturating_sub(last_activity.elapsed());
        let n = tokio::select! {
            r = stream.read(&mut tmp) => {
                match r {
                    Ok(0) | Err(_) => break,
                    Ok(n) => n,
                }
            }
            () = tokio::time::sleep(remaining) => break,
        };
        last_activity = Instant::now();
        if buffer.len() + n > max_buffer {
            break;
        }
        buffer.extend_from_slice(&tmp[..n]);

        if awaiting_proxy {
            match ProxyProtocol::parse(&buffer) {
                Ok(None) => continue,
                Err(_) => break,
                Ok(Some(header)) => {
                    buffer.drain(..header.length);
                    awaiting_proxy = false;
                    if let (Some(ip), Some(port)) = (header.ip, header.port) {
                        reported_peer = (ip, port);
                    }
                }
            }
        }

        while buffer.len() >= 2 {
            let payload_len = u16::from_be_bytes([buffer[0], buffer[1]]) as usize;
            if payload_len == 0 || payload_len > max_frame {
                return;
            }
            if buffer.len() < payload_len + 2 {
                break;
            }
            let message = buffer[2..2 + payload_len].to_vec();
            buffer.drain(..payload_len + 2);
            let answer = packet(&message, &reported_peer.0, reported_peer.1, Protocol::Tcp);
            if !answer.is_empty() {
                if answer.len() > Message::MAX_SIZE {
                    return;
                }
                let mut frame = Vec::with_capacity(2 + answer.len());
                frame.extend_from_slice(&u16::try_from(answer.len()).unwrap_or(0).to_be_bytes());
                frame.extend_from_slice(&answer);
                if stream.write_all(&frame).await.is_err() {
                    return;
                }
            }
        }
    }
}
