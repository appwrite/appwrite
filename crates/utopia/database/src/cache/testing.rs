//! Cache adapters PHP's cache tests use.
//!
//! PHP: `Tests\Unit\Cache\RedisLeasableCache`, `LeasableHashCache`,
//! `FailingMemory`, `CountingCache`, `Tests\Unit\HashMemoryCache`, and the
//! anonymous `Cache` subclasses tests write to fail or watch a call.

use std::collections::{BTreeMap, HashMap};
use std::future::Future;
use std::sync::{Arc, Mutex, RwLock};

use indexmap::IndexMap;
use php_std::Value;
use utopia_cache::{Adapter, Cache, Result};

#[derive(Default)]
struct State {
    fields: IndexMap<String, IndexMap<String, (i64, Value)>>,
    generations: HashMap<String, i64>,
    failing_field_purges: bool,
    corrupting_field_writes: bool,
    lease_grace_window: i64,
    tombstones: HashMap<String, f64>,
    lists: usize,
    flush_on_next_list: bool,
    operations: usize,
    loads: HashMap<String, usize>,
}

/// Intercepts a cache call (key, hash, data): `Some` answers it instead of
/// the store.
type Intercept<T> = Box<dyn Fn(&str, &str, &Value) -> Option<Result<T>> + Send + Sync>;

#[derive(Default)]
struct Intercepts {
    load: Option<Intercept<Option<Value>>>,
    save: Option<Intercept<bool>>,
    purge: Option<Intercept<bool>>,
    generation: Option<Intercept<String>>,
}

/// Mirrors how utopia-php/cache's Redis adapters store keys: every key is a
/// hash whose generation is a reserved field, and purging a key or one of
/// its fields advances that generation instead of deleting the key.
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
    intercepts: RwLock<Intercepts>,
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
        let data =
            if !hash.is_empty() && state.corrupting_field_writes { Value::from("corrupted") } else { data.clone() };
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
    pub(crate) fn on_save(&self, intercept: impl Fn(&str, &str, &Value) -> Option<Result<bool>> + Send + Sync + 'static) {
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

    /// `failActivations()`: an active epoch is not saved.
    pub(crate) fn fail_activations(&self) {
        self.on_save(|key, _, data| {
            (key.ends_with("#epoch") && matches!(data, Value::Str(s) if s.as_bytes().starts_with(b"active:")))
                .then_some(Ok(false))
        });
    }

    /// `failBlocks()`: a tombstone is not saved.
    pub(crate) fn fail_blocks(&self) {
        self.on_save(|key, _, data| {
            (key.ends_with("#epoch") && matches!(data, Value::Str(s) if s.as_bytes().starts_with(b"blocked:")))
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

    /// How many times the fields of a key were listed.
    pub(crate) fn lists(&self) -> usize {
        self.state().lists
    }

    /// Flushes everything on the next list (PHP's test cache that flushes
    /// before its first list).
    pub(crate) fn flush_on_next_list(&self) {
        self.state().flush_on_next_list = true;
    }

    /// How many operations ran (`CountingCache::getOperations()`).
    pub(crate) fn operations(&self) -> usize {
        self.state().operations
    }

    /// The loads of each key (`CountingCache::getLoads()`).
    pub(crate) fn loads(&self) -> HashMap<String, usize> {
        self.state().loads.clone()
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
        state.generations.remove(key);
        state.tombstones.remove(key);
    }

    /// `keys()`: every key, including the ones holding only a generation.
    pub(crate) fn keys(&self) -> Vec<String> {
        let state = self.state();
        let mut keys: BTreeMap<String, ()> = state.fields.keys().map(|k| (k.clone(), ())).collect();
        keys.extend(state.generations.keys().map(|k| (k.clone(), ())));
        keys.into_keys().collect()
    }

    /// The keys holding a value.
    pub(crate) fn stored_keys(&self) -> Vec<String> {
        self.state().fields.keys().cloned().collect()
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
                    Some((time, data)) if time.saturating_add(ttl) > now() => Some(data.clone()),
                    _ => None,
                })
            }
        };
        std::future::ready(result)
    }

    fn save(&self, key: &str, data: &Value, hash: &str, _ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        self.count();
        let saved = match self.intercept_save(key, hash, data) {
            Some(answer) => answer,
            None => Ok(self.save_now(key, data, hash)),
        };
        std::future::ready(saved)
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
        state.lists += 1;
        state.operations += 1;
        if state.flush_on_next_list {
            state.flush_on_next_list = false;
            state.fields.clear();
            state.generations.clear();
            state.tombstones.clear();
        }
        let fields = if self.plain {
            Vec::new()
        } else {
            state.fields.get(key).map(|f| f.keys().cloned().collect()).unwrap_or_default()
        };
        std::future::ready(Ok(fields))
    }

    fn purge(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
        self.count();
        if let Some(answer) = self.intercepts.read().unwrap().purge.as_ref().and_then(|i| i(key, hash, &Value::Null)) {
            return std::future::ready(answer);
        }
        let hash = if self.plain { "" } else { hash };
        let mut state = self.state();
        if !hash.is_empty() && state.failing_field_purges {
            return std::future::ready(Ok(false));
        }
        if !self.plain {
            *state.generations.entry(key.to_owned()).or_insert(0) += 1;
        }
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
        std::future::ready(Ok(removed || self.lenient))
    }

    fn flush(&self) -> impl Future<Output = Result<bool>> + Send {
        self.count();
        let mut state = self.state();
        state.fields.clear();
        state.generations.clear();
        state.tombstones.clear();
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

    fn leasable(&self) -> bool {
        !self.plain
    }

    fn generation(&self, key: &str) -> impl Future<Output = Result<String>> + Send {
        self.count();
        if let Some(answer) =
            self.intercepts.read().unwrap().generation.as_ref().and_then(|i| i(key, "", &Value::Null))
        {
            return std::future::ready(answer);
        }
        let generation = self.state().generations.get(key).copied().unwrap_or(0).to_string();
        std::future::ready(Ok(generation))
    }

    fn save_with_lease(
        &self,
        key: &str,
        data: &Value,
        hash: &str,
        generation: &str,
    ) -> impl Future<Output = Result<bool>> + Send {
        self.count();
        if let Some(answer) = self.intercept_save(key, hash, data) {
            return std::future::ready(answer);
        }
        let allowed = {
            let mut state = self.state();
            let current = state.generations.get(key).copied().unwrap_or(0).to_string();
            if current != generation || state.tombstones.get(key).copied().unwrap_or(0.0) > microtime() {
                false
            } else {
                state.tombstones.remove(key);
                true
            }
        };
        std::future::ready(Ok(allowed && self.save_now(key, data, hash)))
    }
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
