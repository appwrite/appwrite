use std::collections::HashMap;

use crate::{adapter::Adapter, Advisory, Attributes};

/// Monotonically increasing counter. Only non-negative increments are expected.
pub trait Counter: Send + Sync {
    fn add(&self, value: f64, attributes: &Attributes);
}

/// Records a distribution of values (latency, payload size, etc.).
pub trait Histogram: Send + Sync {
    fn record(&self, value: f64, attributes: &Attributes);
}

/// Counter that can increase or decrease.
pub trait UpDownCounter: Send + Sync {
    fn add(&self, value: f64, attributes: &Attributes);
}

/// Records the last value for a measurement.
pub trait Gauge: Send + Sync {
    fn record(&self, value: f64, attributes: &Attributes);
}

/// Observer passed to [`ObservableGauge`] callbacks during collection.
pub trait Observer {
    fn observe(&mut self, value: f64, attributes: &Attributes);
}

/// Callback registered on an [`ObservableGauge`].
pub type ObserveCallback = Box<dyn Fn(&mut dyn Observer) + Send + Sync>;

/// Asynchronous gauge observed via callbacks at collect time.
pub trait ObservableGauge: Send + Sync {
    fn observe(&self, callback: ObserveCallback);
}

/// PHP `Counter::lazy()` - deprecated shim that calls `Adapter::createCounter()`.
pub fn lazy_counter(
    adapter: &dyn Adapter,
    name: &str,
    unit: Option<&str>,
    description: Option<&str>,
    advisory: Advisory,
) -> std::sync::Arc<dyn Counter> {
    adapter.create_counter(name, unit, description, advisory)
}

/// PHP `Histogram::lazy()`.
pub fn lazy_histogram(
    adapter: &dyn Adapter,
    name: &str,
    unit: Option<&str>,
    description: Option<&str>,
    advisory: Advisory,
) -> std::sync::Arc<dyn Histogram> {
    adapter.create_histogram(name, unit, description, advisory)
}

/// PHP `Gauge::lazy()`.
pub fn lazy_gauge(
    adapter: &dyn Adapter,
    name: &str,
    unit: Option<&str>,
    description: Option<&str>,
    advisory: Advisory,
) -> std::sync::Arc<dyn Gauge> {
    adapter.create_gauge(name, unit, description, advisory)
}

/// PHP `UpDownCounter::lazy()`.
pub fn lazy_up_down_counter(
    adapter: &dyn Adapter,
    name: &str,
    unit: Option<&str>,
    description: Option<&str>,
    advisory: Advisory,
) -> std::sync::Arc<dyn UpDownCounter> {
    adapter.create_up_down_counter(name, unit, description, advisory)
}

/// Build attributes from `&[("key", "value")]`.
pub fn attrs(pairs: &[(&str, &str)]) -> HashMap<String, String> {
    pairs
        .iter()
        .map(|(k, v)| (String::from(*k), String::from(*v)))
        .collect()
}
