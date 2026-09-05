use std::collections::HashMap;
use std::sync::Arc;
use std::time::Instant;

use parking_lot::Mutex;
use utopia_telemetry::adapters::NoneAdapter;
use utopia_telemetry::{attrs, Adapter as TelemetryAdapter, Counter, Histogram};

use crate::adapter::Adapter;
use crate::error::CacheError;
use crate::value::{CacheValue, LoadResult, SaveResult};

const DURATION_NAME: &str = "cache.operation.duration";
const LOAD_TOTAL_NAME: &str = "cache.load.total";
const LOAD_TOTAL_DESC: &str = "Cache load operations broken down by hit/miss result.";

/// PHP `Utopia\Cache\Cache`.
pub struct Cache {
    adapter: Box<dyn Adapter>,
    /// PHP `$caseSensitive` (public).
    pub case_sensitive: bool,
    telemetry: Arc<dyn TelemetryAdapter>,
    operation_duration: Mutex<Option<Arc<dyn Histogram>>>,
    load_results: Mutex<Option<Arc<dyn Counter>>>,
}

impl std::fmt::Debug for Cache {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Cache")
            .field("case_sensitive", &self.case_sensitive)
            .finish_non_exhaustive()
    }
}

impl Cache {
    /// PHP `__construct(Adapter $adapter)`. Default telemetry is `None`.
    #[must_use]
    pub fn new(adapter: impl Adapter + 'static) -> Self {
        Self::from_boxed(Box::new(adapter))
    }

    #[must_use]
    pub fn from_boxed(adapter: Box<dyn Adapter>) -> Self {
        Self {
            adapter,
            case_sensitive: false,
            telemetry: Arc::new(NoneAdapter::new()),
            operation_duration: Mutex::new(None),
            load_results: Mutex::new(None),
        }
    }

    /// PHP `setTelemetry`. Instruments are created lazily on first use.
    pub fn set_telemetry(&mut self, telemetry: Arc<dyn TelemetryAdapter>) {
        self.telemetry = telemetry.clone();
        *self.operation_duration.lock() = None;
        *self.load_results.lock() = None;
        if let Some(inner) = self.adapter.as_telemetry_mut() {
            inner.set_telemetry(telemetry);
        }
    }

    fn get_operation_duration(&self) -> Arc<dyn Histogram> {
        let mut slot = self.operation_duration.lock();
        if let Some(h) = slot.as_ref() {
            return Arc::clone(h);
        }
        let mut advisory = HashMap::new();
        advisory.insert(
            "ExplicitBucketBoundaries".into(),
            "[0.001,0.005,0.01,0.025,0.05,0.1,0.25,0.5,1]".into(),
        );
        let hist = self
            .telemetry
            .create_histogram(DURATION_NAME, Some("s"), None, advisory);
        *slot = Some(Arc::clone(&hist));
        hist
    }

    fn get_load_results(&self) -> Arc<dyn Counter> {
        let mut slot = self.load_results.lock();
        if let Some(c) = slot.as_ref() {
            return Arc::clone(c);
        }
        let counter = self.telemetry.create_counter(
            LOAD_TOTAL_NAME,
            None,
            Some(LOAD_TOTAL_DESC),
            HashMap::new(),
        );
        *slot = Some(Arc::clone(&counter));
        counter
    }

    fn normalize<'a>(&self, value: &'a str) -> std::borrow::Cow<'a, str> {
        if self.case_sensitive {
            std::borrow::Cow::Borrowed(value)
        } else {
            std::borrow::Cow::Owned(value.to_lowercase())
        }
    }

    /// PHP `setCaseSensitivity`. Returns the assigned value.
    pub fn set_case_sensitivity(&mut self, value: bool) -> bool {
        self.case_sensitive = value;
        value
    }

    fn record_duration(&self, start: Instant, operation: &str, key: Option<&str>) {
        let duration = start.elapsed().as_secs_f64();
        let adapter_name = self.adapter.get_name(key);
        self.get_operation_duration().record(
            duration,
            &attrs(&[("operation", operation), ("adapter", adapter_name.as_str())]),
        );
    }

    /// PHP `load($key, $ttl, $hash = '')`.
    pub fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError> {
        let key = self.normalize(key);
        let hash = self.normalize(hash);
        let start = Instant::now();
        let result = self.adapter.load(key.as_ref(), ttl, hash.as_ref())?;
        self.record_duration(start, "load", Some(key.as_ref()));
        let adapter_name = self.adapter.get_name(Some(key.as_ref()));
        let hit_or_miss = if result.is_miss() { "miss" } else { "hit" };
        self.get_load_results().add(
            1.0,
            &attrs(&[("adapter", adapter_name.as_str()), ("result", hit_or_miss)]),
        );
        Ok(result)
    }

    /// PHP `save($key, $data, $hash = '')`.
    pub fn save(
        &self,
        key: &str,
        data: impl Into<CacheValue>,
        hash: &str,
    ) -> Result<SaveResult, CacheError> {
        let key = self.normalize(key);
        let hash = self.normalize(hash);
        let data = data.into();
        let start = Instant::now();
        let result = self.adapter.save(key.as_ref(), &data, hash.as_ref());
        self.record_duration(start, "save", Some(key.as_ref()));
        result
    }

    /// PHP `getGeneration($key)`. Returns `"0"` when the adapter is not leasable.
    pub fn get_generation(&self, key: &str) -> Result<String, CacheError> {
        match self.adapter.as_leasable() {
            Some(leasable) => {
                let key = self.normalize(key);
                leasable.get_generation(key.as_ref())
            }
            None => Ok("0".into()),
        }
    }

    /// PHP `saveWithLease($key, $data, $hash, $generation)`.
    pub fn save_with_lease(
        &self,
        key: &str,
        data: impl Into<CacheValue>,
        hash: &str,
        generation: &str,
    ) -> Result<SaveResult, CacheError> {
        let key = self.normalize(key);
        let hash = self.normalize(hash);
        let data = data.into();
        let start = Instant::now();
        let result = if let Some(leasable) = self.adapter.as_leasable() {
            leasable.save_with_lease(key.as_ref(), &data, hash.as_ref(), generation)
        } else {
            self.adapter.save(key.as_ref(), &data, hash.as_ref())
        };
        self.record_duration(start, "saveWithLease", Some(key.as_ref()));
        result
    }

    /// PHP `touch($key, $hash = '')`.
    pub fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        let key = self.normalize(key);
        let hash = self.normalize(hash);
        let start = Instant::now();
        let result = self.adapter.touch(key.as_ref(), hash.as_ref());
        self.record_duration(start, "touch", Some(key.as_ref()));
        result
    }

    /// PHP `list($key)`.
    pub fn list(&self, key: &str) -> Result<Vec<String>, CacheError> {
        let key = self.normalize(key);
        let start = Instant::now();
        let result = self.adapter.list(key.as_ref());
        self.record_duration(start, "list", Some(key.as_ref()));
        result
    }

    /// PHP `purge($key, $hash = '')`.
    pub fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError> {
        let key = self.normalize(key);
        let hash = self.normalize(hash);
        let start = Instant::now();
        let result = self.adapter.purge(key.as_ref(), hash.as_ref());
        self.record_duration(start, "purge", Some(key.as_ref()));
        result
    }

    /// PHP `flush()`.
    pub fn flush(&self) -> Result<bool, CacheError> {
        let start = Instant::now();
        let result = self.adapter.flush();
        self.record_duration(start, "flush", None);
        result
    }

    /// PHP `ping()`.
    #[must_use]
    pub fn ping(&self) -> bool {
        self.adapter.ping()
    }

    /// PHP `getSize()`.
    pub fn get_size(&self) -> Result<i64, CacheError> {
        let start = Instant::now();
        let result = self.adapter.get_size();
        self.record_duration(start, "size", None);
        result
    }
}
