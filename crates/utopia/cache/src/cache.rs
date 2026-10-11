//! [`Cache`]: the facade over an [`Adapter`].

use std::borrow::Cow;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, OnceLock, RwLock};
use std::time::Instant;

use php_std::types::Value;

use crate::Result;
use crate::adapter::{Adapter, DynAdapter, Fields};
use utopia_telemetry::{Counter, Histogram, NoTelemetry, Telemetry, Value as Attribute};

/// Bucket boundaries of `cache.operation.duration`, in seconds.
const DURATION_BUCKETS: [f64; 9] = [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0];

/// The cache: keys (lower-cased unless case-sensitive), an [`Adapter`] that
/// stores the entries, and metrics on every operation
/// (`cache.operation.duration`, and `cache.load.total` by hit or miss).
///
/// `A` defaults to a dynamic adapter, so `Cache` alone names a cache over any
/// backend chosen at run time.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Cache, Memory};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let cache = Cache::new(Memory::new());
/// assert!(cache.save("Planet", &Value::from("Earth"), "", 0).await?);
/// assert_eq!(cache.load("PLANET", 60, "").await?, Some(Value::from("Earth")));
/// assert!(cache.purge("planet", "").await?);
/// assert_eq!(cache.load("planet", 60, "").await?, None);
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\Memory;
/// use Utopia\Cache\Cache;
///
/// $cache = new Cache(new Memory());
/// $cache->save('Planet', 'Earth');
/// echo $cache->load('PLANET', 60), "\n"; // Earth
/// $cache->purge('planet');
/// var_dump($cache->load('planet', 60)); // bool(false)
/// ```
pub struct Cache<A: ?Sized = dyn DynAdapter> {
    case_sensitive: AtomicBool,
    telemetry: RwLock<Instruments>,
    adapter: Arc<A>,
}

struct Instruments {
    telemetry: Arc<dyn Telemetry>,
    duration: OnceLock<Histogram>,
    loads: OnceLock<Counter>,
}

impl Instruments {
    fn new(telemetry: Arc<dyn Telemetry>) -> Self {
        Instruments { telemetry, duration: OnceLock::new(), loads: OnceLock::new() }
    }

    fn duration(&self) -> &Histogram {
        self.duration.get_or_init(|| {
            self.telemetry.histogram("cache.operation.duration", Some("s"), None, Some(&DURATION_BUCKETS))
        })
    }

    fn loads(&self) -> &Counter {
        self.loads.get_or_init(|| {
            self.telemetry.counter(
                "cache.load.total",
                None,
                Some("Cache load operations broken down by hit/miss result."),
            )
        })
    }
}

impl<A: Adapter> Cache<A> {
    /// A case-insensitive cache over `adapter`, recording no metrics.
    pub fn new(adapter: A) -> Self {
        Self::from_arc(Arc::new(adapter))
    }
}

impl Cache {
    /// A cache over a shared, dynamically typed adapter.
    pub fn from_dyn(adapter: Arc<dyn DynAdapter>) -> Self {
        Self::from_arc(adapter)
    }
}

impl<A: Adapter + ?Sized> Cache<A> {
    /// A cache over an adapter shared with other owners.
    pub fn from_arc(adapter: Arc<A>) -> Self {
        Cache {
            case_sensitive: AtomicBool::new(false),
            telemetry: RwLock::new(Instruments::new(Arc::new(NoTelemetry))),
            adapter,
        }
    }

    /// The adapter.
    pub fn adapter(&self) -> &Arc<A> {
        &self.adapter
    }

    /// Whether keys are case-sensitive.
    ///
    /// PHP: `$caseSensitive`.
    pub fn case_sensitive(&self) -> bool {
        self.case_sensitive.load(Ordering::Relaxed)
    }

    /// Makes keys case-sensitive or not; returns the new setting.
    ///
    /// PHP: `setCaseSensitivity`.
    pub fn set_case_sensitivity(&self, value: bool) -> bool {
        self.case_sensitive.store(value, Ordering::Relaxed);
        value
    }

    /// Records metrics with `telemetry` from now on, and hands it to the
    /// adapter. Instruments are created on first use.
    ///
    /// PHP: `setTelemetry`.
    pub fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        *self.telemetry.write().unwrap_or_else(|e| e.into_inner()) = Instruments::new(telemetry.clone());
        self.adapter.set_telemetry(telemetry);
    }

    /// `strtolower()` unless case-sensitive.
    fn fold<'a>(&self, key: &'a str) -> Cow<'a, str> {
        if self.case_sensitive() || !key.bytes().any(|b| b.is_ascii_uppercase()) {
            Cow::Borrowed(key)
        } else {
            Cow::Owned(key.to_ascii_lowercase())
        }
    }

    fn record(&self, start: Instant, operation: &str, key: Option<&str>) {
        let duration = start.elapsed().as_secs_f64();
        let name = self.adapter.name(key);
        let instruments = self.telemetry.read().unwrap_or_else(|e| e.into_inner());
        instruments
            .duration()
            .record(duration, &[("operation", Attribute::from(operation)), ("adapter", Attribute::from(name))]);
    }

    /// The entry for `key` (and `hash`) if it was saved less than `ttl`
    /// seconds ago; `None` on a miss.
    pub async fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<Value>> {
        let key = self.fold(key);
        let hash = self.fold(hash);
        let start = Instant::now();
        let result = self.adapter.load(&key, ttl, &hash).await?;
        let duration = start.elapsed().as_secs_f64();
        let name = self.adapter.name(Some(&key));
        let instruments = self.telemetry.read().unwrap_or_else(|e| e.into_inner());
        instruments
            .duration()
            .record(duration, &[("operation", Attribute::from("load")), ("adapter", Attribute::from(name))]);
        let outcome = if matches!(result, None | Some(Value::Bool(false))) { "miss" } else { "hit" };
        instruments.loads().add(1, &[("adapter", Attribute::from(name)), ("result", Attribute::from(outcome))]);
        Ok(result)
    }

    /// Saves `data` under `key` (and `hash`); `ttl > 0` also gives the key an
    /// expiry where the adapter supports it. Returns whether it was stored.
    pub async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> Result<bool> {
        let key = self.fold(key);
        let hash = self.fold(hash);
        let start = Instant::now();
        let result = self.adapter.save(&key, data, &hash, ttl).await;
        self.record(start, "save", Some(&key));
        result
    }

    /// Several fields of `key` in one round trip, missing or expired ones
    /// omitted; an empty `fields` loads them all. Empty for adapters without
    /// fields.
    pub async fn load_many(&self, key: &str, ttl: i64, fields: &[&str]) -> Result<Fields> {
        let key = self.fold(key);
        let folded: Vec<Cow<'_, str>> = fields.iter().map(|f| self.fold(f)).collect();
        let fields: Vec<&str> = folded.iter().map(|f| f.as_ref()).collect();
        let start = Instant::now();
        let result = self.adapter.load_many(&key, &fields, ttl).await;
        self.record(start, "loadMany", Some(&key));
        result
    }

    /// Writes every field of `data` in one round trip; `ttl > 0` gives the
    /// key an expiry. `false` on failure or for adapters without fields.
    pub async fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> Result<bool> {
        let key = self.fold(key);
        let start = Instant::now();
        let result = if self.case_sensitive() {
            self.adapter.save_many(&key, data, ttl).await
        } else {
            // array_change_key_case(): a later key folding onto an earlier one wins, in its place.
            let mut folded = Fields::with_capacity(data.len());
            for (field, value) in data {
                folded.insert(field.to_ascii_lowercase(), value.clone());
            }
            self.adapter.save_many(&key, &folded, ttl).await
        };
        self.record(start, "saveMany", Some(&key));
        result
    }

    /// The key's generation (advanced by each purge); `"0"` for adapters
    /// without leases.
    pub async fn generation(&self, key: &str) -> Result<String> {
        if !self.adapter.leasable() {
            return Ok("0".to_owned());
        }
        let key = self.fold(key);
        self.adapter.generation(&key).await
    }

    /// Saves only if no purge happened since `generation` was read, closing
    /// the cache-aside read-after-write race. A plain save for adapters
    /// without leases.
    pub async fn save_with_lease(&self, key: &str, data: &Value, hash: &str, generation: &str) -> Result<bool> {
        let key = self.fold(key);
        let hash = self.fold(hash);
        let start = Instant::now();
        let result = if self.adapter.leasable() {
            self.adapter.save_with_lease(&key, data, &hash, generation).await
        } else {
            self.adapter.save(&key, data, &hash, 0).await
        };
        self.record(start, "saveWithLease", Some(&key));
        result
    }

    /// Refreshes an entry's timestamp without replacing its data.
    pub async fn touch(&self, key: &str, hash: &str) -> Result<bool> {
        let key = self.fold(key);
        let hash = self.fold(hash);
        let start = Instant::now();
        let result = self.adapter.touch(&key, &hash).await?;
        self.record(start, "touch", Some(&key));
        Ok(result)
    }

    /// The fields stored under `key`.
    pub async fn list(&self, key: &str) -> Result<Vec<String>> {
        let key = self.fold(key);
        let start = Instant::now();
        let result = self.adapter.list(&key).await?;
        self.record(start, "list", Some(&key));
        Ok(result)
    }

    /// Removes the entry for `key` (or one `hash` of it).
    pub async fn purge(&self, key: &str, hash: &str) -> Result<bool> {
        let key = self.fold(key);
        let hash = self.fold(hash);
        let start = Instant::now();
        let result = self.adapter.purge(&key, &hash).await?;
        self.record(start, "purge", Some(&key));
        Ok(result)
    }

    /// Removes every entry.
    pub async fn flush(&self) -> Result<bool> {
        let start = Instant::now();
        let result = self.adapter.flush().await?;
        self.record(start, "flush", None);
        Ok(result)
    }

    /// Whether the backend answers.
    pub async fn ping(&self) -> Result<bool> {
        self.adapter.ping().await
    }

    /// The size of the cache.
    ///
    /// PHP: `getSize`.
    pub async fn size(&self) -> Result<i64> {
        let start = Instant::now();
        let result = self.adapter.size().await?;
        self.record(start, "size", None);
        Ok(result)
    }
}
