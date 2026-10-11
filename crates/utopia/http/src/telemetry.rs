//! The HTTP server metrics [`crate::Http::run`] records, following the
//! OpenTelemetry semantic conventions, and the instrument traits an exporter
//! implements to receive them.
//!
//! PHP: `Http::setTelemetry()`. `utopia-php/telemetry` has no crate yet:
//! these traits are the part of its adapter the HTTP layer uses.

use std::sync::Arc;

/// A measurement attribute value.
#[derive(Debug, Clone, PartialEq)]
pub enum Attribute {
    /// A string value.
    Str(String),
    /// An integer value.
    Int(i64),
    /// An attribute that does not apply (`null`).
    None,
}

/// Attributes of a measurement: name and value pairs.
pub type Attributes = [(&'static str, Attribute)];

/// An instrument that records a distribution of values, such as request
/// durations or body sizes.
///
/// PHP: `Utopia\Telemetry\Histogram`.
pub trait Histogram: Send + Sync {
    /// Records `value` with `attributes`.
    fn record(&self, value: f64, attributes: &Attributes);
}

/// An instrument that counts up and down, such as requests in flight.
///
/// PHP: `Utopia\Telemetry\UpDownCounter`.
pub trait UpDownCounter: Send + Sync {
    /// Adds `value` (negative to subtract) with `attributes`.
    fn add(&self, value: i64, attributes: &Attributes);
}

/// A metrics exporter: creates the instruments the server records into.
///
/// PHP: `Utopia\Telemetry\Adapter`.
pub trait Telemetry: Send + Sync {
    /// A histogram named `name`, measured in `unit`; `boundaries` are explicit
    /// bucket boundaries, when advised.
    fn histogram(&self, name: &str, unit: &str, boundaries: Option<&[f64]>) -> Arc<dyn Histogram>;
    /// An up-down counter named `name`, measured in `unit`.
    fn up_down_counter(&self, name: &str, unit: &str) -> Arc<dyn UpDownCounter>;
}

/// An exporter that records nothing: the default of an application.
///
/// PHP: `Utopia\Telemetry\Adapter\None`.
pub struct NoTelemetry;

struct Nothing;

impl Histogram for Nothing {
    /// Discards the value.
    fn record(&self, _value: f64, _attributes: &Attributes) {}
}

impl UpDownCounter for Nothing {
    /// Discards the value.
    fn add(&self, _value: i64, _attributes: &Attributes) {}
}

impl Telemetry for NoTelemetry {
    /// A histogram that discards every value.
    fn histogram(&self, _name: &str, _unit: &str, _boundaries: Option<&[f64]>) -> Arc<dyn Histogram> {
        Arc::new(Nothing)
    }

    /// A counter that discards every value.
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
    /// Creates the server's instruments from `telemetry`.
    pub fn new(telemetry: &dyn Telemetry) -> Self {
        Self {
            request_duration: telemetry.histogram("http.server.request.duration", "s", Some(&DURATION_BUCKETS)),
            active_requests: telemetry.up_down_counter("http.server.active_requests", "{request}"),
            request_body_size: telemetry.histogram("http.server.request.body.size", "By", None),
            response_body_size: telemetry.histogram("http.server.response.body.size", "By", None),
        }
    }
}
