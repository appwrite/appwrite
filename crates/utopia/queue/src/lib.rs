//! A task queue: messages published onto brokers (Redis, Redis Cluster,
//! NATS JetStream), consumed with at-least-once delivery, retries and
//! dead letters, and a worker server running jobs between hooks with
//! bounded concurrency.
//!
//! Messages travel as an envelope (`pid`, `queue`, `timestamp`, `payload`,
//! `attempts` on a requeue) written by a [`Codec`](codec::Codec): JSON
//! byte-for-byte as `json_encode` writes it, or igbinary. Payloads are PHP
//! values ([`php_std::Array`]), so what one runtime publishes the other
//! consumes.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Queue\Queue` | [`Queue`] |
//! | `Utopia\Queue\Message` | [`Message`] |
//! | `Utopia\Queue\Codec`, `Codec\Json`, `Codec\Igbinary`, `Codec\Compat` | [`codec::Codec`], [`codec::Json`], [`codec::Igbinary`], [`codec::Compat`] |
//! | `Utopia\Queue\Publisher\Synchronous`, `Publisher\Asynchronous` | [`Publisher`], [`AsyncPublisher`] |
//! | `Utopia\Queue\Consumer`, `Consumer\Bounded`, `Consumer\Exclusive` | [`Consumer`] ([`Consumer::in_flight_ceiling`], [`Consumer::is_exclusive`]) |
//! | `Utopia\Queue\Connection`, `Connection\Redis`, `Connection\RedisCluster`, `Connection\Locking` | [`connection::Connection`], [`connection::Redis`], [`connection::RedisCluster`], [`connection::Locking`] |
//! | `Utopia\Queue\Broker\*` | [`broker`] |
//! | `Utopia\Queue\Server` | [`Server`] |
//! | `Utopia\Queue\Job`, `Utopia\Servers\Hook` | [`Job`], [`Hook`] |
//! | `Utopia\Queue\Adapter`, `Adapter\Swoole`, `Adapter\KubernetesJob` | [`adapter::Adapter`] with [`adapter::Runtime::Workers`], [`adapter::Runtime::Drain`] |
//! | `Utopia\DI\Container` | [`hook::Resources`] |
//! | `Utopia\Queue\PermanentFailure`, `Publisher\BufferFullException` | [`Error::PermanentFailure`], [`Error::BufferFull`] |
//! | `Utopia\Queue\Internal\Buffer` | internal: settlement and acknowledgement coalescing |
//! | `uniqid()`, `time()`, `random_bytes()` in brokers | [`Clock`] |

#![warn(missing_docs)]

pub mod adapter;
pub mod broker;
mod buffer;
pub mod codec;
pub mod connection;
mod contract;
mod error;
pub mod hook;
mod message;
mod queue;
mod server;
pub mod telemetry;

pub use contract::{AsyncPublisher, Clock, Consumer, Publisher, SystemClock, payload};
pub use error::{Error, Result};
pub use hook::{Hook, Job};
pub use message::Message;
pub use queue::{DEFAULT_NAMESPACE, Queue};
pub use server::{DURATION_BUCKETS, Server};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
