//! utopia-proxy - Rust port of `utopia-php/proxy`.
//!
//! TCP / HTTP / SMTP proxy with resolver routing, SSRF validation, and Tokio
//! servers (PHP Swoole). BPF sockmap load is a documented no-op (unsafe forbid).

#![forbid(unsafe_code)]
#![allow(clippy::doc_markdown)]

pub mod adapter;
pub mod connection_result;
pub mod dns;
pub mod error;
pub mod protocol;
pub mod resolver;
pub mod server;
pub mod sockmap;
pub mod tls;

pub use adapter::tcp::TcpAdapter;
pub use adapter::Adapter;
pub use connection_result::ConnectionResult;
pub use error::ProxyError;
pub use protocol::Protocol;
pub use resolver::fixed::Fixed;
pub use resolver::{Resolver, ResolverError, ResolverResult};

pub mod prelude {
    pub use crate::adapter::tcp::TcpAdapter;
    pub use crate::adapter::Adapter;
    pub use crate::connection_result::ConnectionResult;
    pub use crate::protocol::Protocol;
    pub use crate::resolver::fixed::Fixed;
    pub use crate::resolver::{Resolver, ResolverError, ResolverResult};
    pub use crate::server::http::{HttpConfig, HttpServer};
    pub use crate::server::smtp::{SmtpConfig, SmtpServer};
    pub use crate::server::tcp::{TcpConfig, TcpServer};
    pub use crate::tls::Tls;
}
