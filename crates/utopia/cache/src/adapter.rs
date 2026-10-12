//! [`Adapter`]: where a cache keeps its entries, and the dynamic form
//! [`DynAdapter`] for adapters chosen at run time.

use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;
use std::time::{SystemTime, UNIX_EPOCH};

use indexmap::IndexMap;
use php_std::types::Value;

use crate::Result;
use utopia_telemetry::Telemetry;

/// Fields of a hash and their values, in order.
///
/// PHP: `loadMany`, `saveMany`.
pub type Fields = IndexMap<String, Value>;

/// A cache backend.
///
/// Entries live under a `key`; adapters that store hashes keep several
/// fields under one key, chosen by `hash` (an empty or `"0"` hash means the
/// key itself). A load misses (`None`) when the entry is absent or older than
/// its `ttl`. Implement it to add a backend; the optional capabilities
/// (batches of fields, leases, telemetry) have defaults that behave like an
/// adapter without them.
pub trait Adapter: Send + Sync {
    /// The entry for `key` (and `hash`) when it was written less than `ttl`
    /// seconds ago.
    fn load(&self, key: &str, ttl: i64, hash: &str) -> impl Future<Output = Result<Option<Value>>> + Send;

    /// Writes `data` under `key` (and `hash`). When `ttl > 0`, adapters that
    /// support it also give the key an expiry. Returns whether it was stored.
    fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> impl Future<Output = Result<bool>> + Send;

    /// Refreshes an entry's timestamp without replacing its data.
    fn touch(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send;

    /// The fields stored under `key`.
    fn list(&self, key: &str) -> impl Future<Output = Result<Vec<String>>> + Send;

    /// Removes the entry for `key` (or one `hash` of it).
    fn purge(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send;

    /// Removes every entry.
    fn flush(&self) -> impl Future<Output = Result<bool>> + Send;

    /// Whether the backend answers.
    fn ping(&self) -> impl Future<Output = Result<bool>> + Send;

    /// The size of the cache: entries, or bytes for the filesystem.
    fn size(&self) -> impl Future<Output = Result<i64>> + Send;

    /// The adapter's name (for metrics), of the shard serving `key` when given.
    fn name(&self, key: Option<&str>) -> &str;

    /// Whether [`Adapter::load_many`] and [`Adapter::save_many`] are supported.
    ///
    /// PHP: `Feature\Batchable`.
    fn batchable(&self) -> bool {
        false
    }

    /// Several fields of `key` at once, missing and expired ones omitted; an
    /// empty `fields` loads every field. Empty without batch support.
    fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> impl Future<Output = Result<Fields>> + Send {
        let _ = (key, fields, ttl);
        async { Ok(Fields::new()) }
    }

    /// Writes every field of `data` at once; `ttl > 0` gives the key an
    /// expiry. `false` without batch support.
    fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        let _ = (key, data, ttl);
        async { Ok(false) }
    }

    /// Whether [`Adapter::generation`] and [`Adapter::save_with_lease`] guard
    /// against stale writes.
    ///
    /// PHP: `Feature\Leasable`.
    fn leasable(&self) -> bool {
        false
    }

    /// The key's generation, advanced by every purge; `"0"` before the first
    /// purge and without lease support.
    fn generation(&self, key: &str) -> impl Future<Output = Result<String>> + Send {
        let _ = key;
        async { Ok("0".to_owned()) }
    }

    /// Saves only if the key's generation is still `generation` (no purge
    /// since the caller read it). Without lease support, a plain save.
    fn save_with_lease(
        &self,
        key: &str,
        data: &Value,
        hash: &str,
        generation: &str,
    ) -> impl Future<Output = Result<bool>> + Send {
        let _ = generation;
        self.save(key, data, hash, 0)
    }

    /// The bytes [`Adapter::save_bytes`] stored under `key` (and `hash`),
    /// whatever their age; `None` when absent. Bytes carry their own save
    /// time ([`Native`](crate::Native) entries), which the caller checks.
    ///
    /// The default reads a string saved through [`Adapter::load`]; adapters
    /// whose codec cannot hold arbitrary bytes (a JSON envelope) override the
    /// three byte methods, as the Redis-protocol adapters and Hazelcast do.
    ///
    /// Rust only: entries only Rust reads ([`crate::Cache::load_native`]).
    fn load_bytes(&self, key: &str, hash: &str) -> impl Future<Output = Result<Option<Vec<u8>>>> + Send {
        async move {
            Ok(match self.load(key, i64::MAX, hash).await? {
                Some(Value::Str(stored)) => Some(stored.as_bytes().to_vec()),
                _ => None,
            })
        }
    }

    /// Writes `bytes` under `key` (and `hash`) as they are; `ttl > 0` gives
    /// the key an expiry where the adapter supports it. Returns whether it
    /// was stored. The default saves them as a string through [`Adapter::save`].
    ///
    /// Rust only: entries only Rust reads ([`crate::Cache::save_native`]).
    fn save_bytes(&self, key: &str, bytes: &[u8], hash: &str, ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        let data = Value::Str(bytes.into());
        async move { self.save(key, &data, hash, ttl).await }
    }

    /// [`Adapter::save_bytes`] only if the key's generation is still
    /// `generation`, as [`Adapter::save_with_lease`]. The default saves them
    /// as a string through [`Adapter::save_with_lease`].
    ///
    /// Rust only: entries only Rust reads ([`crate::Cache::save_native_with_lease`]).
    fn save_bytes_with_lease(
        &self,
        key: &str,
        bytes: &[u8],
        hash: &str,
        generation: &str,
    ) -> impl Future<Output = Result<bool>> + Send {
        let data = Value::Str(bytes.into());
        async move { self.save_with_lease(key, &data, hash, generation).await }
    }

    /// Hands the adapter a telemetry backend for its own metrics. Ignored by
    /// adapters without metrics.
    ///
    /// PHP: `Feature\Telemetry`.
    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        let _ = telemetry;
    }
}

/// A boxed future, for [`DynAdapter`].
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// The object-safe form of [`Adapter`], implemented for every adapter, so
/// adapters of different types can share a `Vec<Arc<dyn DynAdapter>>` (and
/// `dyn DynAdapter` is itself an [`Adapter`]).
pub trait DynAdapter: Send + Sync {
    /// Object-safe [`Adapter::load`].
    fn dyn_load<'a>(&'a self, key: &'a str, ttl: i64, hash: &'a str) -> BoxFuture<'a, Result<Option<Value>>>;
    /// Object-safe [`Adapter::save`].
    fn dyn_save<'a>(&'a self, key: &'a str, data: &'a Value, hash: &'a str, ttl: i64) -> BoxFuture<'a, Result<bool>>;
    /// Object-safe [`Adapter::touch`].
    fn dyn_touch<'a>(&'a self, key: &'a str, hash: &'a str) -> BoxFuture<'a, Result<bool>>;
    /// Object-safe [`Adapter::list`].
    fn dyn_list<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<Vec<String>>>;
    /// Object-safe [`Adapter::purge`].
    fn dyn_purge<'a>(&'a self, key: &'a str, hash: &'a str) -> BoxFuture<'a, Result<bool>>;
    /// Object-safe [`Adapter::flush`].
    fn dyn_flush(&self) -> BoxFuture<'_, Result<bool>>;
    /// Object-safe [`Adapter::ping`].
    fn dyn_ping(&self) -> BoxFuture<'_, Result<bool>>;
    /// Object-safe [`Adapter::size`].
    fn dyn_size(&self) -> BoxFuture<'_, Result<i64>>;
    /// Object-safe [`Adapter::name`].
    fn dyn_name(&self, key: Option<&str>) -> &str;
    /// Object-safe [`Adapter::batchable`].
    fn dyn_batchable(&self) -> bool;
    /// Object-safe [`Adapter::load_many`].
    fn dyn_load_many<'a>(&'a self, key: &'a str, fields: &'a [&'a str], ttl: i64) -> BoxFuture<'a, Result<Fields>>;
    /// Object-safe [`Adapter::save_many`].
    fn dyn_save_many<'a>(&'a self, key: &'a str, data: &'a Fields, ttl: i64) -> BoxFuture<'a, Result<bool>>;
    /// Object-safe [`Adapter::leasable`].
    fn dyn_leasable(&self) -> bool;
    /// Object-safe [`Adapter::generation`].
    fn dyn_generation<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<String>>;
    /// Object-safe [`Adapter::save_with_lease`].
    fn dyn_save_with_lease<'a>(
        &'a self,
        key: &'a str,
        data: &'a Value,
        hash: &'a str,
        generation: &'a str,
    ) -> BoxFuture<'a, Result<bool>>;
    /// Object-safe [`Adapter::load_bytes`].
    fn dyn_load_bytes<'a>(&'a self, key: &'a str, hash: &'a str) -> BoxFuture<'a, Result<Option<Vec<u8>>>>;
    /// Object-safe [`Adapter::save_bytes`].
    fn dyn_save_bytes<'a>(
        &'a self,
        key: &'a str,
        bytes: &'a [u8],
        hash: &'a str,
        ttl: i64,
    ) -> BoxFuture<'a, Result<bool>>;
    /// Object-safe [`Adapter::save_bytes_with_lease`].
    fn dyn_save_bytes_with_lease<'a>(
        &'a self,
        key: &'a str,
        bytes: &'a [u8],
        hash: &'a str,
        generation: &'a str,
    ) -> BoxFuture<'a, Result<bool>>;
    /// Object-safe [`Adapter::set_telemetry`].
    fn dyn_set_telemetry(&self, telemetry: Arc<dyn Telemetry>);
}

impl<A: Adapter> DynAdapter for A {
    fn dyn_load<'a>(&'a self, key: &'a str, ttl: i64, hash: &'a str) -> BoxFuture<'a, Result<Option<Value>>> {
        Box::pin(self.load(key, ttl, hash))
    }
    fn dyn_save<'a>(&'a self, key: &'a str, data: &'a Value, hash: &'a str, ttl: i64) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.save(key, data, hash, ttl))
    }
    fn dyn_touch<'a>(&'a self, key: &'a str, hash: &'a str) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.touch(key, hash))
    }
    fn dyn_list<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<Vec<String>>> {
        Box::pin(self.list(key))
    }
    fn dyn_purge<'a>(&'a self, key: &'a str, hash: &'a str) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.purge(key, hash))
    }
    fn dyn_flush(&self) -> BoxFuture<'_, Result<bool>> {
        Box::pin(self.flush())
    }
    fn dyn_ping(&self) -> BoxFuture<'_, Result<bool>> {
        Box::pin(self.ping())
    }
    fn dyn_size(&self) -> BoxFuture<'_, Result<i64>> {
        Box::pin(self.size())
    }
    fn dyn_name(&self, key: Option<&str>) -> &str {
        self.name(key)
    }
    fn dyn_batchable(&self) -> bool {
        self.batchable()
    }
    fn dyn_load_many<'a>(&'a self, key: &'a str, fields: &'a [&'a str], ttl: i64) -> BoxFuture<'a, Result<Fields>> {
        Box::pin(self.load_many(key, fields, ttl))
    }
    fn dyn_save_many<'a>(&'a self, key: &'a str, data: &'a Fields, ttl: i64) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.save_many(key, data, ttl))
    }
    fn dyn_leasable(&self) -> bool {
        self.leasable()
    }
    fn dyn_generation<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<String>> {
        Box::pin(self.generation(key))
    }
    fn dyn_save_with_lease<'a>(
        &'a self,
        key: &'a str,
        data: &'a Value,
        hash: &'a str,
        generation: &'a str,
    ) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.save_with_lease(key, data, hash, generation))
    }
    fn dyn_load_bytes<'a>(&'a self, key: &'a str, hash: &'a str) -> BoxFuture<'a, Result<Option<Vec<u8>>>> {
        Box::pin(self.load_bytes(key, hash))
    }
    fn dyn_save_bytes<'a>(
        &'a self,
        key: &'a str,
        bytes: &'a [u8],
        hash: &'a str,
        ttl: i64,
    ) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.save_bytes(key, bytes, hash, ttl))
    }
    fn dyn_save_bytes_with_lease<'a>(
        &'a self,
        key: &'a str,
        bytes: &'a [u8],
        hash: &'a str,
        generation: &'a str,
    ) -> BoxFuture<'a, Result<bool>> {
        Box::pin(self.save_bytes_with_lease(key, bytes, hash, generation))
    }
    fn dyn_set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        self.set_telemetry(telemetry)
    }
}

impl Adapter for dyn DynAdapter {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<Value>> {
        self.dyn_load(key, ttl, hash).await
    }
    async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> Result<bool> {
        self.dyn_save(key, data, hash, ttl).await
    }
    async fn touch(&self, key: &str, hash: &str) -> Result<bool> {
        self.dyn_touch(key, hash).await
    }
    async fn list(&self, key: &str) -> Result<Vec<String>> {
        self.dyn_list(key).await
    }
    async fn purge(&self, key: &str, hash: &str) -> Result<bool> {
        self.dyn_purge(key, hash).await
    }
    fn flush(&self) -> impl Future<Output = Result<bool>> + Send {
        self.dyn_flush()
    }
    fn ping(&self) -> impl Future<Output = Result<bool>> + Send {
        self.dyn_ping()
    }
    fn size(&self) -> impl Future<Output = Result<i64>> + Send {
        self.dyn_size()
    }
    fn name(&self, key: Option<&str>) -> &str {
        self.dyn_name(key)
    }
    fn batchable(&self) -> bool {
        self.dyn_batchable()
    }
    async fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> Result<Fields> {
        self.dyn_load_many(key, fields, ttl).await
    }
    async fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> Result<bool> {
        self.dyn_save_many(key, data, ttl).await
    }
    fn leasable(&self) -> bool {
        self.dyn_leasable()
    }
    async fn generation(&self, key: &str) -> Result<String> {
        self.dyn_generation(key).await
    }
    async fn save_with_lease(&self, key: &str, data: &Value, hash: &str, generation: &str) -> Result<bool> {
        self.dyn_save_with_lease(key, data, hash, generation).await
    }
    async fn load_bytes(&self, key: &str, hash: &str) -> Result<Option<Vec<u8>>> {
        self.dyn_load_bytes(key, hash).await
    }
    async fn save_bytes(&self, key: &str, bytes: &[u8], hash: &str, ttl: i64) -> Result<bool> {
        self.dyn_save_bytes(key, bytes, hash, ttl).await
    }
    async fn save_bytes_with_lease(&self, key: &str, bytes: &[u8], hash: &str, generation: &str) -> Result<bool> {
        self.dyn_save_bytes_with_lease(key, bytes, hash, generation).await
    }
    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        self.dyn_set_telemetry(telemetry)
    }
}

macro_rules! forward {
    ($($ptr:ident),*) => {$(
        impl<A: Adapter + ?Sized> Adapter for $ptr<A> {
            fn load(&self, key: &str, ttl: i64, hash: &str) -> impl Future<Output = Result<Option<Value>>> + Send {
                (**self).load(key, ttl, hash)
            }
            fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> impl Future<Output = Result<bool>> + Send {
                (**self).save(key, data, hash, ttl)
            }
            fn touch(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
                (**self).touch(key, hash)
            }
            fn list(&self, key: &str) -> impl Future<Output = Result<Vec<String>>> + Send {
                (**self).list(key)
            }
            fn purge(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
                (**self).purge(key, hash)
            }
            fn flush(&self) -> impl Future<Output = Result<bool>> + Send {
                (**self).flush()
            }
            fn ping(&self) -> impl Future<Output = Result<bool>> + Send {
                (**self).ping()
            }
            fn size(&self) -> impl Future<Output = Result<i64>> + Send {
                (**self).size()
            }
            fn name(&self, key: Option<&str>) -> &str {
                (**self).name(key)
            }
            fn batchable(&self) -> bool {
                (**self).batchable()
            }
            fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> impl Future<Output = Result<Fields>> + Send {
                (**self).load_many(key, fields, ttl)
            }
            fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> impl Future<Output = Result<bool>> + Send {
                (**self).save_many(key, data, ttl)
            }
            fn leasable(&self) -> bool {
                (**self).leasable()
            }
            fn generation(&self, key: &str) -> impl Future<Output = Result<String>> + Send {
                (**self).generation(key)
            }
            fn save_with_lease(
                &self,
                key: &str,
                data: &Value,
                hash: &str,
                generation: &str,
            ) -> impl Future<Output = Result<bool>> + Send {
                (**self).save_with_lease(key, data, hash, generation)
            }
            fn load_bytes(&self, key: &str, hash: &str) -> impl Future<Output = Result<Option<Vec<u8>>>> + Send {
                (**self).load_bytes(key, hash)
            }
            fn save_bytes(&self, key: &str, bytes: &[u8], hash: &str, ttl: i64) -> impl Future<Output = Result<bool>> + Send {
                (**self).save_bytes(key, bytes, hash, ttl)
            }
            fn save_bytes_with_lease(
                &self,
                key: &str,
                bytes: &[u8],
                hash: &str,
                generation: &str,
            ) -> impl Future<Output = Result<bool>> + Send {
                (**self).save_bytes_with_lease(key, bytes, hash, generation)
            }
            fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
                (**self).set_telemetry(telemetry)
            }
        }
    )*};
}

forward!(Arc, Box);

/// Retries of a networked adapter: how many times a command that failed on the
/// connection is retried, and the pause between attempts.
///
/// PHP: `Feature\Retryable`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Retry {
    max_retries: u32,
    delay_ms: i64,
}

impl Default for Retry {
    /// No retries, one second between attempts.
    fn default() -> Self {
        Retry { max_retries: 0, delay_ms: 1000 }
    }
}

impl Retry {
    /// The fewest retries allowed.
    ///
    /// PHP: `Retryable::MIN_RETRIES`.
    pub const MIN_RETRIES: i64 = 0;
    /// The most retries allowed.
    ///
    /// PHP: `Retryable::MAX_RETRIES`.
    pub const MAX_RETRIES: i64 = 10;

    /// The same settings with `retries` retries, clamped to `0..=10`.
    ///
    /// PHP: `setMaxRetries`.
    pub fn with_max_retries(self, retries: i64) -> Self {
        Retry { max_retries: retries.clamp(Self::MIN_RETRIES, Self::MAX_RETRIES) as u32, ..self }
    }

    /// The same settings with `delay_ms` milliseconds between attempts.
    ///
    /// PHP: `setRetryDelay`.
    pub fn with_delay_ms(self, delay_ms: i64) -> Self {
        Retry { delay_ms, ..self }
    }

    /// How many times a failed command is retried.
    ///
    /// PHP: `getMaxRetries()`.
    pub fn max_retries(&self) -> u32 {
        self.max_retries
    }

    /// The pause between attempts, in milliseconds.
    ///
    /// PHP: `getRetryDelay()`.
    pub fn delay_ms(&self) -> i64 {
        self.delay_ms
    }

    #[cfg_attr(not(any(feature = "redis", feature = "memcached")), allow(dead_code))]
    pub(crate) async fn pause(&self) {
        // usleep() with a negative delay does not sleep.
        if self.delay_ms > 0 {
            tokio::time::sleep(std::time::Duration::from_millis(self.delay_ms as u64)).await;
        }
    }
}

/// The current unix time in seconds.
///
/// PHP: `time()`.
pub(crate) fn now() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs() as i64).unwrap_or(0)
}

/// The hash field an entry is stored under: `hash`, or the key when `hash`
/// is `""` or `"0"`.
///
/// PHP: `$hash === '' || $hash === '0'`.
#[cfg_attr(not(feature = "redis"), allow(dead_code))]
pub(crate) fn field<'a>(key: &'a str, hash: &'a str) -> &'a str {
    if hash.is_empty() || hash == "0" { key } else { hash }
}

/// `$key === '' || $key === '0'`.
pub(crate) fn blank(key: &str) -> bool {
    key.is_empty() || key == "0"
}

/// PHP's `empty($data)` for a string or array.
pub(crate) fn empty(data: &Value) -> bool {
    !data.truthy()
}

/// The data an adapter's `array|string $data` parameter receives from
/// coercive-mode callers: strings and arrays as they are, other scalars as
/// their string form (`1` is `"1"`, `true` is `"1"`, `false` is `""`), and a
/// `TypeError` for null and objects. `target` names the function and
/// parameter for the message (`Utopia\Cache\Adapter\Memory::save(): Argument #2 ($data)`).
pub(crate) fn coerce<'a>(data: &'a Value, target: &str) -> Result<std::borrow::Cow<'a, Value>> {
    use std::borrow::Cow;
    match data {
        Value::Str(_) | Value::Array(_) => Ok(Cow::Borrowed(data)),
        Value::Int(_) | Value::Float(_) | Value::Bool(_) => {
            Ok(Cow::Owned(Value::Str(data.to_php_string().map_err(|e| crate::Error::Type(e.message))?)))
        }
        other => {
            Err(crate::Error::Type(format!("{target} must be of type array|string, {} given", other.debug_type())))
        }
    }
}
