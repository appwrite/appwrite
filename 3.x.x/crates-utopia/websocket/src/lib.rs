//! WebSocket client and server for Utopia.
//!
//! Rust port of [`utopia-php/websocket`](https://github.com/utopia-php/websocket).
//!
//! PHP Swoole and Workerman adapters are Tokio TCP/WebSocket implementations
//! with the same public method names.

mod adapter;
mod client;
mod error;
mod http;
mod protocol;
mod server;

pub use adapter::{Adapter, NativeHandle, Swoole, TokioAdapter, Workerman};
pub use client::Client;
pub use error::WebsocketError;
pub use http::{HttpRequest, HttpResponse};
pub use protocol::{accept_key, decode_frame, encode_frame, OPCODE_TEXT};
pub use server::Server;

/// Prelude for common websocket types.
pub mod prelude {
    pub use crate::{
        Adapter, Client, HttpRequest, HttpResponse, NativeHandle, Server, Swoole, TokioAdapter,
        WebsocketError, Workerman,
    };
}
