//! Utopia HTTP for Rust.
//!
//! A deliberately small layer on top of `hyper`:
//!
//! * [`Router`]: segment trie with Utopia matching rules (static segments win,
//!   empty segments are ignored, path params are raw segments).
//! * [`Request`]: lazily parsed parameters with PHP semantics (JSON body or
//!   bracket-style query/form strings), cookies and header helpers.
//! * [`Response`]: append/replace header semantics, JSON helpers and
//!   negotiated compression (zstd, br, gzip, deflate).
//! * [`serve`]: an HTTP/1.1 server loop with keep-alive and graceful shutdown.
//!
//! Application concerns (auth, hooks, models) live in the application crates;
//! this crate has no knowledge of Appwrite.

mod compression;
mod params;
mod request;
mod response;
mod router;
mod server;

pub use compression::Compression;
pub use params::{Params, parse_query};
pub use request::Request;
pub use response::Response;
pub use router::{Match, PathParams, Router};
pub use server::{Handler, ServerOptions, serve};

pub use bytes::Bytes;
pub use http::{HeaderMap, HeaderName, HeaderValue, Method, StatusCode, header};
