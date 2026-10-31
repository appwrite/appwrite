use crate::telemetry::Attributes;

/// An instrument that records a distribution of values, such as request
/// durations or body sizes.
///
/// PHP: `Utopia\Telemetry\Histogram`.
pub trait Histogram: Send + Sync {
    /// Records `value` with `attributes`.
    fn record(&self, value: f64, attributes: &Attributes);
}
