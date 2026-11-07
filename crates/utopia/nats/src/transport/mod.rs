//! Byte streams to a NATS server: plain TCP, TLS, and WebSockets.
//!
//! The [`Transport`] trait is the extension point: a
//! [`crate::ConnectionOptions::transport_factory`] can hand the connection
//! any implementation (an in-memory double in tests, a proxy, a custom
//! socket).

mod socket;
mod tcp;
mod tls;
mod websocket;

use std::path::PathBuf;
use std::time::Duration;

use async_trait::async_trait;

pub use tcp::TcpTransport;
pub use tls::TlsTransport;
pub use websocket::WebSocketTransport;

use crate::error::Result;

/// A connection-oriented byte stream to one server.
///
/// Every method that waits takes an optional timeout: `None` keeps the
/// timeout the stream last used (the connect timeout at first), as a PHP
/// stream keeps its `stream_set_timeout()`.
///
/// PHP: `Utopia\NATS\Transport\Transport`.
#[async_trait]
pub trait Transport: Send {
    /// Opens the stream to `host:port`, giving up after `timeout`.
    ///
    /// PHP: `Transport::connect()`.
    async fn connect(&mut self, host: &str, port: u16, timeout: Duration) -> Result<()>;

    /// Writes every byte of `data`, returning how many were written.
    ///
    /// PHP: `Transport::write()`.
    async fn write(&mut self, data: &[u8]) -> Result<usize>;

    /// Appends at most `max` bytes to `buf`, waiting for at least one, and
    /// returns how many it appended. `0` means the stream had nothing to
    /// give without failing (PHP's empty string).
    ///
    /// PHP: `Transport::read()`.
    async fn read(&mut self, buf: &mut Vec<u8>, max: usize, timeout: Option<Duration>) -> Result<usize>;

    /// Reads up to and including the next `\n`.
    ///
    /// PHP: `Transport::readLine()`.
    async fn read_line(&mut self, timeout: Option<Duration>) -> Result<Vec<u8>>;

    /// Upgrades a plain stream to TLS in place (after the server's `INFO`).
    ///
    /// PHP: `Transport::upgradeTls()`.
    async fn upgrade_tls(&mut self, options: &TlsOptions) -> Result<()>;

    /// Whether the stream is open.
    ///
    /// PHP: `Transport::isConnected()`.
    fn is_connected(&self) -> bool;

    /// Closes the stream. Closing twice is harmless.
    ///
    /// PHP: `Transport::close()`.
    async fn close(&mut self);
}

/// How a TLS stream verifies the server and presents itself.
///
/// PHP: the `ssl` context options array (`cafile`, `local_cert`,
/// `local_pk`, `verify_peer`, `verify_peer_name`, `peer_name`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TlsOptions {
    /// CA bundle (PEM) to verify the server with; the Mozilla roots when
    /// absent.
    pub ca_file: Option<PathBuf>,
    /// Client certificate chain (PEM), for mutual TLS.
    pub cert_file: Option<PathBuf>,
    /// Client private key (PEM), for mutual TLS.
    pub key_file: Option<PathBuf>,
    /// Whether the server's certificate chain must verify.
    pub verify_peer: bool,
    /// Whether the server's certificate must name the host.
    pub verify_peer_name: bool,
    /// The name to verify (and send as SNI) instead of the host dialled.
    pub peer_name: Option<String>,
}

impl Default for TlsOptions {
    fn default() -> Self {
        TlsOptions {
            ca_file: None,
            cert_file: None,
            key_file: None,
            verify_peer: true,
            verify_peer_name: true,
            peer_name: None,
        }
    }
}
