//! [`CircuitBreaker`]: an adapter whose failures fall back instead of
//! failing, behind a [`utopia_circuit_breaker::CircuitBreaker`] that stops
//! calling a failing backend.

use std::borrow::Cow;
use std::future::Future;
use std::sync::Arc;

use php_std::types::Value;
use utopia_circuit_breaker::{CircuitBreaker as Breaker, Failure};
use utopia_telemetry::Telemetry;

use crate::adapter::{Adapter, Fields};
use crate::{Error, Result};

impl Failure for Error {
    /// The PHP exception class of the error, as the breaker's
    /// `exception.type` attribute.
    fn exception_type(&self) -> Cow<'_, str> {
        Cow::Borrowed(self.php_class())
    }
}

/// A breaker's own failure (its shared store failed), as a cache error.
fn breaker_error(e: utopia_circuit_breaker::Error) -> Error {
    match e {
        utopia_circuit_breaker::Error::InvalidArgument(m) => Error::InvalidArgument(m),
        utopia_circuit_breaker::Error::Adapter(m) => Error::Runtime(m),
    }
}

/// An adapter behind a circuit breaker: while the backend fails, operations
/// return a fallback (a miss, `false`, an empty list, `0`) instead of an
/// error, and an open circuit skips the backend entirely.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, CircuitBreaker, Memory};
/// use utopia_circuit_breaker::{CircuitBreaker as Breaker, Options};
///
/// # utopia_async::Runtime::new()?.block_on(async {
/// let cache = CircuitBreaker::new(Memory::new(), Breaker::new(Options::default()).unwrap());
/// assert!(cache.save("key", &Value::from("value"), "", 0).await?);
/// assert_eq!(cache.load("key", 60, "").await?, Some(Value::from("value")));
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\CircuitBreaker;
/// use Utopia\Cache\Adapter\Memory;
/// use Utopia\CircuitBreaker\CircuitBreaker as Breaker;
///
/// $cache = new CircuitBreaker(new Memory(), new Breaker());
/// $cache->save('key', 'value');
/// echo $cache->load('key', 60), "\n"; // value
/// ```
///
/// PHP: `Utopia\Cache\Adapter\CircuitBreaker`.
pub struct CircuitBreaker<A> {
    adapter: A,
    breaker: Arc<Breaker>,
}

impl<A: Adapter> CircuitBreaker<A> {
    /// `adapter` behind `breaker` (a breaker or one shared with other adapters).
    pub fn new(adapter: A, breaker: impl Into<Arc<Breaker>>) -> Self {
        CircuitBreaker { adapter, breaker: breaker.into() }
    }

    /// The adapter behind the breaker.
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// The breaker.
    pub fn breaker(&self) -> &Arc<Breaker> {
        &self.breaker
    }

    /// `call`'s value when the breaker lets it through and it succeeds;
    /// `fallback` otherwise.
    async fn guarded<T>(&self, fallback: T, call: impl Future<Output = Result<T>>) -> Result<T> {
        self.breaker.call(move || fallback, call).await.map_err(breaker_error)
    }
}

impl<A: Adapter> Adapter for CircuitBreaker<A> {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<Value>> {
        self.guarded(None, self.adapter.load(key, ttl, hash)).await
    }

    async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> Result<bool> {
        self.guarded(false, self.adapter.save(key, data, hash, ttl)).await
    }

    async fn touch(&self, key: &str, hash: &str) -> Result<bool> {
        self.guarded(false, self.adapter.touch(key, hash)).await
    }

    async fn list(&self, key: &str) -> Result<Vec<String>> {
        self.guarded(Vec::new(), self.adapter.list(key)).await
    }

    async fn purge(&self, key: &str, hash: &str) -> Result<bool> {
        self.guarded(false, self.adapter.purge(key, hash)).await
    }

    async fn flush(&self) -> Result<bool> {
        self.guarded(false, self.adapter.flush()).await
    }

    async fn ping(&self) -> Result<bool> {
        self.guarded(false, self.adapter.ping()).await
    }

    async fn size(&self) -> Result<i64> {
        self.guarded(0, self.adapter.size()).await
    }

    fn name(&self, key: Option<&str>) -> &str {
        self.adapter.name(key)
    }

    fn batchable(&self) -> bool {
        true
    }

    async fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> Result<Fields> {
        if !self.adapter.batchable() {
            return Ok(Fields::new());
        }
        self.guarded(Fields::new(), self.adapter.load_many(key, fields, ttl)).await
    }

    async fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> Result<bool> {
        if !self.adapter.batchable() {
            return Ok(false);
        }
        self.guarded(false, self.adapter.save_many(key, data, ttl)).await
    }

    fn leasable(&self) -> bool {
        true
    }

    async fn generation(&self, key: &str) -> Result<String> {
        if !self.adapter.leasable() {
            return Ok("0".to_owned());
        }
        self.guarded("0".to_owned(), self.adapter.generation(key)).await
    }

    async fn save_with_lease(&self, key: &str, data: &Value, hash: &str, generation: &str) -> Result<bool> {
        if !self.adapter.leasable() {
            return self.save(key, data, hash, 0).await;
        }
        self.guarded(false, self.adapter.save_with_lease(key, data, hash, generation)).await
    }

    async fn load_bytes(&self, key: &str, hash: &str) -> Result<Option<Vec<u8>>> {
        self.guarded(None, self.adapter.load_bytes(key, hash)).await
    }

    async fn save_bytes(&self, key: &str, bytes: &[u8], hash: &str, ttl: i64) -> Result<bool> {
        self.guarded(false, self.adapter.save_bytes(key, bytes, hash, ttl)).await
    }

    async fn save_bytes_with_lease(&self, key: &str, bytes: &[u8], hash: &str, generation: &str) -> Result<bool> {
        if !self.adapter.leasable() {
            return self.save_bytes(key, bytes, hash, 0).await;
        }
        self.guarded(false, self.adapter.save_bytes_with_lease(key, bytes, hash, generation)).await
    }

    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        self.breaker.set_telemetry(telemetry.clone());
        self.adapter.set_telemetry(telemetry);
    }
}
