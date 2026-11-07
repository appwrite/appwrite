use crate::telemetry::{Attributes, Histogram, UpDownCounter};

/// An instrument that discards every value.
pub(super) struct Nothing;

impl Histogram for Nothing {
    /// Discards the value.
    fn record(&self, _value: f64, _attributes: &Attributes) {}
}

impl UpDownCounter for Nothing {
    /// Discards the value.
    fn add(&self, _value: i64, _attributes: &Attributes) {}
}
