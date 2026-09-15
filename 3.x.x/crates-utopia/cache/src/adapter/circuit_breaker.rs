use std::sync::Arc;

use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::feature::{Leasable, Telemetry};
use crate::value::{CacheValue, LoadResult, SaveResult};
use utopia_circuit_breaker::CircuitBreaker as UtopiaCircuitBreaker;

/// PHP `Utopia\Cache\Adapter\CircuitBreaker`.
pub struct CircuitBreaker {
    adapter: Box<dyn Adapter>,
    breaker: UtopiaCircuitBreaker,
}

impl std::fmt::Debug for CircuitBreaker {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("CircuitBreaker").finish_non_exhaustive()
    }
}

impl CircuitBreaker {
    #[must_use]
    pub fn new(adapter: impl Adapter + 'static, breaker: UtopiaCircuitBreaker) -> Self {
        Self {
            adapter: Box::new(adapter),
            breaker,
        }
    }

    #[must_use]
    pub fn from_boxed(adapter: Box<dyn Adapter>, breaker: UtopiaCircuitBreaker) -> Self {
        Self { adapter, breaker }
    }
}

impl Adapter for CircuitBreaker {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError> {
        Ok(self
            .breaker
            .call(|| LoadResult::Miss, || self.adapter.load(key, ttl, hash)))
    }

    fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError> {
        Ok(self
            .breaker
            .call(|| SaveResult::Failed, || self.adapter.save(key, data, hash)))
    }

    fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        Ok(self
            .breaker
            .call(|| false, || self.adapter.touch(key, hash)))
    }

    fn list(&self, key: &str) -> Result<Vec<String>, CacheError> {
        Ok(self.breaker.call(Vec::new, || self.adapter.list(key)))
    }

    fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        Ok(self
            .breaker
            .call(|| false, || self.adapter.purge(key, hash)))
    }

    fn flush(&self) -> Result<bool, CacheError> {
        Ok(self.breaker.call(|| false, || self.adapter.flush()))
    }

    fn ping(&self) -> bool {
        self.breaker
            .call(|| false, || Ok::<_, CacheError>(self.adapter.ping()))
    }

    fn get_size(&self) -> Result<i64, CacheError> {
        Ok(self.breaker.call(|| 0, || self.adapter.get_size()))
    }

    fn get_name(&self, key: Option<&str>) -> String {
        std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| self.adapter.get_name(key)))
            .unwrap_or_else(|_| "circuit-breaker".into())
    }

    fn as_leasable(&self) -> Option<&dyn Leasable> {
        Some(self)
    }

    fn as_telemetry_mut(&mut self) -> Option<&mut dyn Telemetry> {
        Some(self)
    }
}

impl Leasable for CircuitBreaker {
    fn get_generation(&self, key: &str) -> Result<String, CacheError> {
        if self.adapter.as_leasable().is_none() {
            return Ok("0".into());
        }
        Ok(self.breaker.call(
            || "0".into(),
            || {
                self.adapter
                    .as_leasable()
                    .expect("checked")
                    .get_generation(key)
            },
        ))
    }

    fn save_with_lease(
        &self,
        key: &str,
        data: &CacheValue,
        hash: &str,
        generation: &str,
    ) -> Result<SaveResult, CacheError> {
        if self.adapter.as_leasable().is_none() {
            return self.save(key, data, hash);
        }
        Ok(self.breaker.call(
            || SaveResult::Failed,
            || {
                self.adapter
                    .as_leasable()
                    .expect("checked")
                    .save_with_lease(key, data, hash, generation)
            },
        ))
    }
}

impl Telemetry for CircuitBreaker {
    fn set_telemetry(&mut self, telemetry: Arc<dyn utopia_telemetry::Adapter>) {
        self.breaker.set_telemetry(Arc::clone(&telemetry));
        if let Some(inner) = self.adapter.as_telemetry_mut() {
            inner.set_telemetry(telemetry);
        }
    }
}
