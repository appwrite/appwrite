//! Caching with expiring entries.
//!
//! A [`Cache`] stores values under keys through an [`Adapter`]: in process
//! ([`Memory`]), on disk ([`Filesystem`]), in Redis ([`Redis`],
//! [`Multiplexing`], [`RedisCluster`]) or Memcached ([`Memcached`],
//! [`Hazelcast`]). Adapters compose: [`Sharding`] spreads keys over several,
//! [`Pool`] borrows one per operation, [`CircuitBreaker`] falls back while a
//! backend fails. Every load takes a TTL: an entry older than it is a miss.
//!
//! Values are PHP values ([`php_std::types::Value`]): the Redis-protocol and
//! Hazelcast adapters store them in an [`Envelope`] encoded with a [`Codec`]
//! ([`Json`] by default, or [`Igbinary`]), byte for byte what the PHP
//! library writes, so both runtimes read each other's entries. Leases
//! ([`lease`]) keep a stale reader from re-caching data a concurrent writer
//! just purged.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Cache\Cache` | [`Cache`] (`getSize` is [`Cache::size`], `getGeneration` [`Cache::generation`]) |
//! | `Utopia\Cache\Adapter` | [`Adapter`], [`DynAdapter`] |
//! | `Feature\Batchable`, `Feature\Leasable`, `Feature\Telemetry` | [`Adapter::batchable`], [`Adapter::leasable`], [`Adapter::set_telemetry`] and their methods |
//! | `Feature\Retryable` | [`Retry`] (`with_max_retries`, `with_retry_delay` on each adapter) |
//! | `Adapter\Memory`, `Adapter\None` | [`Memory`], [`None`] |
//! | `Adapter\Filesystem` | [`Filesystem`] (streaming loads: [`Filesystem::open`]) |
//! | `Adapter\Redis`, `Adapter\Redis\Leasable` | [`Redis`], [`lease`] |
//! | `Adapter\Redis\Multiplexing`, `Redis\ConnectionContext` | [`Multiplexing`], [`MultiplexingOptions`] |
//! | `Adapter\Redis\Client` (`encode`, `parse`, `unwrap`), `RedisError` | [`resp::encode`], [`resp::parse`], [`resp::unwrap`], [`resp::Reply::Error`] |
//! | `Adapter\Redis\NoScript` | [`resp::is_noscript`], [`Error::NoScript`] |
//! | `ConnectionException`, `TimeoutException`, `IdleConnectionException`, `ConnectionError` | [`Error::Connection`], [`Error::Timeout`], [`Error::Idle`] |
//! | `Adapter\RedisCluster` | [`RedisCluster`] (feature `redis-cluster`) |
//! | `Adapter\Memcached`, `Adapter\Hazelcast` | [`Memcached`], [`Hazelcast`] (feature `memcached`) |
//! | `Adapter\Sharding` | [`Sharding`] |
//! | `Adapter\Pool` (over `Utopia\Pools\Pool`) | [`Pool`] over a [`Lender`] ([`Stack`]) |
//! | `Adapter\CircuitBreaker` (over `Utopia\CircuitBreaker\CircuitBreaker`) | [`CircuitBreaker`] over a [`Breaker`] ([`Circuit`]) |
//! | `Codec`, `Codec\Json`, `Codec\Igbinary` | [`Codec`], [`Json`], [`Igbinary`] |
//! | `Envelope` | [`Envelope`] |
//! | `Utopia\Telemetry\Adapter` (`None`, `Test`) | [`telemetry::Telemetry`] ([`telemetry::NoTelemetry`], [`telemetry::MemoryTelemetry`]) |
//!
//! PHP's `load` returns `false` on a miss; Rust returns `None`. `save`
//! returns the data or `false`; Rust returns whether it stored.

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

mod adapter;
mod breaker;
mod cache;
mod codec;
mod envelope;
mod error;
#[cfg(feature = "memcached")]
mod fastlz;
#[cfg(feature = "filesystem")]
mod filesystem;
pub mod lease;
#[cfg(feature = "memcached")]
mod memcached;
mod memory;
#[cfg(feature = "redis")]
mod multiplexing;
mod none;
mod pool;
#[cfg(feature = "redis")]
mod redis_adapter;
#[cfg(feature = "redis-cluster")]
mod redis_cluster;
pub mod resp;
mod sharding;
pub mod telemetry;

pub use adapter::{Adapter, BoxFuture, DynAdapter, Fields, Retry};
pub use breaker::{Admitted, Breaker, Circuit, CircuitBreaker, CircuitOptions, State};
pub use cache::Cache;
pub use codec::{AnyCodec, Codec, Igbinary, Json};
pub use envelope::Envelope;
pub use error::{Error, Result};
#[cfg(feature = "filesystem")]
pub use filesystem::Filesystem;
#[cfg(feature = "memcached")]
pub use memcached::{Hazelcast, Memcached, MemcachedClient};
pub use memory::Memory;
#[cfg(feature = "redis")]
pub use multiplexing::{Multiplexing, MultiplexingOptions};
pub use none::None;
pub use pool::{Lender, Lent, Pool, Stack};
#[cfg(feature = "redis")]
pub use redis_adapter::Redis;
#[cfg(feature = "redis-cluster")]
pub use redis_cluster::RedisCluster;
pub use sharding::Sharding;

/// The redis-rs crate the Redis adapters are built on.
#[cfg(feature = "redis")]
pub use ::redis;
