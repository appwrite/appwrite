use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;

use utopia_cache::adapter::Memory;
use utopia_cache::feature::Telemetry;
use utopia_cache::{Adapter, Cache, CacheError, CacheValue, LoadResult, SaveResult};
use utopia_telemetry::adapters::NoneAdapter;
use utopia_telemetry::{Adapter as TelemetryAdapter, TestAdapter};

struct Probe {
    inner: Memory,
    telemetry: parking_lot::Mutex<Option<Arc<dyn TelemetryAdapter>>>,
    calls: Arc<AtomicUsize>,
}

impl Probe {
    fn new(calls: Arc<AtomicUsize>) -> Self {
        Self {
            inner: Memory::new(),
            telemetry: parking_lot::Mutex::new(None),
            calls,
        }
    }
}

impl Adapter for Probe {
    fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError> {
        self.inner.load(key, ttl, hash)
    }
    fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError> {
        self.inner.save(key, data, hash)
    }
    fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        self.inner.touch(key, hash)
    }
    fn list(&self, key: &str) -> Result<Vec<String>, CacheError> {
        self.inner.list(key)
    }
    fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        self.inner.purge(key, hash)
    }
    fn flush(&self) -> Result<bool, CacheError> {
        self.inner.flush()
    }
    fn ping(&self) -> bool {
        self.inner.ping()
    }
    fn get_size(&self) -> Result<i64, CacheError> {
        self.inner.get_size()
    }
    fn get_name(&self, key: Option<&str>) -> String {
        self.inner.get_name(key)
    }
    fn as_telemetry_mut(&mut self) -> Option<&mut dyn Telemetry> {
        Some(self)
    }
}

impl Telemetry for Probe {
    fn set_telemetry(&mut self, telemetry: Arc<dyn TelemetryAdapter>) {
        *self.telemetry.lock() = Some(telemetry);
        self.calls.fetch_add(1, Ordering::SeqCst);
    }
}

#[test]
fn cache_propagates_telemetry_to_adapter() {
    let calls = Arc::new(AtomicUsize::new(0));
    let probe = Probe::new(Arc::clone(&calls));
    let telemetry: Arc<dyn TelemetryAdapter> = Arc::new(NoneAdapter::new());
    let mut cache = Cache::new(probe);
    cache.set_telemetry(Arc::clone(&telemetry));
    assert_eq!(calls.load(Ordering::SeqCst), 1);
}

struct CapturingMemory;

impl Adapter for CapturingMemory {
    fn load(&self, _key: &str, _ttl: i64, _hash: &str) -> Result<LoadResult, CacheError> {
        Ok(LoadResult::Hit(CacheValue::Null))
    }
    fn save(&self, _k: &str, _d: &CacheValue, _h: &str) -> Result<SaveResult, CacheError> {
        Ok(SaveResult::Failed)
    }
    fn touch(&self, _k: &str, _h: &str) -> Result<bool, CacheError> {
        Ok(false)
    }
    fn list(&self, _k: &str) -> Result<Vec<String>, CacheError> {
        Ok(vec![])
    }
    fn purge(&self, _k: &str, _h: &str) -> Result<bool, CacheError> {
        Ok(false)
    }
    fn flush(&self) -> Result<bool, CacheError> {
        Ok(false)
    }
    fn ping(&self) -> bool {
        true
    }
    fn get_size(&self) -> Result<i64, CacheError> {
        Ok(0)
    }
    fn get_name(&self, _k: Option<&str>) -> String {
        "memory".into()
    }
}

#[test]
fn load_emits_hit_and_miss_counts() {
    let mut cache = Cache::new(Memory::new());
    let telemetry = Arc::new(TestAdapter::new());
    let adapter: Arc<dyn TelemetryAdapter> = telemetry.clone();
    cache.set_telemetry(adapter);

    cache.load("missing", 60, "").unwrap();
    cache.save("present", "value", "").unwrap();
    cache.load("present", 60, "").unwrap();
    cache.load("present", 60, "").unwrap();

    let values = telemetry.counter_measurements("cache.load.total");
    assert_eq!(values.len(), 3);
}

#[test]
fn hit_miss_attributes_are_recorded() {
    let mut cache = Cache::new(Memory::new());
    let telemetry = Arc::new(TestAdapter::new());
    let adapter: Arc<dyn TelemetryAdapter> = telemetry.clone();
    cache.set_telemetry(adapter);

    cache.load("absent", 60, "").unwrap();
    cache.save("here", "value", "").unwrap();
    cache.load("here", 60, "").unwrap();

    let results: Vec<String> = telemetry
        .counter_measurements("cache.load.total")
        .into_iter()
        .map(|m| m.attributes.get("result").cloned().unwrap_or_default())
        .collect();
    assert_eq!(results, vec!["miss".to_string(), "hit".to_string()]);
}

#[test]
fn null_return_is_treated_as_hit() {
    let mut cache = Cache::new(CapturingMemory);
    let telemetry = Arc::new(TestAdapter::new());
    let adapter: Arc<dyn TelemetryAdapter> = telemetry.clone();
    cache.set_telemetry(adapter);
    cache.load("any", 60, "").unwrap();
    let result = telemetry.counter_measurements("cache.load.total")[0]
        .attributes
        .get("result")
        .cloned()
        .unwrap();
    assert_eq!(result, "hit");
}
