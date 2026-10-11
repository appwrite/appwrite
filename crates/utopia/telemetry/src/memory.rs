//! [`MemoryTelemetry`]: a backend that keeps every data point, for tests.

use std::sync::{Arc, Mutex, MutexGuard};

use crate::{
    Attributes, BoxFuture, Callback, Counter, Gauge, Histogram, Number, ObservableGauge, Observation, Observe,
    OwnedAttributes, Record, Telemetry, UpDownCounter, to_owned,
};

/// The kind of an instrument.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub enum Kind {
    /// [`Counter`].
    Counter,
    /// [`UpDownCounter`].
    UpDownCounter,
    /// [`Histogram`].
    Histogram,
    /// [`Gauge`].
    Gauge,
    /// [`ObservableGauge`].
    ObservableGauge,
}

/// One recorded data point.
#[derive(Debug, Clone, PartialEq)]
pub struct Point {
    /// The amount or measurement.
    pub value: Number,
    /// Its attributes, in order.
    pub attributes: OwnedAttributes,
}

type Points = Arc<Mutex<Vec<Point>>>;

/// The instruments that recorded, in the order they first did.
#[derive(Default)]
struct Registry {
    recorded: Vec<((Kind, String), Points)>,
    /// Observable gauges by name: whether one was observed, and its callbacks.
    gauges: Vec<(String, Arc<Gauged>)>,
}

#[derive(Default)]
struct Gauged {
    observed: Mutex<bool>,
    callbacks: Mutex<Vec<Callback>>,
}

/// Keeps every data point in memory, by instrument, so tests can check what
/// was recorded.
///
/// An instrument appears on its first data point, not when it is created, as
/// with an exporting backend. Each instrument keeps its own points; when two
/// instruments of one name record, the name shows the last one that did.
/// Observable gauges are one instrument per name, listed once observed, and
/// [`MemoryTelemetry::observe`] runs their callbacks.
///
/// ```
/// use utopia_telemetry::{Kind, MemoryTelemetry, Telemetry, Value};
///
/// let telemetry = MemoryTelemetry::new();
/// let duration = telemetry.histogram("request.duration", Some("ms"), None, None);
/// assert_eq!(telemetry.values(Kind::Histogram, "request.duration"), None);
///
/// duration.record(12.3, &[("route", Value::from("/v1/health"))]);
/// assert_eq!(telemetry.values(Kind::Histogram, "request.duration"), Some(vec![12.3.into()]));
/// ```
///
/// ```php
/// use Utopia\Telemetry\Adapter\Test;
///
/// $telemetry = new Test();
/// $duration = $telemetry->createHistogram('request.duration', 'ms');
/// var_dump(isset($telemetry->histograms['request.duration'])); // bool(false)
///
/// $duration->record(12.3, ['route' => '/v1/health']);
/// var_dump($telemetry->histograms['request.duration']->values); // [12.3]
/// ```
///
/// PHP: `Utopia\Telemetry\Adapter\Test`; its public `$counters`,
/// `$histograms`, `$gauges`, `$upDownCounters` and `$observableGauges` arrays
/// are [`MemoryTelemetry::names`], [`MemoryTelemetry::values`] and
/// [`MemoryTelemetry::callbacks`].
#[derive(Clone, Default)]
pub struct MemoryTelemetry {
    registry: Arc<Mutex<Registry>>,
}

struct Recorder {
    kind: Kind,
    name: String,
    points: Points,
    registry: Arc<Mutex<Registry>>,
}

impl Record for Recorder {
    fn record(&self, value: Number, attributes: Attributes<'_>) {
        {
            let mut registry = lock(&self.registry);
            let key = (self.kind, self.name.clone());
            match registry.recorded.iter_mut().find(|(k, _)| *k == key) {
                Some((_, points)) => *points = self.points.clone(),
                None => registry.recorded.push((key, self.points.clone())),
            }
        }
        lock(&self.points).push(Point { value, attributes: to_owned(attributes) });
    }
}

impl Observe for Gauged {
    fn observe(&self, callback: Callback) {
        *lock(&self.observed) = true;
        lock(&self.callbacks).push(callback);
    }
}

fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

impl MemoryTelemetry {
    /// An empty backend.
    pub fn new() -> Self {
        Self::default()
    }

    fn recorder(&self, kind: Kind, name: &str) -> Arc<Recorder> {
        Arc::new(Recorder { kind, name: name.to_owned(), points: Arc::default(), registry: self.registry.clone() })
    }

    /// The names of the instruments of `kind` that recorded (observable
    /// gauges: that were observed), in the order they first did.
    ///
    /// PHP: the keys of `$counters`, `$histograms`, ... .
    pub fn names(&self, kind: Kind) -> Vec<String> {
        let registry = lock(&self.registry);
        if kind == Kind::ObservableGauge {
            return registry.gauges.iter().filter(|(_, g)| *lock(&g.observed)).map(|(n, _)| n.clone()).collect();
        }
        registry.recorded.iter().filter(|((k, _), _)| *k == kind).map(|((_, n), _)| n.clone()).collect()
    }

    /// The data points of the last instrument of `kind` named `name` that
    /// recorded; `None` when none did.
    pub fn points(&self, kind: Kind, name: &str) -> Option<Vec<Point>> {
        let points = {
            let registry = lock(&self.registry);
            registry.recorded.iter().find(|((k, n), _)| *k == kind && n == name).map(|(_, p)| p.clone())?
        };
        Some(lock(&points).clone())
    }

    /// The values the last instrument of `kind` named `name` recorded.
    ///
    /// PHP: `$telemetry->counters[$name]->values` (and the other kinds).
    pub fn values(&self, kind: Kind, name: &str) -> Option<Vec<Number>> {
        self.points(kind, name).map(|points| points.into_iter().map(|p| p.value).collect())
    }

    /// The number of callbacks the observable gauge `name` has; `None` when
    /// it was never observed.
    ///
    /// PHP: `count($telemetry->observableGauges[$name]->callbacks)`.
    pub fn callbacks(&self, name: &str) -> Option<usize> {
        let gauge = self.gauge(name)?;
        let observed = *lock(&gauge.observed);
        observed.then(|| lock(&gauge.callbacks).len())
    }

    fn gauge(&self, name: &str) -> Option<Arc<Gauged>> {
        lock(&self.registry).gauges.iter().find(|(n, _)| n == name).map(|(_, g)| g.clone())
    }

    /// Runs every callback of the observable gauge `name`, in the order they
    /// were added, and returns what they reported; `None` when it was never
    /// observed.
    ///
    /// PHP: calling each of `$telemetry->observableGauges[$name]->callbacks`.
    pub async fn observe(&self, name: &str) -> Option<Vec<Observation>> {
        let gauge = self.gauge(name)?;
        if !*lock(&gauge.observed) {
            return None;
        }
        let callbacks: Vec<Callback> = lock(&gauge.callbacks).clone();
        let mut out = Vec::new();
        for callback in callbacks {
            out.extend(callback().await);
        }
        Some(out)
    }
}

impl Telemetry for MemoryTelemetry {
    /// A counter keeping its amounts.
    fn counter(&self, name: &str, _: Option<&str>, _: Option<&str>) -> Counter {
        Counter::new(self.recorder(Kind::Counter, name))
    }

    /// An up/down counter keeping its amounts.
    fn up_down_counter(&self, name: &str, _: Option<&str>, _: Option<&str>) -> UpDownCounter {
        UpDownCounter::new(self.recorder(Kind::UpDownCounter, name))
    }

    /// A histogram keeping its measurements.
    fn histogram(&self, name: &str, _: Option<&str>, _: Option<&str>, _: Option<&[f64]>) -> Histogram {
        Histogram::new(self.recorder(Kind::Histogram, name))
    }

    /// A gauge keeping its measurements.
    fn gauge(&self, name: &str, _: Option<&str>, _: Option<&str>) -> Gauge {
        Gauge::new(self.recorder(Kind::Gauge, name))
    }

    /// The observable gauge `name`, created on the first call.
    fn observable_gauge(&self, name: &str, _: Option<&str>, _: Option<&str>) -> ObservableGauge {
        let gauge = {
            let mut registry = lock(&self.registry);
            match registry.gauges.iter().find(|(n, _)| n == name) {
                Some((_, g)) => g.clone(),
                None => {
                    let g = Arc::new(Gauged::default());
                    registry.gauges.push((name.to_owned(), g.clone()));
                    g
                }
            }
        };
        ObservableGauge::new(gauge)
    }

    /// Succeeds: nothing is exported.
    fn collect(&self) -> BoxFuture<'_, bool> {
        Box::pin(async { true })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::Value;

    #[test]
    fn instruments_appear_on_their_first_point() {
        let telemetry = MemoryTelemetry::new();
        let counter = telemetry.counter("events.total", Some("{event}"), None);
        assert!(telemetry.names(Kind::Counter).is_empty());
        counter.add(1, &[("event.name", Value::from("created"))]);
        counter.add(2, &[]);
        assert_eq!(telemetry.values(Kind::Counter, "events.total"), Some(vec![Number::Int(1), Number::Int(2)]));
    }

    #[test]
    fn the_last_instrument_to_record_owns_the_name() {
        let telemetry = MemoryTelemetry::new();
        let first = telemetry.counter("x", None, None);
        let second = telemetry.counter("x", None, None);
        first.add(1, &[]);
        second.add(2, &[]);
        assert_eq!(telemetry.values(Kind::Counter, "x"), Some(vec![Number::Int(2)]));
        first.add(3, &[]);
        assert_eq!(telemetry.values(Kind::Counter, "x"), Some(vec![Number::Int(1), Number::Int(3)]));
    }

    #[tokio::test]
    async fn observable_gauges_share_one_instrument_per_name() {
        let telemetry = MemoryTelemetry::new();
        telemetry.observable_gauge("g", None, None).observe(|o| o.observe(1.into(), &[("pool", Value::from("a"))]));
        telemetry.observable_gauge("g", None, None).observe(|o| o.observe(2.into(), &[("pool", Value::from("b"))]));
        assert_eq!(telemetry.callbacks("g"), Some(2));
        let observed = telemetry.observe("g").await.unwrap();
        assert_eq!(observed.iter().map(|o| o.value).collect::<Vec<_>>(), vec![Number::Int(1), Number::Int(2)]);
    }
}
