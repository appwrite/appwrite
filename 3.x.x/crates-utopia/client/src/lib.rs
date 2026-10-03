//! PSR-18-shaped HTTP client for Utopia.
//!
//! Rust port of [`utopia-php/client`](https://github.com/utopia-php/client).
//!
//! PHP cURL adapter → [`adapter::curl::Client`] (reqwest blocking).
//! PHP Swoole coroutine adapter → [`adapter::swoole_coroutine::Client`] (Tokio + reqwest).

pub mod adapter;
pub mod decorator;
pub mod psr18;
pub mod response;

mod client;
mod error;
mod pool;
mod tls;

pub use adapter::{Adapter, StreamingClient};
pub use client::{Client, HeaderValues, RelativeUri};
pub use decorator::{Backoff, Decorator, Retry, Strategy};
pub use error::{
    AdapterInitializationException, AdapterPreconditionException, ConnectionException,
    DnsException, Error, ErrorKind, InvalidResponseException, InvalidUriException,
    NetworkException, ProtocolException, ProxyException, RequestException, TimeoutException,
    TlsException,
};
pub use pool::Pool;
pub use psr18::StreamingClientInterface;
pub use response::Builder as ResponseBuilder;
pub use tls::Tls;

pub mod exception {
    pub use crate::error::{
        AdapterInitializationException, AdapterPreconditionException, ConnectionException,
        DnsException, Error, InvalidResponseException, InvalidUriException, NetworkException,
        ProtocolException, ProxyException, RequestException, TimeoutException, TlsException,
    };
}

pub mod prelude {
    pub use crate::{
        adapter::{curl, swoole_coroutine, Adapter, StreamingClient},
        Backoff, Client, Decorator, Error, Pool, RelativeUri, Retry, Strategy, Tls,
    };
}
