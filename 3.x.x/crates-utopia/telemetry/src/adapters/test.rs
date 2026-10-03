use std::collections::HashMap;
use std::fmt;
use std::sync::Arc;

use parking_lot::Mutex;

use crate::instrument::ObserveCallback;
use crate::{
    adapter::Adapter, Advisory, Attributes, Counter, Gauge, Histogram, ObservableGauge,
    UpDownCounter,
};

/// A single recorded measurement with attributes.
#[derive(Debug, Clone, PartialEq)]
pub struct RecordedMeasurement {
    pub value: f64,
    pub attributes: HashMap<String, String>,
}

/// Point-in-time snapshot of all measurements recorded by a [`TestAdapter`].
///
/// Instruments appear here on first write, matching PHP `Adapter\Test` and the
/// OpenTelemetry adapter: an unused instrument is never exported.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct TestSnapshot {
    pub counters: HashMap<String, Vec<RecordedMeasurement>>,
    pub histograms: HashMap<String, Vec<RecordedMeasurement>>,
    pub gauges: HashMap<String, Vec<RecordedMeasurement>>,
    pub up_down_counters: HashMap<String, Vec<RecordedMeasurement>>,
}

type Series = Arc<Mutex<Vec<RecordedMeasurement>>>;

#[derive(Default)]
struct TestState {
    counters: HashMap<String, Series>,
    histograms: HashMap<String, Series>,
    gauges: HashMap<String, Series>,
    up_down_counters: HashMap<String, Series>,
    observable_gauges: HashMap<String, Arc<TestObservableGauge>>,
    unobserved_gauges: HashMap<String, Arc<TestObservableGauge>>,
}

/// In-memory telemetry adapter for tests and assertions.
#[derive(Default)]
pub struct TestAdapter {
    state: Arc<Mutex<TestState>>,
}

impl fmt::Debug for TestAdapter {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("TestAdapter").finish_non_exhaustive()
    }
}

impl TestAdapter {
    pub fn new() -> Self {
        Self::default()
    }

    fn snapshot_kind(map: &HashMap<String, Series>) -> HashMap<String, Vec<RecordedMeasurement>> {
        map.iter()
            .map(|(k, v)| (k.clone(), v.lock().clone()))
            .collect()
    }

    /// Returns a snapshot of instruments that have been written to.
    pub fn snapshot(&self) -> TestSnapshot {
        let state = self.state.lock();
        TestSnapshot {
            counters: Self::snapshot_kind(&state.counters),
            histograms: Self::snapshot_kind(&state.histograms),
            gauges: Self::snapshot_kind(&state.gauges),
            up_down_counters: Self::snapshot_kind(&state.up_down_counters),
        }
    }

    /// Names of counters that have recorded at least once (PHP `$adapter->counters`).
    pub fn counter_names(&self) -> Vec<String> {
        self.state.lock().counters.keys().cloned().collect()
    }

    pub fn counter_values(&self, name: &str) -> Vec<f64> {
        self.counter_measurements(name)
            .into_iter()
            .map(|m| m.value)
            .collect()
    }

    pub fn histogram_values(&self, name: &str) -> Vec<f64> {
        self.histogram_measurements(name)
            .into_iter()
            .map(|m| m.value)
            .collect()
    }

    pub fn gauge_values(&self, name: &str) -> Vec<f64> {
        self.gauge_measurements(name)
            .into_iter()
            .map(|m| m.value)
            .collect()
    }

    pub fn up_down_counter_values(&self, name: &str) -> Vec<f64> {
        self.up_down_counter_measurements(name)
            .into_iter()
            .map(|m| m.value)
            .collect()
    }

    pub fn counter_measurements(&self, name: &str) -> Vec<RecordedMeasurement> {
        self.state
            .lock()
            .counters
            .get(name)
            .map(|s| s.lock().clone())
            .unwrap_or_default()
    }

    pub fn histogram_measurements(&self, name: &str) -> Vec<RecordedMeasurement> {
        self.state
            .lock()
            .histograms
            .get(name)
            .map(|s| s.lock().clone())
            .unwrap_or_default()
    }

    pub fn gauge_measurements(&self, name: &str) -> Vec<RecordedMeasurement> {
        self.state
            .lock()
            .gauges
            .get(name)
            .map(|s| s.lock().clone())
            .unwrap_or_default()
    }

    pub fn up_down_counter_measurements(&self, name: &str) -> Vec<RecordedMeasurement> {
        self.state
            .lock()
            .up_down_counters
            .get(name)
            .map(|s| s.lock().clone())
            .unwrap_or_default()
    }

    /// Whether an observable gauge has been observed (PHP `$adapter->observableGauges`).
    pub fn observable_gauge_observed(&self, name: &str) -> bool {
        self.state.lock().observable_gauges.contains_key(name)
    }
}

struct TestCounter {
    name: String,
    series: Series,
    state: Arc<Mutex<TestState>>,
}

struct TestHistogram {
    name: String,
    series: Series,
    state: Arc<Mutex<TestState>>,
}

struct TestGauge {
    name: String,
    series: Series,
    state: Arc<Mutex<TestState>>,
}

struct TestUpDownCounter {
    name: String,
    series: Series,
    state: Arc<Mutex<TestState>>,
}

struct TestObservableGauge {
    name: String,
    state: Arc<Mutex<TestState>>,
    callbacks: Mutex<Vec<ObserveCallback>>,
}

impl Counter for TestCounter {
    fn add(&self, value: f64, attributes: &Attributes) {
        self.series.lock().push(RecordedMeasurement {
            value,
            attributes: attributes.clone(),
        });
        self.state
            .lock()
            .counters
            .insert(self.name.clone(), Arc::clone(&self.series));
    }
}

impl Histogram for TestHistogram {
    fn record(&self, value: f64, attributes: &Attributes) {
        self.series.lock().push(RecordedMeasurement {
            value,
            attributes: attributes.clone(),
        });
        self.state
            .lock()
            .histograms
            .insert(self.name.clone(), Arc::clone(&self.series));
    }
}

impl Gauge for TestGauge {
    fn record(&self, value: f64, attributes: &Attributes) {
        self.series.lock().push(RecordedMeasurement {
            value,
            attributes: attributes.clone(),
        });
        self.state
            .lock()
            .gauges
            .insert(self.name.clone(), Arc::clone(&self.series));
    }
}

impl UpDownCounter for TestUpDownCounter {
    fn add(&self, value: f64, attributes: &Attributes) {
        self.series.lock().push(RecordedMeasurement {
            value,
            attributes: attributes.clone(),
        });
        self.state
            .lock()
            .up_down_counters
            .insert(self.name.clone(), Arc::clone(&self.series));
    }
}

impl ObservableGauge for TestObservableGauge {
    fn observe(&self, callback: ObserveCallback) {
        self.callbacks.lock().push(callback);
        let mut state = self.state.lock();
        if let Some(existing) = state.unobserved_gauges.remove(&self.name) {
            state.observable_gauges.insert(self.name.clone(), existing);
        }
    }
}

impl Adapter for TestAdapter {
    fn create_counter(
        &self,
        name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn Counter> {
        Arc::new(TestCounter {
            name: name.to_string(),
            series: Arc::new(Mutex::new(Vec::new())),
            state: Arc::clone(&self.state),
        })
    }

    fn create_histogram(
        &self,
        name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn Histogram> {
        Arc::new(TestHistogram {
            name: name.to_string(),
            series: Arc::new(Mutex::new(Vec::new())),
            state: Arc::clone(&self.state),
        })
    }

    fn create_gauge(
        &self,
        name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn Gauge> {
        Arc::new(TestGauge {
            name: name.to_string(),
            series: Arc::new(Mutex::new(Vec::new())),
            state: Arc::clone(&self.state),
        })
    }

    fn create_up_down_counter(
        &self,
        name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn UpDownCounter> {
        Arc::new(TestUpDownCounter {
            name: name.to_string(),
            series: Arc::new(Mutex::new(Vec::new())),
            state: Arc::clone(&self.state),
        })
    }

    fn create_observable_gauge(
        &self,
        name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn ObservableGauge> {
        let mut state = self.state.lock();
        if let Some(existing) = state
            .observable_gauges
            .get(name)
            .or_else(|| state.unobserved_gauges.get(name))
        {
            return existing.clone();
        }
        let gauge = Arc::new(TestObservableGauge {
            name: name.to_string(),
            state: Arc::clone(&self.state),
            callbacks: Mutex::new(Vec::new()),
        });
        state
            .unobserved_gauges
            .insert(name.to_string(), Arc::clone(&gauge));
        gauge
    }

    fn collect(&self) -> bool {
        true
    }
}
