use std::sync::{Arc, OnceLock};

use crate::instrument::ObserveCallback;
use crate::{
    adapter::Adapter, Advisory, Attributes, Counter, Gauge, Histogram, ObservableGauge,
    UpDownCounter,
};

/// No-op telemetry adapter. All instruments discard measurements.
#[derive(Debug, Default, Clone, Copy)]
pub struct NoneAdapter;

impl NoneAdapter {
    pub fn new() -> Self {
        Self
    }
}

struct NoopCounter;
struct NoopHistogram;
struct NoopGauge;
struct NoopUpDownCounter;
struct NoopObservableGauge;

impl Counter for NoopCounter {
    fn add(&self, _value: f64, _attributes: &Attributes) {}
}

impl Histogram for NoopHistogram {
    fn record(&self, _value: f64, _attributes: &Attributes) {}
}

impl Gauge for NoopGauge {
    fn record(&self, _value: f64, _attributes: &Attributes) {}
}

impl UpDownCounter for NoopUpDownCounter {
    fn add(&self, _value: f64, _attributes: &Attributes) {}
}

impl ObservableGauge for NoopObservableGauge {
    fn observe(&self, _callback: ObserveCallback) {}
}

fn shared_counter() -> Arc<dyn Counter> {
    static COUNTER: OnceLock<Arc<dyn Counter>> = OnceLock::new();
    Arc::clone(COUNTER.get_or_init(|| Arc::new(NoopCounter)))
}

fn shared_histogram() -> Arc<dyn Histogram> {
    static HISTOGRAM: OnceLock<Arc<dyn Histogram>> = OnceLock::new();
    Arc::clone(HISTOGRAM.get_or_init(|| Arc::new(NoopHistogram)))
}

fn shared_gauge() -> Arc<dyn Gauge> {
    static GAUGE: OnceLock<Arc<dyn Gauge>> = OnceLock::new();
    Arc::clone(GAUGE.get_or_init(|| Arc::new(NoopGauge)))
}

fn shared_up_down_counter() -> Arc<dyn UpDownCounter> {
    static UP_DOWN: OnceLock<Arc<dyn UpDownCounter>> = OnceLock::new();
    Arc::clone(UP_DOWN.get_or_init(|| Arc::new(NoopUpDownCounter)))
}

fn shared_observable_gauge() -> Arc<dyn ObservableGauge> {
    static OBSERVABLE: OnceLock<Arc<dyn ObservableGauge>> = OnceLock::new();
    Arc::clone(OBSERVABLE.get_or_init(|| Arc::new(NoopObservableGauge)))
}

impl Adapter for NoneAdapter {
    fn create_counter(
        &self,
        _name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn Counter> {
        shared_counter()
    }

    fn create_histogram(
        &self,
        _name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn Histogram> {
        shared_histogram()
    }

    fn create_gauge(
        &self,
        _name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn Gauge> {
        shared_gauge()
    }

    fn create_up_down_counter(
        &self,
        _name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn UpDownCounter> {
        shared_up_down_counter()
    }

    fn create_observable_gauge(
        &self,
        _name: &str,
        _unit: Option<&str>,
        _description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn ObservableGauge> {
        shared_observable_gauge()
    }

    fn collect(&self) -> bool {
        true
    }

    fn enabled(&self) -> bool {
        false
    }
}
