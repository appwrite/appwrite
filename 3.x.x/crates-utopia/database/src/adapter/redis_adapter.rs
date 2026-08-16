//! Redis cache-layer adapter (PHP `Utopia\Database\Adapter\Redis`).

use super::{Adapter, AdapterState};
use crate::adapter::memory::Memory;
use crate::value::AttrValue;

/// Redis adapter (PHP `Utopia\Database\Adapter\Redis`).
///
/// PHP Redis wraps another adapter and caches document reads. The Rust port
/// keeps the same constructor shape; live Redis I/O is behind the `redis`
/// feature. Without a live client this still compiles and delegates to Memory.
#[derive(Debug)]
pub struct Redis {
    state: AdapterState,
    inner: Memory,
    url: String,
}

impl Redis {
    /// Wrap a Memory adapter (PHP constructor takes the parent adapter + Redis).
    #[must_use]
    pub fn new(url: impl Into<String>) -> Self {
        Self {
            state: AdapterState::default(),
            inner: Memory::new(),
            url: url.into(),
        }
    }

    /// Redis URL.
    #[must_use]
    pub fn url(&self) -> &str {
        &self.url
    }

    /// The wrapped in-process adapter.
    #[must_use]
    pub fn inner(&self) -> &Memory {
        &self.inner
    }

    /// Mutable wrapped adapter.
    pub fn inner_mut(&mut self) -> &mut Memory {
        &mut self.inner
    }
}

impl Adapter for Redis {
    fn state(&self) -> &AdapterState {
        &self.state
    }
    fn state_mut(&mut self) -> &mut AdapterState {
        &mut self.state
    }
    fn get_support_for_caching(&self) -> bool {
        true
    }
    fn get_driver(&self) -> AttrValue {
        AttrValue::from("redis")
    }
}
