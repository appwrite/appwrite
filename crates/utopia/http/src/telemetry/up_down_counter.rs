use crate::telemetry::Attributes;

/// An instrument that counts up and down, such as requests in flight.
///
/// PHP: `Utopia\Telemetry\UpDownCounter`.
pub trait UpDownCounter: Send + Sync {
    /// Adds `value` (negative to subtract) with `attributes`.
    fn add(&self, value: i64, attributes: &Attributes);
}
