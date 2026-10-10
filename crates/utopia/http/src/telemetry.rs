//! The HTTP server metrics [`crate::Http::run`] records
//! (`Http::setTelemetry()`), following the OpenTelemetry semantic
//! conventions. `utopia-php/telemetry` has no crate yet: these traits are the
//! part of its adapter the HTTP layer uses, for an exporter to implement.

use std::sync::Arc;

/// A measurement attribute value.
#[derive(Debug, Clone, PartialEq)]
pub enum Attribute {
    Str(String),
    Int(i64),
    /// An attribute that does not apply (`null`).
    None,
}

/// Attributes of a measurement.
pub type Attributes = [(&'static str, Attribute)];

/// `Utopia\Telemetry\Histogram`.
pub trait Histogram: Send + Sync {
    fn record(&self, value: f64, attributes: &Attributes);
}

/// `Utopia\Telemetry\UpDownCounter`.
pub trait UpDownCounter: Send + Sync {
    fn add(&self, value: i64, attributes: &Attributes);
}

/// `Utopia\Telemetry\Adapter`: creates instruments.
pub trait Telemetry: Send + Sync {
    /// A histogram; `boundaries` are explicit bucket boundaries, when advised.
    fn histogram(&self, name: &str, unit: &str, boundaries: Option<&[f64]>) -> Arc<dyn Histogram>;
    fn up_down_counter(&self, name: &str, unit: &str) -> Arc<dyn UpDownCounter>;
}

/// `Utopia\Telemetry\Adapter\None`: records nothing.
pub struct NoTelemetry;

struct Nothing;

impl Histogram for Nothing {
    fn record(&self, _value: f64, _attributes: &Attributes) {}
}

impl UpDownCounter for Nothing {
    fn add(&self, _value: i64, _attributes: &Attributes) {}
}

impl Telemetry for NoTelemetry {
    fn histogram(&self, _name: &str, _unit: &str, _boundaries: Option<&[f64]>) -> Arc<dyn Histogram> {
        Arc::new(Nothing)
    }

    fn up_down_counter(&self, _name: &str, _unit: &str) -> Arc<dyn UpDownCounter> {
        Arc::new(Nothing)
    }
}

/// `http.server.request.duration` bucket boundaries, in seconds.
pub const DURATION_BUCKETS: [f64; 14] =
    [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0];

/// The instruments of one application.
#[derive(Clone)]
pub(crate) struct Instruments {
    pub request_duration: Arc<dyn Histogram>,
    pub active_requests: Arc<dyn UpDownCounter>,
    pub request_body_size: Arc<dyn Histogram>,
    pub response_body_size: Arc<dyn Histogram>,
}

impl Instruments {
    pub fn new(telemetry: &dyn Telemetry) -> Self {
        Self {
            request_duration: telemetry.histogram("http.server.request.duration", "s", Some(&DURATION_BUCKETS)),
            active_requests: telemetry.up_down_counter("http.server.active_requests", "{request}"),
            request_body_size: telemetry.histogram("http.server.request.body.size", "By", None),
            response_body_size: telemetry.histogram("http.server.response.body.size", "By", None),
        }
    }
}
