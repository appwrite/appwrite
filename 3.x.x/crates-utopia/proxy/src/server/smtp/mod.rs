//! SMTP proxy server. Port of `src/Server/SMTP/Swoole.php` to tokio.
//!
//! - [`SmtpConfig`] - typed server configuration, mirrors `src/Server/SMTP/Config.php`.
//! - [`Connection`] - per-client connection state + backend socket, mirrors `src/Server/SMTP/Connection.php`.
//! - [`SmtpServer`] - accept loop plus SMTP command/data state machine.

pub mod config;
pub mod connection;
pub mod server;

pub use config::SmtpConfig;
pub use connection::{Connection, SmtpState};
pub use server::SmtpServer;
