//! TCP proxy server. Port of `src/Server/TCP/Swoole.php` to tokio.
//!
//! - [`TcpConfig`] - typed server configuration, mirrors `src/Server/TCP/Config.php`.
//! - [`Connection`] - per-client connection state, mirrors `src/Server/TCP/Connection.php`.
//! - [`TcpServer`] - multi-port listener with optional TLS termination and BPF sockmap.

pub mod config;
pub mod connection;
pub mod server;
pub mod sockopts;

pub use config::TcpConfig;
pub use connection::Connection;
pub use server::TcpServer;
