//! HTTP proxy server. Mirrors `src/Server/HTTP/` - typed config, request handler
//! with pooled backend clients, and a hyper-based server implementation.

pub mod config;
pub mod handler;
pub mod pool;
pub mod server;

pub use config::{HttpConfig, RequestHandler, WorkerStart};
pub use handler::Handler;
pub use pool::{Pool, PooledClient, Pools};
pub use server::HttpServer;
