//! Cache adapters PHP's cache tests use.
//!
//! PHP: `Tests\Unit\Cache\RedisLeasableCache`.

use std::collections::{BTreeMap, HashMap};
use std::future::Future;
use std::sync::{Arc, Mutex};

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
}

/// Mirrors how utopia-php/cache's Redis adapters store keys: every key is a
/// hash whose generation is a reserved field, and purging a key or one of
/// its fields advances that generation instead of deleting the key.
///
/// PHP: `Tests\Unit\Cache\RedisLeasableCache`.
#[derive(Default)]
pub(crate) struct Leasable {
    state: Mutex<State>,
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

    fn state(&self) -> std::sync::MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn field(key: &str, hash: &str) -> String {
        if hash.is_empty() { key.to_owned() } else { hash.to_owned() }
    }

    fn save_now(&self, key: &str, data: &Value, hash: &str) -> bool {
        if key.is_empty() || empty(data) {
            return false;
        }
        let mut state = self.state();
        let data =
            if !hash.is_empty() && state.corrupting_field_writes { Value::from("corrupted") } else { data.clone() };
        state.fields.entry(key.to_owned()).or_default().insert(Self::field(key, hash), (now(), data));
        true
    }

    /// `setLeaseGraceWindow()`.
    #[allow(dead_code)]
    pub(crate) fn set_lease_grace_window(&self, milliseconds: i64) {
        self.state().lease_grace_window = milliseconds.max(0);
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

    /// `countValues()`: every value the cache holds, leaving out generations.
    pub(crate) fn count_values(&self) -> usize {
        self.state().fields.values().map(IndexMap::len).sum()
    }

    /// The raw value of a key's field.
    pub(crate) fn raw(&self, key: &str, hash: &str) -> Option<Value> {
        self.state().fields.get(key).and_then(|f| f.get(&Self::field(key, hash))).map(|(_, v)| v.clone())
    }
}

impl Adapter for Leasable {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> impl Future<Output = Result<Option<Value>>> + Send {
        let state = self.state();
        let saved = state.fields.get(key).and_then(|f| f.get(&Self::field(key, hash)));
        let result = match saved {
            Some((time, data)) if time.saturating_add(ttl) > now() => Some(data.clone()),
            _ => None,
        };
        async move { Ok(result) }
    }

    fn save(&self, key: &str, data: &Value, hash: &str, _ttl: i64) -> impl Future<Output = Result<bool>> + Send {
        let saved = self.save_now(key, data, hash);
        async move { Ok(saved) }
    }

    fn touch(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
        let mut state = self.state();
        let touched = match state.fields.get_mut(key).and_then(|f| f.get_mut(&Self::field(key, hash))) {
            Some(entry) => {
                entry.0 = now();
                true
            }
            None => false,
        };
        async move { Ok(touched) }
    }

    fn list(&self, key: &str) -> impl Future<Output = Result<Vec<String>>> + Send {
        {
            let mut state = self.state();
            state.lists += 1;
            if state.flush_on_next_list {
                state.flush_on_next_list = false;
                state.fields.clear();
                state.generations.clear();
                state.tombstones.clear();
            }
        }
        let fields = self.state().fields.get(key).map(|f| f.keys().cloned().collect()).unwrap_or_default();
        async move { Ok(fields) }
    }

    fn purge(&self, key: &str, hash: &str) -> impl Future<Output = Result<bool>> + Send {
        let mut state = self.state();
        let purged = if !hash.is_empty() && state.failing_field_purges {
            false
        } else {
            *state.generations.entry(key.to_owned()).or_insert(0) += 1;
            if state.lease_grace_window > 0 {
                let until = microtime() + state.lease_grace_window as f64 / 1000.0;
                state.tombstones.insert(key.to_owned(), until);
            }
            if hash.is_empty() {
                state.fields.shift_remove(key).is_some_and(|f| !f.is_empty())
            } else {
                let removed = state.fields.get_mut(key).and_then(|f| f.shift_remove(hash)).is_some();
                if state.fields.get(key).is_some_and(IndexMap::is_empty) {
                    state.fields.shift_remove(key);
                }
                removed
            }
        };
        async move { Ok(purged) }
    }

    fn flush(&self) -> impl Future<Output = Result<bool>> + Send {
        let mut state = self.state();
        state.fields.clear();
        state.generations.clear();
        state.tombstones.clear();
        async { Ok(true) }
    }

    async fn ping(&self) -> Result<bool> {
        Ok(true)
    }

    fn size(&self) -> impl Future<Output = Result<i64>> + Send {
        let size = self.keys().len() as i64;
        async move { Ok(size) }
    }

    fn name(&self, _key: Option<&str>) -> &str {
        "redis-leasable"
    }

    fn leasable(&self) -> bool {
        true
    }

    fn generation(&self, key: &str) -> impl Future<Output = Result<String>> + Send {
        let generation = self.state().generations.get(key).copied().unwrap_or(0).to_string();
        async move { Ok(generation) }
    }

    fn save_with_lease(
        &self,
        key: &str,
        data: &Value,
        hash: &str,
        generation: &str,
    ) -> impl Future<Output = Result<bool>> + Send {
        let saved = {
            let mut state = self.state();
            let current = state.generations.get(key).copied().unwrap_or(0).to_string();
            if current != generation || state.tombstones.get(key).copied().unwrap_or(0.0) > microtime() {
                None
            } else {
                state.tombstones.remove(key);
                Some(())
            }
        };
        let saved = saved.is_some() && self.save_now(key, data, hash);
        async move { Ok(saved) }
    }
}

/// A cache over a [`Leasable`] adapter, and the adapter.
pub(crate) fn leasable() -> (Arc<Leasable>, Arc<Cache>) {
    let adapter = Leasable::new();
    (adapter.clone(), Arc::new(Cache::from_dyn(adapter)))
}

/// A second cache object over the same adapter (PHP's `new Cache($adapter)`).
pub(crate) fn over(adapter: &Arc<Leasable>) -> Arc<Cache> {
    Arc::new(Cache::from_dyn(adapter.clone()))
}

/// A cache over utopia-cache's `Memory` adapter (no fields, no generations).
pub(crate) fn memory() -> Arc<Cache> {
    Arc::new(Cache::from_dyn(Arc::new(utopia_cache::Memory::new())))
}
