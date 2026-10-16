//! Cache adapters for Utopia.
//!
//! Rust port of [`utopia-php/cache`](https://github.com/utopia-php/cache).
//!
//! Layout matches PHP `Utopia\Cache\`:
//! - [`Adapter`] and [`Cache`] at the crate root
//! - implementations under [`adapter`] (`Adapter\Memory`, `Adapter\Redis`, …)
//! - [`feature`] (`Feature\Leasable`, `Feature\Retryable`, `Feature\Telemetry`)

#![allow(clippy::upper_case_acronyms)]

pub mod adapter;
mod cache;
mod error;
pub mod feature;
mod value;

pub use adapter::Adapter;
pub use cache::Cache;
pub use error::CacheError;
pub use value::{is_empty_key, CacheValue, LoadResult, SaveResult};

/// PHP `Utopia\CircuitBreaker` - re-export of [`utopia_circuit_breaker`].
pub mod circuit_breaker {
    pub use utopia_circuit_breaker::{CircuitBreaker, CircuitState};
}

/// Prelude for the PHP crate-root types plus common adapters.
pub mod prelude {
    pub use crate::adapter::{Filesystem, Memory, Pool, Sharding};
    pub use crate::{Adapter, Cache, CacheError, CacheValue, LoadResult, SaveResult};
}
