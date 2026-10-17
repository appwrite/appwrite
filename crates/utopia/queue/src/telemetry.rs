//! Metrics a worker and a background publisher report (the part of
//! `utopia-php/telemetry` this crate uses).

use std::sync::Arc;

use crate::connection::BoxFuture;

/// Attributes of one observation.
pub type Attributes = Vec<(String, String)>;

/// Records values into a histogram.
pub trait Histogram: Send + Sync {
    /// Records one value.
    fn record(&self, value: f64);
}

/// Reports a gauge's current values when asked: each `(value, attributes)`.
pub type Observer = Arc<dyn Fn() -> BoxFuture<'static, Vec<(i64, Attributes)>> + Send + Sync>;

/// Where metrics go. The default, [`NoTelemetry`], drops them.
pub trait Telemetry: Send + Sync {
    /// A histogram with explicit bucket boundaries.
    fn histogram(&self, name: &str, unit: &str, description: Option<&str>, buckets: &[f64]) -> Arc<dyn Histogram>;

    /// An observable gauge: `observer` is called on every collection.
    fn observable_gauge(&self, name: &str, unit: &str, description: &str, observer: Observer);
}

/// Drops every metric.
#[derive(Debug, Clone, Copy, Default)]
pub struct NoTelemetry;

struct Discard;

impl Histogram for Discard {
    fn record(&self, _value: f64) {}
}

impl Telemetry for NoTelemetry {
    fn histogram(&self, _: &str, _: &str, _: Option<&str>, _: &[f64]) -> Arc<dyn Histogram> {
        Arc::new(Discard)
    }

    fn observable_gauge(&self, _: &str, _: &str, _: &str, _: Observer) {}
}
