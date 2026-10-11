//! Caching with expiring entries.
//!
//! A [`Cache`] stores values under keys through an [`Adapter`]: in process
//! ([`Memory`]), on disk ([`Filesystem`]), in Redis ([`Redis`],
//! [`Multiplexing`], [`RedisCluster`]) or Memcached ([`Memcached`],
//! [`Hazelcast`]). Adapters compose: [`Sharding`] spreads keys over several,
//! [`Pool`] borrows one per operation, [`CircuitBreaker`] falls back while a
//! backend fails. Every load takes a TTL: an entry older than it is a miss.
//!
//! Values are [`php_std::types::Value`]s: the Redis-protocol and Hazelcast
//! adapters store them in an [`Envelope`] encoded with a [`Codec`] ([`Json`]
//! by default, or [`Igbinary`]). Leases ([`lease`]) keep a stale reader from
//! re-caching data a concurrent writer just purged.
//!
//! A load returns `None` on a miss; a save returns whether it stored.
//!
//! PHP: values are PHP values, encoded byte for byte as the PHP library
//! writes them, so both runtimes read each other's entries.
//!
//! **Which codec.** A cache shared with a PHP runtime keeps PHP's codec; a
//! cache only Rust reads uses the native codec. Entries PHP may read go
//! through [`Cache::load`] and [`Cache::save`] with the codec the PHP side
//! configures (Appwrite: [`Igbinary`]). Entries only Rust reads (its own
//! fields, such as the project store's) go through [`Cache::load_native`]
//! and [`Cache::save_native`]: typed Rust values in the [`Native`] format,
//! with the same TTL, generation leases and purge tombstones, and no PHP
//! values built on the way.
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
//! | `Adapter\Pool` (over `Utopia\Pools\Pool`) | [`Pool`] over a [`utopia_pools::Pool`] |
//! | `Adapter\CircuitBreaker` (over `Utopia\CircuitBreaker\CircuitBreaker`) | [`CircuitBreaker`] over a [`utopia_circuit_breaker::CircuitBreaker`] |
//! | `Codec`, `Codec\Json`, `Codec\Igbinary` | [`Codec`], [`Json`], [`Igbinary`] |
//! | `Envelope` | [`Envelope`] |
//! | (Rust only) | [`Native`], [`Cache::load_native`], [`Cache::save_native`], [`Cache::save_native_with_lease`], [`Adapter::load_bytes`], [`Adapter::save_bytes`], [`Adapter::save_bytes_with_lease`] |
//! | `Utopia\Telemetry\Adapter` | [`telemetry::Telemetry`] (the `utopia-telemetry` crate) |
//!
//! PHP's `load` returns `false` on a miss; Rust returns `None`. `save`
//! returns the data or `false`; Rust returns whether it stored.

#![warn(missing_docs)]

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
mod native;
mod none;
mod pool;
#[cfg(feature = "redis")]
mod redis_adapter;
#[cfg(feature = "redis-cluster")]
mod redis_cluster;
pub mod resp;
mod sharding;
/// The telemetry library the cache records its metrics with.
pub use utopia_telemetry as telemetry;

pub use adapter::{Adapter, BoxFuture, DynAdapter, Fields, Retry};
pub use breaker::CircuitBreaker;
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
pub use native::Native;
pub use none::None;
pub use pool::Pool;
#[cfg(feature = "redis")]
pub use redis_adapter::Redis;
#[cfg(feature = "redis-cluster")]
pub use redis_cluster::RedisCluster;
pub use sharding::Sharding;

/// The redis-rs crate the Redis adapters are built on.
#[cfg(feature = "redis")]
pub use ::redis;
