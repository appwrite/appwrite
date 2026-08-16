use std::sync::Arc;

use crate::{Advisory, Counter, Gauge, Histogram, ObservableGauge, UpDownCounter};

/// Backend that creates metric instruments.
pub trait Adapter: Send + Sync {
    fn create_counter(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        advisory: Advisory,
    ) -> Arc<dyn Counter>;

    fn create_histogram(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        advisory: Advisory,
    ) -> Arc<dyn Histogram>;

    fn create_gauge(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        advisory: Advisory,
    ) -> Arc<dyn Gauge>;

    fn create_up_down_counter(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        advisory: Advisory,
    ) -> Arc<dyn UpDownCounter>;

    fn create_observable_gauge(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        advisory: Advisory,
    ) -> Arc<dyn ObservableGauge>;

    /// Flush or export buffered metrics. Returns `true` on success.
    fn collect(&self) -> bool;

    /// When `false`, callers may skip building metric attributes (e.g. `NoneAdapter`).
    fn enabled(&self) -> bool {
        true
    }
}
