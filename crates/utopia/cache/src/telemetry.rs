//! Metrics the cache records: the instruments it needs from a telemetry
//! backend ([`Telemetry`]), a no-op backend ([`NoTelemetry`]) and one that
//! keeps every data point in memory ([`MemoryTelemetry`]).
//!
//! These traits are the part of `utopia-php/telemetry` the cache uses; they
//! move to a `utopia-telemetry` crate when that library is converted.

use std::collections::BTreeMap;
use std::sync::{Arc, Mutex};

/// Attributes of one data point: `(name, value)` pairs in order.
pub type Attributes<'a> = &'a [(&'a str, &'a str)];

/// A monotonic counter.
pub trait Counter: Send + Sync {
    /// Adds `amount` with `attributes`.
    fn add(&self, amount: f64, attributes: Attributes<'_>);
}

/// A counter that can go down.
pub trait UpDownCounter: Send + Sync {
    /// Adds `amount` (negative to decrease) with `attributes`.
    fn add(&self, amount: f64, attributes: Attributes<'_>);
}

/// A distribution of measurements.
pub trait Histogram: Send + Sync {
    /// Records `value` with `attributes`.
    fn record(&self, value: f64, attributes: Attributes<'_>);
}

/// A gauge: the last value set wins.
pub trait Gauge: Send + Sync {
    /// Records `value` with `attributes`.
    fn record(&self, value: f64, attributes: Attributes<'_>);
}

/// Creates instruments. Implement it to export the cache's metrics.
pub trait Telemetry: Send + Sync {
    /// A counter named `name`.
    fn counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Arc<dyn Counter>;

    /// An up/down counter named `name`.
    fn up_down_counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Arc<dyn UpDownCounter>;

    /// A histogram named `name`, with explicit bucket boundaries when given.
    fn histogram(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        boundaries: &[f64],
    ) -> Arc<dyn Histogram>;

    /// A gauge named `name`.
    fn gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Arc<dyn Gauge>;
}

/// Records nothing.
///
/// PHP: `Utopia\Telemetry\Adapter\None`.
#[derive(Debug, Clone, Copy, Default)]
pub struct NoTelemetry;

struct Nothing;

impl Counter for Nothing {
    fn add(&self, _: f64, _: Attributes<'_>) {}
}

impl UpDownCounter for Nothing {
    fn add(&self, _: f64, _: Attributes<'_>) {}
}

impl Histogram for Nothing {
    fn record(&self, _: f64, _: Attributes<'_>) {}
}

impl Gauge for Nothing {
    fn record(&self, _: f64, _: Attributes<'_>) {}
}

impl Telemetry for NoTelemetry {
    fn counter(&self, _: &str, _: Option<&str>, _: Option<&str>) -> Arc<dyn Counter> {
        Arc::new(Nothing)
    }

    fn up_down_counter(&self, _: &str, _: Option<&str>, _: Option<&str>) -> Arc<dyn UpDownCounter> {
        Arc::new(Nothing)
    }

    fn histogram(&self, _: &str, _: Option<&str>, _: Option<&str>, _: &[f64]) -> Arc<dyn Histogram> {
        Arc::new(Nothing)
    }

    fn gauge(&self, _: &str, _: Option<&str>, _: Option<&str>) -> Arc<dyn Gauge> {
        Arc::new(Nothing)
    }
}

/// Data points by instrument.
type Store = Arc<Mutex<BTreeMap<(Kind, String), Vec<Point>>>>;

/// One recorded data point.
#[derive(Debug, Clone, PartialEq)]
pub struct Point {
    /// The amount or measurement.
    pub value: f64,
    /// Its attributes, in order.
    pub attributes: Vec<(String, String)>,
}

/// Keeps every data point, by instrument name. An instrument appears on its
/// first data point, not when it is created.
///
/// PHP: `Utopia\Telemetry\Adapter\Test`.
#[derive(Clone, Default)]
pub struct MemoryTelemetry {
    points: Store,
}

/// The kind of an instrument [`MemoryTelemetry`] recorded.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub enum Kind {
    /// [`Counter`].
    Counter,
    /// [`UpDownCounter`].
    UpDownCounter,
    /// [`Histogram`].
    Histogram,
    /// [`Gauge`].
    Gauge,
}

struct Recorder {
    key: (Kind, String),
    points: Store,
}

impl Recorder {
    fn push(&self, value: f64, attributes: Attributes<'_>) {
        let point =
            Point { value, attributes: attributes.iter().map(|(k, v)| ((*k).to_owned(), (*v).to_owned())).collect() };
        let mut points = self.points.lock().unwrap_or_else(|e| e.into_inner());
        points.entry(self.key.clone()).or_default().push(point);
    }
}

impl Counter for Recorder {
    fn add(&self, amount: f64, attributes: Attributes<'_>) {
        self.push(amount, attributes);
    }
}

impl UpDownCounter for Recorder {
    fn add(&self, amount: f64, attributes: Attributes<'_>) {
        self.push(amount, attributes);
    }
}

impl Histogram for Recorder {
    fn record(&self, value: f64, attributes: Attributes<'_>) {
        self.push(value, attributes);
    }
}

impl Gauge for Recorder {
    fn record(&self, value: f64, attributes: Attributes<'_>) {
        self.push(value, attributes);
    }
}

impl MemoryTelemetry {
    /// An empty recorder.
    pub fn new() -> Self {
        Self::default()
    }

    fn recorder(&self, kind: Kind, name: &str) -> Recorder {
        Recorder { key: (kind, name.to_owned()), points: self.points.clone() }
    }

    /// The data points of an instrument, `None` when it never recorded.
    pub fn points(&self, kind: Kind, name: &str) -> Option<Vec<Point>> {
        self.points.lock().unwrap_or_else(|e| e.into_inner()).get(&(kind, name.to_owned())).cloned()
    }

    /// Names of the instruments of `kind` that recorded, sorted.
    pub fn names(&self, kind: Kind) -> Vec<String> {
        let points = self.points.lock().unwrap_or_else(|e| e.into_inner());
        points.keys().filter(|(k, _)| *k == kind).map(|(_, n)| n.clone()).collect()
    }
}

impl Telemetry for MemoryTelemetry {
    fn counter(&self, name: &str, _: Option<&str>, _: Option<&str>) -> Arc<dyn Counter> {
        Arc::new(self.recorder(Kind::Counter, name))
    }

    fn up_down_counter(&self, name: &str, _: Option<&str>, _: Option<&str>) -> Arc<dyn UpDownCounter> {
        Arc::new(self.recorder(Kind::UpDownCounter, name))
    }

    fn histogram(&self, name: &str, _: Option<&str>, _: Option<&str>, _: &[f64]) -> Arc<dyn Histogram> {
        Arc::new(self.recorder(Kind::Histogram, name))
    }

    fn gauge(&self, name: &str, _: Option<&str>, _: Option<&str>) -> Arc<dyn Gauge> {
        Arc::new(self.recorder(Kind::Gauge, name))
    }
}
