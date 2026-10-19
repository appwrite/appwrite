//! The HTTP server metrics [`crate::Http::run`] records, following the
//! OpenTelemetry semantic conventions, and the instrument traits an exporter
//! implements to receive them.
//!
//! PHP: `Http::setTelemetry()`. `utopia-php/telemetry` has no crate yet:
//! these traits are the part of its adapter the HTTP layer uses.

mod attribute;
mod histogram;
mod instruments;
mod no_telemetry;
mod nothing;
mod up_down_counter;

use std::sync::Arc;

pub use attribute::Attribute;
pub use histogram::Histogram;
pub(crate) use instruments::Instruments;
pub use no_telemetry::NoTelemetry;
pub use up_down_counter::UpDownCounter;

/// Attributes of a measurement: name and value pairs.
pub type Attributes = [(&'static str, Attribute)];

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

/// `http.server.request.duration` bucket boundaries, in seconds.
pub const DURATION_BUCKETS: [f64; 14] =
    [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0];
