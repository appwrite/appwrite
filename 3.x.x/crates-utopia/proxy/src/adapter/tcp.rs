//! TCP adapter - port → protocol, backend connection cache, sockmap hooks.
//! Mirrors `src/Adapter/TCP.php`.

use std::os::fd::RawFd;
use std::sync::Arc;
use std::time::Duration;

use dashmap::DashMap;
use tokio::net::TcpStream;
use tokio::sync::Mutex;

use super::Adapter;
use crate::connection_result::ConnectionResult;
use crate::error::ProxyError;
use crate::protocol::Protocol;
use crate::resolver::{Resolver, ResolverError};
use crate::sockmap::Sockmap;

/// Thin wrapper around a backend `TcpStream` plus its raw kernel fd.
pub struct TcpClient {
    pub stream: Mutex<TcpStream>,
    pub fd: RawFd,
}

impl std::fmt::Debug for TcpClient {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("TcpClient")
            .field("fd", &self.fd)
            .finish_non_exhaustive()
    }
}

/// TCP adapter. Composes the base `Adapter` with TCP-specific state: listen port,
/// per-client backend connection cache, optional BPF sockmap, and TCP socket options.
pub struct TcpAdapter {
    base: Adapter,
    port: u16,
    connections: DashMap<RawFd, Arc<TcpClient>>,
    sockmap_pairs: DashMap<RawFd, RawFd>,
    sockmap: parking_lot::RwLock<Option<Arc<Sockmap>>>,
    timeout: parking_lot::RwLock<Duration>,
    connect_timeout: parking_lot::RwLock<Duration>,
    tcp_user_timeout_ms: parking_lot::RwLock<u32>,
    tcp_quickack: parking_lot::RwLock<bool>,
    tcp_notsent_lowat: parking_lot::RwLock<u32>,
}

impl std::fmt::Debug for TcpAdapter {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("TcpAdapter")
            .field("port", &self.port)
            .finish_non_exhaustive()
    }
}

impl TcpAdapter {
    /// Default matching `TCP::$timeout = 30.0` in PHP.
    pub const DEFAULT_TIMEOUT: Duration = Duration::from_secs(30);
    /// Default matching `TCP::$connectTimeout = 5.0` in PHP.
    pub const DEFAULT_CONNECT_TIMEOUT: Duration = Duration::from_secs(5);

    pub fn new(port: u16, resolver: Option<Arc<dyn Resolver>>) -> Self {
        let base = Adapter::new(resolver, Protocol::from_port(port));
        Self {
            base,
            port,
            connections: DashMap::new(),
            sockmap_pairs: DashMap::new(),
            sockmap: parking_lot::RwLock::new(None),
            timeout: parking_lot::RwLock::new(Self::DEFAULT_TIMEOUT),
            connect_timeout: parking_lot::RwLock::new(Self::DEFAULT_CONNECT_TIMEOUT),
            tcp_user_timeout_ms: parking_lot::RwLock::new(0),
            tcp_quickack: parking_lot::RwLock::new(false),
            tcp_notsent_lowat: parking_lot::RwLock::new(0),
        }
    }

    pub fn base(&self) -> &Adapter {
        &self.base
    }

    pub fn port(&self) -> u16 {
        self.port
    }

    /// Protocol implied by the listen port. Matches `TCP::getProtocol()`.
    pub fn protocol(&self) -> Protocol {
        Protocol::from_port(self.port)
    }

    pub fn set_timeout(&self, timeout: Duration) -> &Self {
        *self.timeout.write() = timeout;
        self
    }

    pub fn timeout(&self) -> Duration {
        *self.timeout.read()
    }

    pub fn set_connect_timeout(&self, timeout: Duration) -> &Self {
        *self.connect_timeout.write() = timeout;
        self
    }

    pub fn connect_timeout(&self) -> Duration {
        *self.connect_timeout.read()
    }

    pub fn set_tcp_user_timeout(&self, milliseconds: u32) -> &Self {
        *self.tcp_user_timeout_ms.write() = milliseconds;
        self
    }

    pub fn tcp_user_timeout_ms(&self) -> u32 {
        *self.tcp_user_timeout_ms.read()
    }

    pub fn set_tcp_quickack(&self, enabled: bool) -> &Self {
        *self.tcp_quickack.write() = enabled;
        self
    }

    pub fn tcp_quickack(&self) -> bool {
        *self.tcp_quickack.read()
    }

    pub fn set_tcp_notsent_lowat(&self, bytes: u32) -> &Self {
        *self.tcp_notsent_lowat.write() = bytes;
        self
    }

    pub fn tcp_notsent_lowat(&self) -> u32 {
        *self.tcp_notsent_lowat.read()
    }

    pub fn set_sockmap(&self, sockmap: Option<Arc<Sockmap>>) -> &Self {
        *self.sockmap.write() = sockmap;
        self
    }

    /// Returns true if the given client fd has been handed to the kernel via sockmap.
    pub fn is_sockmap_active(&self, client_fd: RawFd) -> bool {
        self.sockmap_pairs.contains_key(&client_fd)
    }

    /// Route via the base adapter. Convenience wrapper mirroring PHP `route()` exposure.
    pub async fn route(&self, data: &[u8]) -> Result<ConnectionResult, ResolverError> {
        self.base.route(data).await
    }

    /// Get or create backend connection for a client fd. On first call, routes
    /// via the resolver and dials the backend; subsequent calls return the cached
    /// connection.
    pub async fn get_connection(
        &self,
        data: &[u8],
        fd: RawFd,
    ) -> Result<Arc<TcpClient>, ProxyError> {
        if let Some(existing) = self.connections.get(&fd) {
            return Ok(existing.clone());
        }

        let result = self.base.route(data).await?;
        let (host, port) = Adapter::parse_endpoint(result.endpoint(), self.port);

        let connect_timeout = self.connect_timeout();
        let stream =
            tokio::time::timeout(connect_timeout, TcpStream::connect((host.as_str(), port)))
                .await
                .map_err(|_| {
                    ProxyError::BackendConnect(format!(
                        "Timeout connecting to backend: {host}:{port}"
                    ))
                })?
                .map_err(|e| {
                    ProxyError::BackendConnect(format!(
                        "Failed to connect to backend: {host}:{port}: {e}"
                    ))
                })?;

        stream
            .set_nodelay(true)
            .map_err(|e| ProxyError::BackendConnect(format!("set_nodelay failed: {e}")))?;

        #[cfg(unix)]
        let backend_fd = {
            use std::os::fd::AsRawFd;
            stream.as_raw_fd()
        };
        #[cfg(not(unix))]
        let backend_fd: RawFd = -1;

        self.apply_socket_options(&stream);

        let client = Arc::new(TcpClient {
            stream: Mutex::new(stream),
            fd: backend_fd,
        });
        self.connections.insert(fd, client.clone());
        Ok(client)
    }

    /// Hand the (client, backend) fd pair to the kernel via sockmap. Must be called
    /// after the initial handshake packet has been written.
    pub fn activate_sockmap(&self, client_fd: RawFd) -> bool {
        let sockmap = match self.sockmap.read().clone() {
            Some(s) => s,
            None => return false,
        };
        if !sockmap.is_available() {
            return false;
        }

        let client = match self.connections.get(&client_fd) {
            Some(c) => c.clone(),
            None => return false,
        };

        let backend_fd = client.fd;
        if backend_fd <= 0 {
            return false;
        }

        if !sockmap.insert_pair(client_fd, backend_fd) {
            return false;
        }

        self.sockmap_pairs.insert(client_fd, backend_fd);
        true
    }

    /// Close and remove backend connection for a client fd. Also clears any sockmap pair.
    pub fn close_connection(&self, fd: RawFd) {
        if let Some((_, backend_fd)) = self.sockmap_pairs.remove(&fd) {
            if let Some(sockmap) = self.sockmap.read().clone() {
                sockmap.remove_pair(fd, backend_fd);
            }
        }
        self.connections.remove(&fd);
    }

    fn apply_socket_options(&self, stream: &TcpStream) {
        // Linux TCP_USER_TIMEOUT / TCP_QUICKACK / TCP_NOTSENT_LOWAT used unsafe
        // setsockopt in the PHP rust port; skipped here. Keepalive/nodelay are
        // applied on the listener/stream in `server::tcp::sockopts`.
        let _ = (
            stream,
            self.tcp_user_timeout_ms(),
            self.tcp_quickack(),
            self.tcp_notsent_lowat(),
        );
    }
}
