//! Async TCP proxy server. Port of `src/Server/TCP/Swoole.php`.
//!
//! Binds each port in [`TcpConfig::ports`] (with SO_REUSEPORT on Linux where
//! supported), accepts connections, routes the first packet via the adapter,
//! forwards initial bytes to the backend, optionally activates a BPF sockmap
//! for zero-copy relay, and otherwise runs a bidirectional copy loop between
//! client and backend.

use std::collections::HashMap;
use std::io;
use std::net::SocketAddr;
use std::sync::Arc;

use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use tokio_rustls::TlsAcceptor;
use tracing::{debug, error, info, warn};

use crate::adapter::tcp::{TcpAdapter, TcpClient};
use crate::error::ProxyError;
use crate::resolver::Resolver;
use crate::sockmap::Sockmap;
use crate::tls::{Tls, TlsContext};
use utopia_console::Console;

use super::config::TcpConfig;
use super::sockopts;

/// Factory used by [`TcpConfig::adapter_factory`] to produce per-port adapters.
pub type AdapterFactory = Box<dyn Fn(u16, Arc<dyn Resolver>) -> TcpAdapter + Send + Sync + 'static>;

/// Async TCP proxy server.
pub struct TcpServer {
    resolver: Arc<dyn Resolver>,
    config: TcpConfig,
}

impl std::fmt::Debug for TcpServer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("TcpServer")
            .field("config", &self.config)
            .finish_non_exhaustive()
    }
}

impl TcpServer {
    pub fn new(resolver: Arc<dyn Resolver>, config: TcpConfig) -> Self {
        Self { resolver, config }
    }

    /// Start the server. Binds every port in `config.ports` and runs accept
    /// loops until a fatal bind error or the runtime is shut down.
    pub async fn start(self) -> io::Result<()> {
        if self.config.ports.is_empty() {
            return Err(io::Error::new(
                io::ErrorKind::InvalidInput,
                "TcpConfig::ports must not be empty",
            ));
        }

        if let Some(tls) = self.config.tls.as_deref() {
            tls.validate()
                .map_err(|e| io::Error::new(io::ErrorKind::InvalidInput, format!("TLS: {e}")))?;
        }

        let tls_acceptor =
            match self.config.tls.as_deref() {
                Some(tls) => Some(build_tls_acceptor(tls).map_err(|e| {
                    io::Error::new(io::ErrorKind::InvalidInput, format!("TLS: {e}"))
                })?),
                None => None,
            };

        let sockmap = if self.config.sockmap_enabled
            && !self.config.sockmap_bpf_object.as_os_str().is_empty()
        {
            let loader = Arc::new(Sockmap::new(&self.config.sockmap_bpf_object));
            if loader.load() {
                info!("Sockmap: enabled (kernel zero-copy relay)");
                Some(loader)
            } else {
                warn!("Sockmap: unavailable ({})", loader.last_error());
                None
            }
        } else {
            None
        };

        // Build one adapter per listening port, matching `onWorkerStart`.
        let mut adapters: HashMap<u16, Arc<TcpAdapter>> = HashMap::new();
        for &port in &self.config.ports {
            let adapter = match &self.config.adapter_factory {
                Some(factory) => factory(port, self.resolver.clone()),
                None => TcpAdapter::new(port, Some(self.resolver.clone())),
            };

            adapter.set_timeout(self.config.timeout);
            adapter.set_connect_timeout(self.config.connect_timeout);
            adapter.set_tcp_user_timeout(self.config.tcp_user_timeout_ms);
            adapter.set_tcp_quickack(self.config.tcp_quickack);
            adapter.set_tcp_notsent_lowat(self.config.tcp_notsent_lowat);
            adapter.set_sockmap(sockmap.clone());

            if self.config.skip_validation {
                adapter.base().set_skip_validation(true).await;
            }
            if self.config.cache_ttl > 0 {
                adapter.base().set_cache_ttl(self.config.cache_ttl).await;
            }

            adapters.insert(port, Arc::new(adapter));
        }

        let shared = Arc::new(Shared {
            config: self.config,
            adapters,
            tls_acceptor,
        });

        let mut handles = Vec::new();
        for &port in &shared.config.ports {
            let listener = bind_port(&shared.config, port)?;
            let shared = shared.clone();
            handles.push(tokio::spawn(async move {
                accept_loop(listener, shared, port).await;
            }));
        }

        let _ = Console::success(&format!(
            "TCP Proxy Server started at {}",
            shared.config.host
        ));
        let ports = shared
            .config
            .ports
            .iter()
            .map(ToString::to_string)
            .collect::<Vec<_>>()
            .join(", ");
        let _ = Console::log(&format!("Ports: {ports}"));
        let _ = Console::log(&format!("Workers: {}", shared.config.workers));
        let _ = Console::log(&format!(
            "Max connections: {}",
            shared.config.max_connections
        ));
        info!(
            host = %shared.config.host,
            ports = ?shared.config.ports,
            "TCP proxy listening"
        );

        for handle in handles {
            let _ = handle.await;
        }
        Ok(())
    }
}

struct Shared {
    config: TcpConfig,
    adapters: HashMap<u16, Arc<TcpAdapter>>,
    tls_acceptor: Option<TlsAcceptor>,
}

fn bind_port(config: &TcpConfig, port: u16) -> io::Result<TcpListener> {
    let addr: SocketAddr = format!("{}:{}", config.host, port)
        .parse()
        .map_err(|e| io::Error::new(io::ErrorKind::InvalidInput, format!("{e}")))?;

    // Use socket2 so SO_REUSEPORT can be set before bind, matching PHP's
    // enable_reuse_port on Swoole BASE mode.
    let domain = if addr.is_ipv4() {
        socket2::Domain::IPV4
    } else {
        socket2::Domain::IPV6
    };
    let socket = socket2::Socket::new(domain, socket2::Type::STREAM, Some(socket2::Protocol::TCP))?;
    socket.set_nonblocking(true)?;
    socket.set_reuse_address(true)?;

    #[cfg(all(target_os = "linux", not(target_os = "solaris")))]
    if config.enable_reuse_port {
        let _ = socket.set_reuse_port(true);
    }

    socket.bind(&addr.into())?;
    socket.listen(config.backlog.max(1))?;

    let std_listener: std::net::TcpListener = socket.into();
    let listener = TcpListener::from_std(std_listener)?;
    sockopts::apply_listener(&listener, config)?;
    Ok(listener)
}

async fn accept_loop(listener: TcpListener, shared: Arc<Shared>, port: u16) {
    loop {
        match listener.accept().await {
            Ok((stream, peer)) => {
                if let Err(e) = sockopts::apply_stream(&stream, &shared.config) {
                    debug!(?e, "failed to apply stream sockopts");
                }
                let shared = shared.clone();
                tokio::spawn(async move {
                    if let Err(e) = handle_connection(stream, peer, port, shared).await {
                        debug!(%peer, ?e, "connection ended with error");
                    }
                });
            }
            Err(e) => {
                error!(?e, port, "accept failed");
                tokio::time::sleep(std::time::Duration::from_millis(50)).await;
            }
        }
    }
}

async fn handle_connection(
    stream: TcpStream,
    peer: SocketAddr,
    port: u16,
    shared: Arc<Shared>,
) -> Result<(), ProxyError> {
    let client_fd = stream_fd(&stream);
    let adapter = shared
        .adapters
        .get(&port)
        .cloned()
        .ok_or_else(|| ProxyError::Configuration(format!("no adapter for port {port}")))?;

    if shared.config.log_connections {
        info!(%peer, port, "client connected");
    }

    let result = run_client(stream, port, adapter.clone(), &shared, client_fd).await;

    adapter.close_connection(client_fd);

    if shared.config.log_connections {
        info!(%peer, port, "client disconnected");
    }

    result
}

/// Drive a single client: read first packet, optionally handle PG SSLRequest,
/// dial backend, forward initial bytes, optionally activate sockmap, then
/// bidirectionally copy until EOF on either side.
async fn run_client(
    stream: TcpStream,
    port: u16,
    adapter: Arc<TcpAdapter>,
    shared: &Arc<Shared>,
    client_fd: std::os::fd::RawFd,
) -> Result<(), ProxyError> {
    let buffer_size = shared.config.receive_buffer_size;
    let mut buf = vec![0u8; buffer_size];

    let n = read_some(&stream, &mut buf).await?;
    if n == 0 {
        return Ok(());
    }
    let initial = &buf[..n];

    // PostgreSQL STARTTLS: respond 'S' and upgrade to TLS before handing off.
    if port == 5432 && shared.tls_acceptor.is_some() && Tls::is_postgresql_ssl_request(initial) {
        // Respond 'S' then TLS-accept on the same stream.
        let mut stream = stream;
        stream
            .write_all(&[crate::tls::PG_SSL_RESPONSE_OK])
            .await
            .map_err(ProxyError::Io)?;
        stream.flush().await.map_err(ProxyError::Io)?;

        let acceptor = shared.tls_acceptor.clone().unwrap();
        let tls_stream = acceptor
            .accept(stream)
            .await
            .map_err(|e| ProxyError::Tls(format!("PostgreSQL STARTTLS accept failed: {e}")))?;

        // Post-TLS: read real startup packet and proceed with backend dial.
        let mut tls_stream = tls_stream;
        let mut post = vec![0u8; buffer_size];
        let m = tls_stream.read(&mut post).await.map_err(ProxyError::Io)?;
        if m == 0 {
            return Ok(());
        }
        let backend = adapter.get_connection(&post[..m], client_fd).await?;
        send_to_backend(&backend, &post[..m]).await?;
        // Sockmap is skipped for TLS-terminated paths (kernel sees ciphertext).
        bidirectional_copy(tls_stream, backend, buffer_size).await;
        return Ok(());
    }

    // Dial backend with the raw initial bytes as the resolver payload.
    let backend = adapter.get_connection(initial, client_fd).await?;
    send_to_backend(&backend, initial).await?;

    // Try kernel zero-copy relay. If it took, we're done - the kernel will
    // handle future bytes and the close hook removes the pair.
    if adapter.activate_sockmap(client_fd) {
        debug!(port, "sockmap activated; skipping userspace forward");
        // We still need to hold the sockets open until one side closes.
        // A simple way: wait until the client socket is closed by peer.
        wait_for_close(stream).await;
        return Ok(());
    }

    bidirectional_copy(stream, backend, buffer_size).await;
    Ok(())
}

async fn send_to_backend(backend: &Arc<TcpClient>, data: &[u8]) -> Result<(), ProxyError> {
    let mut guard = backend.stream.lock().await;
    guard.write_all(data).await.map_err(ProxyError::Io)?;
    guard.flush().await.map_err(ProxyError::Io)?;
    Ok(())
}

async fn read_some(stream: &TcpStream, buf: &mut [u8]) -> Result<usize, ProxyError> {
    stream.readable().await.map_err(ProxyError::Io)?;
    loop {
        match stream.try_read(buf) {
            Ok(n) => return Ok(n),
            Err(ref e) if e.kind() == io::ErrorKind::WouldBlock => {
                stream.readable().await.map_err(ProxyError::Io)?;
            }
            Err(e) => return Err(ProxyError::Io(e)),
        }
    }
}

/// Copy bytes both directions between client and backend using tokio's
/// built-in `copy_bidirectional`. That variant uses a single small internal
/// buffer per direction rather than the per-task `vec![0u8; buf_size]` pair
/// we previously allocated - crucial for high-fan-in idle workloads.
async fn bidirectional_copy<S>(mut client: S, backend: Arc<TcpClient>, buf_size: usize)
where
    S: tokio::io::AsyncRead + tokio::io::AsyncWrite + Unpin + Send,
{
    // Take ownership of the backend stream for exclusive read+write. After
    // this point there's no other referent trying to read the backend stream
    // (no sockmap, no pool).
    let mut backend_stream = match Arc::try_unwrap(backend) {
        Ok(owned) => owned.stream.into_inner(),
        Err(shared_client) => {
            // Fall back to serialised forwarding under the mutex - rare: only
            // happens if the adapter kept another reference around.
            forward_serialised(client, shared_client, buf_size).await;
            return;
        }
    };

    let _ = tokio::io::copy_bidirectional(&mut client, &mut backend_stream).await;
}

/// Fallback copy loop when the backend Arc is still shared. Used only when
/// the adapter has kept an internal reference (shouldn't normally happen once
/// we dropped the cache slot - but we keep a correct fallback).
async fn forward_serialised<S>(mut client: S, backend: Arc<TcpClient>, buf_size: usize)
where
    S: tokio::io::AsyncRead + tokio::io::AsyncWrite + Unpin + Send,
{
    let mut buf = vec![0u8; buf_size];
    loop {
        tokio::select! {
            r = client.read(&mut buf) => {
                match r {
                    Ok(0) | Err(_) => break,
                    Ok(n) => {
                        let mut guard = backend.stream.lock().await;
                        if guard.write_all(&buf[..n]).await.is_err() {
                            break;
                        }
                    }
                }
            }
            r = async {
                let mut guard = backend.stream.lock().await;
                let mut local = vec![0u8; buf_size];
                let n = guard.read(&mut local).await;
                (n, local)
            } => {
                let (n, local) = r;
                match n {
                    Ok(0) | Err(_) => break,
                    Ok(n) => {
                        if client.write_all(&local[..n]).await.is_err() {
                            break;
                        }
                    }
                }
            }
        }
    }
}

async fn wait_for_close(stream: TcpStream) {
    let mut stream = stream;
    let mut buf = [0u8; 1];
    // read() returning Ok(0) means the peer closed; any error also ends.
    let _ = stream.read(&mut buf).await;
}

#[cfg(unix)]
fn stream_fd(stream: &TcpStream) -> std::os::fd::RawFd {
    use std::os::fd::AsRawFd;
    stream.as_raw_fd()
}

#[cfg(not(unix))]
fn stream_fd(_stream: &TcpStream) -> std::os::fd::RawFd {
    -1
}

fn build_tls_acceptor(tls: &Tls) -> Result<TlsAcceptor, String> {
    let context = TlsContext::new(tls.clone());
    let config = context.rustls_server_config()?;
    Ok(TlsAcceptor::from(config))
}
