//! Cache adapters PHP's cache tests use.
//!
//! PHP: `Tests\Unit\Cache\RedisLeasableCache`, `LeasableHashCache`,
//! `FailingMemory`, `CountingCache`, `Tests\Unit\HashMemoryCache`, and the
//! anonymous `Cache` subclasses tests write to fail or watch a call.

use std::collections::{BTreeMap, HashMap};
use std::future::Future;
use std::sync::{Arc, Mutex, RwLock};

use futures_util::future::BoxFuture;
use indexmap::IndexMap;
use php_std::Value;
use utopia_cache::{Adapter, Cache, Result};

#[derive(Default)]
struct State {
    fields: IndexMap<String, IndexMap<String, (i64, Value)>>,
    versions: HashMap<String, String>,
    failing_field_purges: bool,
    corrupting_field_writes: bool,
    lease_grace_window: i64,
    tombstones: HashMap<String, f64>,
    operations: usize,
    loads: HashMap<String, usize>,
}

/// Intercepts a cache call (key, hash, data): `Some` answers it instead of
/// the store.
type Intercept<T> = Box<dyn Fn(&str, &str, &Value) -> Option<Result<T>> + Send + Sync>;

/// Which save (key, data) a `before_next_*` hook waits for.
type SaveMatcher = Box<dyn Fn(&str, &Value) -> bool + Send + Sync>;

#[derive(Default)]
struct Intercepts {
    load: Option<Intercept<Option<Value>>>,
    save: Option<Intercept<bool>>,
    purge: Option<Intercept<bool>>,
    generation: Option<Intercept<String>>,
    swap: Option<Intercept<Option<String>>>,
}

/// Mirrors how utopia-php/cache's Redis adapters store keys: every key is a
/// hash with an opaque version, which every save replaces and every purge
/// drops (a key without one gets a fresh one when it is read).
///
/// [`Leasable::plain`] plays utopia-cache's `Memory` instead (one value per
/// key whatever the hash, no fields listed, no generations),
/// [`Leasable::lenient`] PHP's `LeasableHashCache`, and the `on_*`
/// intercepts PHP's anonymous cache subclasses that fail or watch a call.
///
/// PHP: `Tests\Unit\Cache\RedisLeasableCache`.
#[derive(Default)]
pub(crate) struct Leasable {
    state: Mutex<State>,
    plain: bool,
    lenient: bool,
    unleased: bool,
    leased: bool,
    json: bool,
    intercepts: RwLock<Intercepts>,
    before_save: Mutex<Option<(SaveMatcher, BoxFuture<'static, ()>)>>,
    before_completion: Mutex<Option<BoxFuture<'static, ()>>>,
}

fn now() -> i64 {
    php_std::datetime::time()
}

fn microtime() -> f64 {
    php_std::datetime::microtime_float()
}

/// PHP's `empty()` of what a cache saves.
fn empty(data: &Value) -> bool {
    match data {
        Value::Str(s) => s.is_empty() || s.as_bytes() == b"0",
        Value::Array(a) => a.is_empty(),
        Value::Null | Value::Bool(false) => true,
        _ => false,
    }
}

impl Leasable {
    /// A new, empty cache adapter.
    pub(crate) fn new() -> Arc<Self> {
        Arc::new(Self::default())
    }

    /// utopia-cache's `Memory`: one value per key, no fields, no
    /// generations.
    pub(crate) fn plain() -> Arc<Self> {
        Arc::new(Leasable { plain: true, ..Self::default() })
    }

    /// PHP's `LeasableHashCache`: saves empty values, and a purge always
    /// answers `true`.
    pub(crate) fn lenient() -> Arc<Self> {
        Arc::new(Leasable { lenient: true, ..Self::default() })
    }

    /// PHP's `HashMemoryCache`: fields, no generations, a purge always
    /// answers `true`.
    pub(crate) fn hash_memory() -> Arc<Self> {
        Arc::new(Leasable { unleased: true, ..Self::default() })
    }

    /// PHP's `JsonHashMemoryCache`: a [`Leasable::hash_memory`] storing each
    /// value as its JSON.
    pub(crate) fn json_hash_memory() -> Arc<Self> {
        Arc::new(Leasable { unleased: true, json: true, ..Self::default() })
    }

    /// utopia-cache's `Memory` with generations (PHP's `MemoryCache`
    /// subclasses implementing `Leasable`).
    pub(crate) fn plain_leased() -> Arc<Self> {
        Arc::new(Leasable { plain: true, leased: true, ..Self::default() })
    }

    /// Runs `future` before the next compare-and-swap of a key containing
    /// `fragment` (PHP's `InterleavingMemory::beforeNextSave()`).
    pub(crate) fn before_next_save(&self, fragment: &str, future: impl Future<Output = ()> + Send + 'static) {
        let fragment = fragment.to_owned();
        *self.before_save.lock().unwrap() =
            Some((Box::new(move |key: &str, _: &Value| key.contains(&fragment)), Box::pin(future)));
    }

    /// Runs `future` before the next completion (a swap of a `#state`
    /// record that drops a writer) compares (PHP's
    /// `OwnershipCache::pauseNextActivation()`).
    pub(crate) fn before_next_activation(&self, future: impl Future<Output = ()> + Send + 'static) {
        *self.before_completion.lock().unwrap() = Some(Box::pin(future));
    }

    /// Answers `compareAndSwap()` calls with `intercept` (key, data, whether
    /// it is a completion) when it returns `Some`.
    pub(crate) fn on_swap(
        &self,
        intercept: impl Fn(&str, &Value, bool) -> Option<Result<Option<String>>> + Send + Sync + 'static,
    ) {
        self.intercepts.write().unwrap().swap = Some(Box::new(move |k, h, d| intercept(k, d, h == "completion")));
    }

    /// `failCompletions()` / `failDuringActivation()`: a completion fails
    /// with `Completion unavailable`.
    pub(crate) fn fail_completions(&self) {
        self.on_swap(|_, _, completion| {
            completion.then(|| {
                Err(utopia_cache::Error::Unavailable { message: "Completion unavailable".to_owned(), previous: None })
            })
        });
    }

    /// `FailingRegistrationCache::failRegistrations()`: the next `count`
    /// registrations (writes of a `#state` record with owners) are refused.
    pub(crate) fn fail_registrations(&self, count: usize) {
        let left = std::sync::atomic::AtomicUsize::new(count);
        self.on_swap(move |key, data, _| {
            let registers = key.ends_with("#state")
                && matches!(data, Value::Array(a) if matches!(a.get_str("owners"), Some(Value::Array(o)) if !o.is_empty()));
            (registers
                && left.fetch_update(std::sync::atomic::Ordering::SeqCst, std::sync::atomic::Ordering::SeqCst, |n| n.checked_sub(1)).is_ok())
            .then(|| Err(utopia_cache::Error::Unavailable { message: "Cache write failed".to_owned(), previous: None }))
        });
    }

    /// Whether a swap of `key` to `data` drops a writer of its `#state`
    /// record (OwnershipCache's `$finishing`).
    fn is_completion(&self, key: &str, data: &Value) -> bool {
        if !key.ends_with("#state") {
            return false;
        }
        let field = self.field(key, "");
        let before = self.state().fields.get(key).and_then(|f| f.get(&field)).map(|(_, v)| v.clone());
        let owners = |v: &Value| match v {
            Value::Array(a) => match a.get_str("owners") {
                Some(Value::Array(o)) => Some(o.len()),
                _ => None,
            },
            _ => None,
        };
        matches!((before.as_ref().and_then(owners), owners(data)), (Some(b), Some(d)) if d < b)
    }

    /// A purge, without the intercepts or the count.
    pub(crate) fn purge_now(&self, key: &str, hash: &str) -> bool {
        let hash = if self.plain { "" } else { hash };
        let mut state = self.state();
        if !hash.is_empty() && state.failing_field_purges {
            return false;
        }
        state.versions.remove(key);
        if state.lease_grace_window > 0 {
            let until = microtime() + state.lease_grace_window as f64 / 1000.0;
            state.tombstones.insert(key.to_owned(), until);
        }
        let removed = if hash.is_empty() {
            state.fields.shift_remove(key).is_some_and(|f| !f.is_empty())
        } else {
            let removed = state.fields.get_mut(key).and_then(|f| f.shift_remove(hash)).is_some();
            if state.fields.get(key).is_some_and(IndexMap::is_empty) {
                state.fields.shift_remove(key);
            }
            removed
        };
        removed || self.lenient || self.unleased || self.leased
    }

    /// A flush, without the count.
    pub(crate) fn flush_now(&self) {
        let mut state = self.state();
        state.fields.clear();
        state.versions.clear();
        state.tombstones.clear();
    }

    /// A key's version, created when it has none, without the intercepts
    /// or the count.
    pub(crate) fn version_now(&self, key: &str) -> String {
        self.state().versions.entry(key.to_owned()).or_insert_with(fresh_version).clone()
    }

    fn state(&self) -> std::sync::MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn field(&self, key: &str, hash: &str) -> String {
        if hash.is_empty() || self.plain { key.to_owned() } else { hash.to_owned() }
    }

    fn save_now(&self, key: &str, data: &Value, hash: &str) -> bool {
        if key.is_empty() || (!self.lenient && empty(data)) {
            return false;
        }
        let field = self.field(key, hash);
        let mut state = self.state();
        let data = if (!hash.is_empty() || key.ends_with("#state")) && state.corrupting_field_writes {
            Value::from("corrupted")
        } else if self.json {
            Value::Str(php_std::Str::from(
                php_std::json::encode(data, php_std::json::Flags::NONE, 512).unwrap_or_default(),
            ))
        } else {
            data.clone()
        };
        state.versions.insert(key.to_owned(), fresh_version());
        state.fields.entry(key.to_owned()).or_default().insert(field, (now(), data));
        true
    }

    fn intercept_save(&self, key: &str, hash: &str, data: &Value) -> Option<Result<bool>> {
        self.intercepts.read().unwrap().save.as_ref().and_then(|i| i(key, hash, data))
    }

    /// Answers `load()` calls with `intercept` when it returns `Some`.
    pub(crate) fn on_load(
        &self,
        intercept: impl Fn(&str, &str) -> Option<Result<Option<Value>>> + Send + Sync + 'static,
    ) {
        self.intercepts.write().unwrap().load = Some(Box::new(move |k, h, _| intercept(k, h)));
    }

    /// Answers `save()` calls (leased or not) with `intercept` when it
    /// returns `Some`.
    pub(crate) fn on_save(
        &self,
        intercept: impl Fn(&str, &str, &Value) -> Option<Result<bool>> + Send + Sync + 'static,
    ) {
        self.intercepts.write().unwrap().save = Some(Box::new(intercept));
    }

    /// Answers `purge()` calls with `intercept` when it returns `Some`.
    pub(crate) fn on_purge(&self, intercept: impl Fn(&str, &str) -> Option<Result<bool>> + Send + Sync + 'static) {
        self.intercepts.write().unwrap().purge = Some(Box::new(move |k, h, _| intercept(k, h)));
    }

    /// Answers `getGeneration()` calls with `intercept` when it returns
    /// `Some`.
    pub(crate) fn on_generation(&self, intercept: impl Fn(&str) -> Option<Result<String>> + Send + Sync + 'static) {
        self.intercepts.write().unwrap().generation = Some(Box::new(move |k, _, _| intercept(k)));
    }

    /// `failBlocks()`: a registration (a `#state` record with owners) is not
    /// saved.
    pub(crate) fn fail_blocks(&self) {
        self.on_save(|key, _, data| {
            (key.ends_with("#state")
                && matches!(data, Value::Array(a) if matches!(a.get_str("owners"), Some(Value::Array(o)) if !o.is_empty())))
            .then_some(Ok(false))
        });
    }

    /// `setLeaseGraceWindow()`.
    pub(crate) fn set_lease_grace_window(&self, milliseconds: i64) {
        self.state().lease_grace_window = milliseconds.max(0);
    }

    /// `expireTombstones()`.
    pub(crate) fn expire_tombstones(&self) {
        self.state().tombstones.clear();
    }

    /// `failFieldPurges()`.
    pub(crate) fn fail_field_purges(&self) {
        self.state().failing_field_purges = true;
    }

    /// `corruptFieldWrites()`.
    pub(crate) fn corrupt_field_writes(&self, corrupting: bool) {
        self.state().corrupting_field_writes = corrupting;
    }

    /// How many operations ran (`CountingCache::getOperations()`).
    pub(crate) fn operations(&self) -> usize {
        self.state().operations
    }
    /// `CountingCache::resetOperations()`.
    pub(crate) fn reset_operations(&self) {
        let mut state = self.state();
        state.operations = 0;
        state.loads.clear();
    }

    fn count(&self) {
        self.state().operations += 1;
    }

    /// `evict()`: drops a key with its generation.
    pub(crate) fn evict(&self, key: &str) {
        let mut state = self.state();
        state.fields.shift_remove(key);
        state.versions.remove(key);
        state.tombstones.remove(key);
    }

    /// `keys()`: every key, including the ones holding only a generation.
    pub(crate) fn keys(&self) -> Vec<String> {
        let state = self.state();
        let mut keys: BTreeMap<String, ()> = state.fields.keys().map(|k| (k.clone(), ())).collect();
        keys.extend(state.versions.keys().map(|k| (k.clone(), ())));
        keys.into_keys().collect()
    }
    /// `countValues()`: every value the cache holds, leaving out generations.
    pub(crate) fn count_values(&self) -> usize {
        self.state().fields.values().map(IndexMap::len).sum()
    }

    /// The raw value of a key's field.
    pub(crate) fn raw(&self, key: &str, hash: &str) -> Option<Value> {
        let field = self.field(key, hash);
        self.state().fields.get(key).and_then(|f| f.get(&field)).map(|(_, v)| v.clone())
    }

    /// Stores a raw value, bypassing the intercepts.
    pub(crate) fn put(&self, key: &str, hash: &str, data: Value) {
        let field = self.field(key, hash);
        self.state().fields.entry(key.to_owned()).or_default().insert(field, (now(), data));
    }
}

impl Adapter for Leasable {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> impl Future<Output = Result<Option<Value>>> + Send {
        {
            let mut state = self.state();
            state.operations += 1;
            *state.loads.entry(key.to_owned()).or_insert(0) += 1;
        }
        let intercepted = self.intercepts.read().unwrap().load.as_ref().and_then(|i| i(key, hash, &Value::Null));
        let result = match intercepted {
            Some(answer) => answer,
            None => {
                let field = self.field(key, hash);
                let state = self.state();
                Ok(match state.fields.get(key).and_then(|f| f.get(&field)) {
                    Some((time, Value::Str(json))) if self.json && time.saturating_add(ttl) > now() => {
                        php_std::json::decode(json.as_bytes(), Some(true), 512, php_std::json::Flags::NONE).ok()
                    }
                    Some((time, data)) if time.saturating_add(ttl) > now() => Some(data.clone()),
                    _ => None,
                })
            }
        };
        std::future::ready(result)
    }

    fn save(&self, key: &str, data: &Value, hash: &str, _ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        self.count();
        std::future::ready(match self.intercept_save(key, hash, data) {
            Some(answer) => answer,
            None => Ok(self.save_now(key, data, hash)),
        })
    }

    fn touch(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
        self.count();
        let field = self.field(key, hash);
        let mut state = self.state();
        let touched = match state.fields.get_mut(key).and_then(|f| f.get_mut(&field)) {
            Some(entry) => {
                entry.0 = now();
                true
            }
            None => false,
        };
        std::future::ready(Ok(touched))
    }

    fn list(&self, key: &str) -> impl Future<Output = Result<Vec<String>>> + Send {
        let mut state = self.state();
        state.operations += 1;
        let fields = if self.plain {
            Vec::new()
        } else {
            state.fields.get(key).map(|f| f.keys().cloned().collect()).unwrap_or_default()
        };
        std::future::ready(Ok(fields))
    }

    fn purge(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
        self.count();
        let intercepted = self.intercepts.read().unwrap().purge.as_ref().and_then(|i| i(key, hash, &Value::Null));
        std::future::ready(intercepted.unwrap_or_else(|| Ok(self.purge_now(key, hash))))
    }

    fn flush(&self) -> impl Future<Output = Result<bool>> + Send {
        self.count();
        self.flush_now();
        std::future::ready(Ok(true))
    }

    async fn ping(&self) -> Result<bool> {
        Ok(true)
    }

    fn size(&self) -> impl Future<Output = Result<i64>> + Send {
        self.count();
        let size = if self.lenient || self.plain { self.state().fields.len() } else { self.keys().len() };
        std::future::ready(Ok(size as i64))
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "redis-leasable"
    }

    fn supports_versioning(&self) -> bool {
        true
    }

    fn version(&self, key: &str) -> impl Future<Output = Result<String>> + Send {
        self.count();
        if let Some(answer) = self.intercepts.read().unwrap().generation.as_ref().and_then(|i| i(key, "", &Value::Null))
        {
            return std::future::ready(answer);
        }
        std::future::ready(Ok(self.version_now(key)))
    }

    fn load_versioned(&self, key: &str) -> impl Future<Output = Result<utopia_cache::Snapshot>> + Send {
        self.count();
        let version = self.version_now(key);
        let field = self.field(key, "");
        let value = self.state().fields.get(key).and_then(|f| f.get(&field)).map(|(_, v)| v.clone());
        let value = match value {
            Some(Value::Str(json)) if self.json => {
                php_std::json::decode(json.as_bytes(), Some(true), 512, php_std::json::Flags::NONE).ok()
            }
            other => other,
        };
        std::future::ready(Ok(utopia_cache::Snapshot { version, value }))
    }

    /// A conditional save: refused while the key's version moved on or a
    /// purge tombstone is in force; a committed save gets a fresh version.
    async fn compare_and_swap(&self, key: &str, data: &Value, hash: &str, version: &str) -> Result<Option<String>> {
        self.count();
        let before = {
            let mut hook = self.before_save.lock().unwrap();
            match hook.as_ref() {
                Some((matches, _)) if matches(key, data) => hook.take().map(|(_, f)| f),
                _ => None,
            }
        };
        if let Some(before) = before {
            before.await;
        }
        let completion = self.is_completion(key, data);
        if completion {
            let paused = self.before_completion.lock().unwrap().take();
            if let Some(paused) = paused {
                paused.await;
            }
        }
        let swapped = self
            .intercepts
            .read()
            .unwrap()
            .swap
            .as_ref()
            .and_then(|i| i(key, if completion { "completion" } else { "" }, data));
        if let Some(answer) = swapped {
            return answer;
        }
        let allowed = {
            let current = self.version_now(key);
            let state = self.state();
            current == version && state.tombstones.get(key).copied().unwrap_or(0.0) <= microtime()
        };
        if !allowed {
            return Ok(None);
        }
        // A refused save fails the swap, as PHP's `Memory` (`Unable to save
        // cache snapshot`) and `RedisLeasableCache` (`Cache write failed`)
        // fail it.
        let saved = match self.intercept_save(key, hash, data) {
            Some(answer) => answer?,
            None => self.save_now(key, data, hash),
        };
        if !saved {
            let message = if self.plain { "Unable to save cache snapshot" } else { "Cache write failed" };
            return Err(utopia_cache::Error::Unavailable { message: message.to_owned(), previous: None });
        }
        Ok(Some(self.version_now(key)))
    }
}

/// `bin2hex(random_bytes(16))`: a fresh opaque version.
fn fresh_version() -> String {
    let mut bytes = [0u8; 16];
    getrandom::fill(&mut bytes).expect("random bytes");
    hex::encode(bytes)
}

/// A cache over a [`Leasable`] adapter, and the adapter.
pub(crate) fn leasable() -> (Arc<Leasable>, Arc<Cache>) {
    let adapter = Leasable::new();
    (adapter.clone(), Arc::new(Cache::from_dyn(adapter)))
}

/// A cache object over `adapter` (PHP's `new Cache($adapter)`).
pub(crate) fn over(adapter: &Arc<Leasable>) -> Arc<Cache> {
    Arc::new(Cache::from_dyn(adapter.clone()))
}

/// A cache over utopia-cache's `Memory` adapter (no fields, no generations).
pub(crate) fn memory() -> Arc<Cache> {
    Arc::new(Cache::from_dyn(Arc::new(utopia_cache::Memory::new())))
}
