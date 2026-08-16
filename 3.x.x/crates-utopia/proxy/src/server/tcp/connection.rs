//! Per-connection state. Mirrors `src/Server/TCP/Connection.php`.

use std::sync::Arc;

use crate::adapter::tcp::TcpClient;

/// Per-client connection state, keyed by client file descriptor in the server.
#[derive(Debug)]
pub struct Connection {
    pub backend: Option<Arc<TcpClient>>,
    pub port: u16,
    pub pending_tls: bool,
    pub inbound: u64,
    pub outbound: u64,
}

impl Connection {
    pub fn new(port: u16) -> Self {
        Self {
            backend: None,
            port,
            pending_tls: false,
            inbound: 0,
            outbound: 0,
        }
    }

    /// Clear all state. Matches `Connection::reset()` in the PHP class.
    pub fn reset(&mut self) {
        self.backend = None;
        self.port = 0;
        self.pending_tls = false;
        self.inbound = 0;
        self.outbound = 0;
    }
}

impl Default for Connection {
    fn default() -> Self {
        Self::new(0)
    }
}
