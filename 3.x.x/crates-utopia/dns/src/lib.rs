//! DNS message codec, zone files, resolvers, and server/client for Utopia.
//!
//! Rust port of [`utopia-php/dns`](https://github.com/utopia-php/dns)
//! (PHP SHA `c3ae00025014`).
//!
//! Layout matches PHP `Utopia\DNS\`: crate-root types (`Adapter`, `Client`,
//! `Message`, …) plus nested [`adapter`], [`exception`], [`message`],
//! [`resolver`], [`validator`], and [`zone`] modules.
//!
//! Public constructors match PHP argument lists, so some APIs trip
//! `too_many_arguments` / `too_many_lines`. Zone-file parsing is a single
//! PHP method ported as one function.

#![allow(clippy::upper_case_acronyms)]
#![allow(clippy::too_many_arguments)]
#![allow(clippy::too_many_lines)]
#![allow(clippy::fn_params_excessive_bools)]

pub mod adapter;
pub mod client;
pub mod error;
pub mod exception;
pub mod message;
pub mod protocol;
pub mod proxy_protocol;
pub mod query;
pub mod resolver;
pub mod server;
pub mod validator;
pub mod zone;

mod wire;

pub use adapter::Adapter;
pub use client::Client;
pub use error::{Error, Result};
pub use message::Message;
pub use protocol::Protocol;
pub use proxy_protocol::ProxyProtocol;
pub use query::Query;
pub use resolver::Resolver;
pub use server::Server;
pub use zone::Zone;

pub mod prelude {
    pub use crate::adapter::{native, swoole, Adapter};
    pub use crate::message::{Domain, Header, Message, Question, Record};
    pub use crate::resolver::{Cloudflare, Google, Memory, Proxy, Resolver};
    pub use crate::validator::{Name, CAA, DNS};
    pub use crate::zone::{File, Zone};
    pub use crate::{Client, Error, Protocol, ProxyProtocol, Query, Server};
}
