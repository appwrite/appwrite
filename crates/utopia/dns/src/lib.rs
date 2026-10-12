//! DNS servers and clients: a message encoder and decoder, zones and zone
//! files, resolvers, a Tokio server adapter over UDP, TCP (with the PROXY
//! protocol) and DNS over HTTPS, a client, a recursive address lookup, and
//! validators for record names, CAA values and served records.
//!
//! Names and record data are bytes: DNS does not require them to be UTF-8,
//! and a server echoes a question exactly as it arrived.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\DNS\Message` | [`message::Message`]; `Message::response()` is [`message::Message::response`] with a [`message::ResponseBuilder`] |
//! | `Message::query()`, `decode()`, `encode()`, `validate()` | [`message::Message::query`], [`message::Message::decode`], [`message::Message::encode`], [`message::Message::validate`] |
//! | `Message::MAX_SIZE`, `MAX_UDP_SIZE`, `RCODE_*` | [`message::MAX_SIZE`], [`message::MAX_UDP_SIZE`], [`message::RCODE_NOERROR`] and the other `RCODE_*` |
//! | `Message\Header` | [`message::Header`] (public fields; [`message::Header::validate`] is the constructor's check) |
//! | `Message\Question` | [`message::Question`] |
//! | `Message\Record` (`TYPE_*`, `CLASS_*`) | [`message::Record`] (`Record::TYPE_*`, `Record::CLASS_*`); named arguments are the `with_*` builders |
//! | `Record::decode()`, `encode()`, `validateRdata()`, `withName()` | [`message::Record::decode`], [`message::Record::encode`], [`message::Record::validate`], [`message::Record::with_name`] |
//! | `Record::typeNameToCode()`, `typeCodeToName()` | [`message::Record::type_name_to_code`], [`message::Record::type_code_to_name`] |
//! | `Message\Domain` | [`message::domain`] ([`message::domain::encode`], [`message::domain::decode`]) |
//! | `Utopia\DNS\Zone` | [`Zone`] |
//! | `Zone\Resolver::lookup()` | [`zone::lookup`], [`zone::lookup_with`] (a seeded shuffle) |
//! | `Zone\File::import()`, `export()` | [`zone::import`], [`zone::export`] |
//! | `Utopia\DNS\Resolver` | [`resolver::Resolver`] |
//! | `Resolver\Memory` | [`resolver::Memory`] |
//! | `Resolver\Proxy`, `Cloudflare`, `Google` | [`resolver::Proxy`], [`resolver::Proxy::cloudflare`], [`resolver::Proxy::google`] |
//! | `Utopia\DNS\Query`, `Protocol` | [`Query`], [`Protocol`] |
//! | `Utopia\DNS\Server` | [`Server`]; its `onPacket()` is [`Handler::handle`] |
//! | `Server::error()`, `setDebug()`, `setTelemetry()`, `start()` | [`Server::error`], [`Server::set_debug`], [`Server::set_telemetry`], [`Server::start`] |
//! | `Server::onWorkerStart()` | [`adapter::Adapter::on_worker_start`] on the adapter given to [`Server::start`] |
//! | `Utopia\DNS\Adapter` | [`adapter::Adapter`] |
//! | `Adapter\Native`, `Adapter\Swoole` and their `Udp`, `Tcp`, `Http` transports | [`adapter::Tokio`] with [`adapter::Udp`], [`adapter::Tcp`], [`adapter::Http`] |
//! | `Utopia\DNS\Client` | [`Client`] (async) |
//! | `Utopia\DNS\ProxyProtocol` | [`ProxyProtocol`] |
//! | `Utopia\DNS\Lookup`, `Lookup\Recursive` | [`lookup::Lookup`], [`lookup::Recursive`] |
//! | `Validator\Name`, `Validator\CAA` | [`validator::Name`], [`validator::Caa`] |
//! | `Validator\DNS` | [`validator::Dns`] (async, reporting a [`validator::DnsCheck`]) |
//! | `InvalidArgumentException`, `DecodingException`, `PartialDecodingException`, `ImportException`, `Exception`, `ValueError` | [`Error`], one variant each |
//!
//! PHP: the Native adapter is a blocking select loop and the Swoole one
//! runs Swoole workers; Rust has one adapter on Tokio, whose TCP transport
//! follows `Native\Tcp`'s limits. PHP's validators keep their state on the
//! object (`getDescription()` after `isValid()`); Rust returns it.

#![warn(missing_docs)]

pub mod adapter;
mod client;
mod error;
pub mod lookup;
pub mod message;
mod proxy_protocol;
mod query;
pub mod resolver;
mod server;
pub mod validator;
pub mod zone;

pub use client::Client;
pub use error::Error;
pub use proxy_protocol::{ProxyProtocol, SIGNATURE_V2};
pub use query::{Protocol, Query};
pub use server::{ErrorHandler, Handler, Server};
pub use zone::Zone;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
