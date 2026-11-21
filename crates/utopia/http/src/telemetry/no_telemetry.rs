use std::sync::Arc;

use crate::telemetry::nothing::Nothing;
use crate::telemetry::{Histogram, Telemetry, UpDownCounter};

/// An exporter that records nothing: the default of an application.
///
/// PHP: `Utopia\Telemetry\Adapter\None`.
pub struct NoTelemetry;

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
