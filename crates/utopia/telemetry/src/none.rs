//! [`NoTelemetry`]: a backend that records nothing.

use std::sync::{Arc, LazyLock};

use crate::{
    Attributes, BoxFuture, Callback, Counter, Gauge, Histogram, Number, ObservableGauge, Observe, Record, Telemetry,
    UpDownCounter,
};

/// Records nothing; every instrument is a no-op and collecting succeeds.
///
/// ```
/// use utopia_telemetry::{NoTelemetry, Telemetry};
///
/// let telemetry = NoTelemetry;
/// telemetry.counter("ignored", None, None).add(1, &[]);
/// ```
///
/// ```php
/// use Utopia\Telemetry\Adapter\None;
///
/// $telemetry = new None();
/// $telemetry->createCounter('ignored')->add(1);
/// ```
///
/// PHP: `Utopia\Telemetry\Adapter\None`.
#[derive(Debug, Clone, Copy, Default)]
pub struct NoTelemetry;

struct Nothing;

impl Record for Nothing {
    #[inline]
    fn record(&self, _: Number, _: Attributes<'_>) {}
}

impl Observe for Nothing {
    fn observe(&self, _: Callback) {}
}

static NOTHING: LazyLock<Arc<Nothing>> = LazyLock::new(|| Arc::new(Nothing));

impl Telemetry for NoTelemetry {
    /// A counter that records nothing.
    fn counter(&self, _: &str, _: Option<&str>, _: Option<&str>) -> Counter {
        Counter::new(NOTHING.clone())
    }

    /// An up/down counter that records nothing.
    fn up_down_counter(&self, _: &str, _: Option<&str>, _: Option<&str>) -> UpDownCounter {
        UpDownCounter::new(NOTHING.clone())
    }

    /// A histogram that records nothing.
    fn histogram(&self, _: &str, _: Option<&str>, _: Option<&str>, _: Option<&[f64]>) -> Histogram {
        Histogram::new(NOTHING.clone())
    }

    /// A gauge that records nothing.
    fn gauge(&self, _: &str, _: Option<&str>, _: Option<&str>) -> Gauge {
        Gauge::new(NOTHING.clone())
    }

    /// An observable gauge that never runs its callbacks.
    fn observable_gauge(&self, _: &str, _: Option<&str>, _: Option<&str>) -> ObservableGauge {
        ObservableGauge::new(NOTHING.clone())
    }

    /// Succeeds without doing anything.
    fn collect(&self) -> BoxFuture<'_, bool> {
        Box::pin(async { true })
    }
}
