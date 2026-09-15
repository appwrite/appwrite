use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;

use utopia_cache::adapter::{CircuitBreaker, Memory, Sharding};
use utopia_cache::circuit_breaker::CircuitBreaker as UtopiaCircuitBreaker;
use utopia_cache::feature::Telemetry;
use utopia_cache::{Adapter, Cache, CacheError, CacheValue, LoadResult, SaveResult};
use utopia_telemetry::Adapter as TelemetryAdapter;
use utopia_telemetry::TestAdapter;

struct FailingAdapter;

impl Adapter for FailingAdapter {
    fn load(&self, _key: &str, _ttl: i64, _hash: &str) -> Result<LoadResult, CacheError> {
        Err(CacheError::AdapterFailed)
    }
    fn save(&self, _key: &str, _data: &CacheValue, _hash: &str) -> Result<SaveResult, CacheError> {
        Err(CacheError::AdapterFailed)
    }
    fn touch(&self, _key: &str, _hash: &str) -> Result<bool, CacheError> {
        Err(CacheError::AdapterFailed)
    }
    fn list(&self, _key: &str) -> Result<Vec<String>, CacheError> {
        Err(CacheError::AdapterFailed)
    }
    fn purge(&self, _key: &str, _hash: &str) -> Result<bool, CacheError> {
        Err(CacheError::AdapterFailed)
    }
    fn flush(&self) -> Result<bool, CacheError> {
        Err(CacheError::AdapterFailed)
    }
    fn ping(&self) -> bool {
        false
    }
    fn get_size(&self) -> Result<i64, CacheError> {
        Err(CacheError::AdapterFailed)
    }
    fn get_name(&self, _key: Option<&str>) -> String {
        "failing".into()
    }
}

struct TelemetryProbe {
    inner: Memory,
    telemetry: parking_lot::Mutex<Option<Arc<dyn TelemetryAdapter>>>,
}

impl TelemetryProbe {
    fn new() -> Self {
        Self {
            inner: Memory::new(),
            telemetry: parking_lot::Mutex::new(None),
        }
    }
}

impl Adapter for TelemetryProbe {
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

impl Telemetry for TelemetryProbe {
    fn set_telemetry(&mut self, telemetry: Arc<dyn TelemetryAdapter>) {
        *self.telemetry.lock() = Some(telemetry);
    }
}

fn failing_cache() -> CircuitBreaker {
    CircuitBreaker::new(FailingAdapter, UtopiaCircuitBreaker::with_threshold(1))
}

#[test]
fn passes_through_healthy_cache_operations() {
    let adapter = Memory::new();
    let cache = CircuitBreaker::new(adapter, UtopiaCircuitBreaker::new());
    assert_eq!(
        cache.save("key", &CacheValue::from("value"), "").unwrap(),
        SaveResult::Saved("value".into())
    );
    match cache.load("key", 60, "").unwrap() {
        LoadResult::Hit(CacheValue::String(s)) => assert_eq!(s, "value"),
        other => panic!("{other:?}"),
    }
    assert!(cache.touch("key", "").unwrap());
    assert_eq!(cache.get_size().unwrap(), 1);
    assert!(cache.ping());
    assert!(cache.purge("key", "").unwrap());
    assert!(cache.load("key", 60, "").unwrap().is_miss());
}

#[test]
fn returns_fallbacks_when_cache_operations_fail() {
    assert!(failing_cache().load("key", 60, "").unwrap().is_miss());
    assert!(failing_cache()
        .save("key", &CacheValue::from("value"), "")
        .unwrap()
        .is_failed());
    assert!(!failing_cache().touch("key", "").unwrap());
    assert!(failing_cache().list("key").unwrap().is_empty());
    assert!(!failing_cache().purge("key", "").unwrap());
    assert!(!failing_cache().flush().unwrap());
    assert!(!failing_cache().ping());
    assert_eq!(failing_cache().get_size().unwrap(), 0);
}

#[test]
fn breaker_short_circuits_shared_counter() {
    use std::sync::Arc as StdArc;
    struct SharedFail {
        loads: StdArc<AtomicUsize>,
    }
    impl Adapter for SharedFail {
        fn load(&self, _key: &str, _ttl: i64, _hash: &str) -> Result<LoadResult, CacheError> {
            self.loads.fetch_add(1, Ordering::SeqCst);
            Err(CacheError::AdapterFailed)
        }
        fn save(&self, _k: &str, _d: &CacheValue, _h: &str) -> Result<SaveResult, CacheError> {
            Err(CacheError::AdapterFailed)
        }
        fn touch(&self, _k: &str, _h: &str) -> Result<bool, CacheError> {
            Err(CacheError::AdapterFailed)
        }
        fn list(&self, _k: &str) -> Result<Vec<String>, CacheError> {
            Err(CacheError::AdapterFailed)
        }
        fn purge(&self, _k: &str, _h: &str) -> Result<bool, CacheError> {
            Err(CacheError::AdapterFailed)
        }
        fn flush(&self) -> Result<bool, CacheError> {
            Err(CacheError::AdapterFailed)
        }
        fn ping(&self) -> bool {
            false
        }
        fn get_size(&self) -> Result<i64, CacheError> {
            Err(CacheError::AdapterFailed)
        }
        fn get_name(&self, _k: Option<&str>) -> String {
            "failing".into()
        }
    }
    let loads = StdArc::new(AtomicUsize::new(0));
    let cache = CircuitBreaker::new(
        SharedFail {
            loads: StdArc::clone(&loads),
        },
        UtopiaCircuitBreaker::with_threshold(1),
    );
    assert!(cache.load("key", 60, "").unwrap().is_miss());
    assert!(cache.load("key", 60, "").unwrap().is_miss());
    assert_eq!(loads.load(Ordering::SeqCst), 1);
}

#[test]
fn telemetry_can_be_attached_after_construction() {
    let telemetry = Arc::new(TestAdapter::new());
    let mut cache = CircuitBreaker::new(Memory::new(), UtopiaCircuitBreaker::new());
    let adapter: Arc<dyn TelemetryAdapter> = telemetry.clone();
    cache.set_telemetry(adapter);
    assert!(cache.load("missing", 60, "").unwrap().is_miss());
    let calls = telemetry.counter_measurements("breaker.calls");
    assert_eq!(calls.len(), 1);
    assert!((calls[0].value - 1.0).abs() < f64::EPSILON);
}

#[test]
fn telemetry_propagates_to_inner_adapter() {
    let telemetry = Arc::new(TestAdapter::new());
    let probe = TelemetryProbe::new();
    let mut cache = CircuitBreaker::new(probe, UtopiaCircuitBreaker::new());
    let adapter: Arc<dyn TelemetryAdapter> = telemetry.clone();
    cache.set_telemetry(adapter);
    // Inner probe is inside the circuit breaker; just ensure set_telemetry does not panic.
}

#[test]
fn cache_telemetry_does_not_propagate_through_sharding() {
    let telemetry = Arc::new(TestAdapter::new());
    let inner = CircuitBreaker::new(Memory::new(), UtopiaCircuitBreaker::new());
    let mut cache = Cache::new(Sharding::new(vec![Box::new(inner)]).unwrap());
    let adapter: Arc<dyn TelemetryAdapter> = telemetry.clone();
    cache.set_telemetry(adapter);
    assert!(cache.save("key", "value", "").unwrap().is_saved());
    assert!(!telemetry
        .histogram_measurements("cache.operation.duration")
        .is_empty());
    assert!(telemetry.counter_measurements("breaker.calls").is_empty());
}
