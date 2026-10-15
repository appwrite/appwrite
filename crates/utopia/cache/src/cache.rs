//! [`Cache`]: the facade over an [`Adapter`].

use std::borrow::Cow;
use std::collections::BTreeMap;
use std::future::Future;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex, OnceLock, RwLock};
use std::time::Instant;

use indexmap::{IndexMap, IndexSet};
use php_std::types::Value;
use serde::Serialize;
use serde::de::DeserializeOwned;

use crate::adapter::{Adapter, DynAdapter, Fields, now};
use crate::{Native, Result};
use utopia_telemetry::{Counter, Histogram, NoTelemetry, Telemetry, Value as Attribute};

/// The keys purged while a [`Cache::record_purges`] call ran: each key, as
/// the cache folded it, with its purged hashes (`""` for the whole key), in
/// the order they were first purged.
///
/// PHP: the `[key => [hash => true]]` array `recordPurges()` returns.
pub type Purges = IndexMap<String, IndexSet<String>>;

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
/// # utopia_async::Runtime::new()?.block_on(async {
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
    recordings: Recordings,
    adapter: Arc<A>,
}

/// The [`Cache::record_purges`] calls running, each with the purges made since it started.
///
/// PHP: `$recordedPurges` and `$nextRecording`.
#[derive(Default)]
struct Recordings {
    next: AtomicU64,
    running: Mutex<BTreeMap<u64, Purges>>,
}

impl Recordings {
    fn running(&self) -> std::sync::MutexGuard<'_, BTreeMap<u64, Purges>> {
        self.running.lock().unwrap_or_else(|e| e.into_inner())
    }
}

/// Ends a recording when its call returns, fails or is dropped.
struct Recording<'a> {
    recordings: &'a Recordings,
    id: u64,
}

impl Drop for Recording<'_> {
    fn drop(&mut self) {
        self.recordings.running().remove(&self.id);
    }
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
            recordings: Recordings::default(),
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
        self.record_load(start, "load", &key, !matches!(result, None | Some(Value::Bool(false))));
        Ok(result)
    }

    /// Records a load's duration and whether it hit.
    fn record_load(&self, start: Instant, operation: &str, key: &str, hit: bool) {
        let duration = start.elapsed().as_secs_f64();
        let name = self.adapter.name(Some(key));
        let instruments = self.telemetry.read().unwrap_or_else(|e| e.into_inner());
        instruments
            .duration()
            .record(duration, &[("operation", Attribute::from(operation)), ("adapter", Attribute::from(name))]);
        let outcome = if hit { "hit" } else { "miss" };
        instruments.loads().add(1, &[("adapter", Attribute::from(name)), ("result", Attribute::from(outcome))]);
    }

    /// The [`Native`] entry for `key` (and `hash`) if it was saved less than
    /// `ttl` seconds ago; `None` on a miss, including bytes that are not a
    /// native entry of a `T`.
    ///
    /// For entries only Rust reads: a cache shared with a PHP runtime keeps
    /// PHP's codec and goes through [`Cache::load`].
    ///
    /// ```
    /// use utopia_cache::{Cache, Memory};
    ///
    /// # utopia_async::Runtime::new()?.block_on(async {
    /// let cache = Cache::new(Memory::new());
    /// let events = vec!["users.*.create".to_owned()];
    /// assert!(cache.save_native("project:1:events", &events, "", 0).await?);
    /// assert_eq!(cache.load_native::<Vec<String>>("project:1:events", 60, "").await?, Some(events));
    /// # Ok::<(), utopia_cache::Error>(())
    /// # })?;
    /// # Ok::<(), Box<dyn std::error::Error>>(())
    /// ```
    pub async fn load_native<T: DeserializeOwned>(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<T>> {
        let key = self.fold(key);
        let hash = self.fold(hash);
        let start = Instant::now();
        let stored = self.adapter.load_bytes(&key, &hash).await?;
        let result = stored.and_then(|stored| Native.decode(&stored, ttl, now()));
        self.record_load(start, "loadNative", &key, result.is_some());
        Ok(result)
    }

    /// Saves `data` under `key` (and `hash`) as a [`Native`] entry; `ttl > 0`
    /// also gives the key an expiry where the adapter supports it. Returns
    /// whether it was stored; fails when `data` cannot be encoded.
    pub async fn save_native<T: Serialize + ?Sized>(&self, key: &str, data: &T, hash: &str, ttl: i64) -> Result<bool> {
        let key = self.fold(key);
        let hash = self.fold(hash);
        let start = Instant::now();
        let stored = Native.encode(data, now())?;
        let result = self.adapter.save_bytes(&key, &stored, &hash, ttl).await;
        self.record(start, "saveNative", Some(&key));
        result
    }

    /// [`Cache::save_native`] only if no purge happened since `generation`
    /// was read, as [`Cache::save_with_lease`]. A plain save for adapters
    /// without leases.
    pub async fn save_native_with_lease<T: Serialize + ?Sized>(
        &self,
        key: &str,
        data: &T,
        hash: &str,
        generation: &str,
    ) -> Result<bool> {
        let key = self.fold(key);
        let hash = self.fold(hash);
        let start = Instant::now();
        let stored = Native.encode(data, now())?;
        let result = if self.adapter.leasable() {
            self.adapter.save_bytes_with_lease(&key, &stored, &hash, generation).await
        } else {
            self.adapter.save_bytes(&key, &stored, &hash, 0).await
        };
        self.record(start, "saveNativeWithLease", Some(&key));
        result
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

    /// Whether the adapter keeps generations ([`Cache::generation`]) and
    /// leased saves. Not in PHP.
    pub fn leasable(&self) -> bool {
        self.adapter.leasable()
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
        for purges in self.recordings.running().values_mut() {
            purges.entry(key.to_string()).or_default().insert(hash.to_string());
        }
        let start = Instant::now();
        let result = self.adapter.purge(&key, &hash).await?;
        self.record(start, "purge", Some(&key));
        Ok(result)
    }

    /// Runs `run` and returns every key [`Cache::purge`] purged while it ran,
    /// or `run`'s error. Calls that overlap, for example from concurrent
    /// tasks, each record all purges made while they run.
    ///
    /// ```
    /// use utopia_cache::{Cache, Memory};
    ///
    /// # utopia_async::Runtime::new()?.block_on(async {
    /// let cache = Cache::new(Memory::new());
    /// let purges = cache
    ///     .record_purges(async {
    ///         cache.purge("Rows", "a").await?;
    ///         cache.purge("rows", "").await?;
    ///         Ok::<_, utopia_cache::Error>(())
    ///     })
    ///     .await?;
    /// assert_eq!(purges["rows"].iter().collect::<Vec<_>>(), ["a", ""]);
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
    /// $purges = $cache->recordPurges(function () use ($cache) {
    ///     $cache->purge('Rows', 'a');
    ///     $cache->purge('rows');
    /// });
    /// echo implode(',', array_keys($purges['rows'])), "\n"; // a,
    /// ```
    ///
    /// PHP: `recordPurges($callback)`, which returns `[key => [hash => true]]`.
    pub async fn record_purges<E>(
        &self,
        run: impl Future<Output = std::result::Result<(), E>>,
    ) -> std::result::Result<Purges, E> {
        let id = self.recordings.next.fetch_add(1, Ordering::Relaxed);
        self.recordings.running().insert(id, Purges::new());
        let recording = Recording { recordings: &self.recordings, id };
        run.await?;
        let purges = self.recordings.running().remove(&recording.id).unwrap_or_default();
        Ok(purges)
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
