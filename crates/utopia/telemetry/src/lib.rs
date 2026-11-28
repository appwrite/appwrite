//! Metrics for services and libraries: create instruments from a
//! [`Telemetry`] backend, record measurements on them, and let the backend
//! export what was recorded.
//!
//! Five instruments cover the usual needs: a [`Counter`] that only goes up, an
//! [`UpDownCounter`], a [`Histogram`] of measurements, a [`Gauge`] whose last
//! value wins, and an [`ObservableGauge`] read by callbacks when the backend
//! collects. Every measurement is a [`Number`] (an integer stays an integer)
//! with [`Attributes`]: `(name, value)` pairs that split it into series.
//!
//! Backends: [`NoTelemetry`] records nothing, [`MemoryTelemetry`] keeps every
//! data point for tests, and `OpenTelemetry` (feature `otlp`, on by default)
//! aggregates measurements and exports them as OTLP over HTTP. Implement
//! [`Telemetry`] to add another.
//!
//! ```
//! use utopia_telemetry::{Kind, MemoryTelemetry, Telemetry, Value};
//!
//! let telemetry = MemoryTelemetry::new();
//! let requests = telemetry.counter("http.requests", Some("{request}"), None);
//! requests.add(1, &[("route", Value::from("/v1/health"))]);
//!
//! assert_eq!(telemetry.values(Kind::Counter, "http.requests"), Some(vec![1.into()]));
//! ```
//!
//! ```php
//! use Utopia\Telemetry\Adapter\Test;
//!
//! $telemetry = new Test();
//! $requests = $telemetry->createCounter('http.requests', '{request}');
//! $requests->add(1, ['route' => '/v1/health']);
//!
//! var_dump($telemetry->counters['http.requests']->values); // [1]
//! ```
//!
//! PHP: ports `utopia-php/telemetry`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Telemetry\Adapter` (interface) | [`Telemetry`] |
//! | `Adapter::createCounter()`, `createUpDownCounter()`, `createHistogram()`, `createGauge()`, `createObservableGauge()` | [`Telemetry::counter`], [`Telemetry::up_down_counter`], [`Telemetry::histogram`], [`Telemetry::gauge`], [`Telemetry::observable_gauge`] |
//! | `Adapter::collect()` | [`Telemetry::collect`] |
//! | `Counter`, `UpDownCounter`, `Histogram`, `Gauge`, `ObservableGauge` | [`Counter`], [`UpDownCounter`], [`Histogram`], [`Gauge`], [`ObservableGauge`] (backends implement [`Record`] and [`Observe`]) |
//! | `Counter::lazy()` and the other deprecated `lazy()` shims | the `Telemetry` methods: instruments are lazy already |
//! | `Utopia\Telemetry\Adapter\None` | [`NoTelemetry`] |
//! | `Utopia\Telemetry\Adapter\Test` | [`MemoryTelemetry`] |
//! | `Utopia\Telemetry\Adapter\OpenTelemetry` | `OpenTelemetry` (feature `otlp`) |
//! | `Adapter\OpenTelemetry\MetricExporter` | part of `OpenTelemetry`: metrics without data points are dropped, gauges have no start time |
//! | `Adapter\OpenTelemetry\Transport\Swoole` | `HttpTransport` (keep-alive connection pool), behind the `Transport` trait |
//! | `Utopia\Telemetry\Exception` | [`Error::Telemetry`] |
//! | `int\|float` amounts | [`Number`] |
//! | attribute values (`string\|int\|float\|bool`) | [`Value`] |

#![warn(missing_docs)]

mod error;
mod memory;
mod none;
#[cfg(feature = "otlp")]
mod otlp;

use std::borrow::Cow;
use std::fmt;
use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;

pub use error::Error;
pub use memory::{Kind, MemoryTelemetry, Point};
pub use none::NoTelemetry;
#[cfg(feature = "otlp")]
pub use otlp::{HttpTransport, OpenTelemetry, Transport};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// A boxed future, as [`Telemetry::collect`] and transports return.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// A measurement: an integer or a float. Integers stay integers through
/// sums, so a counter that only ever adds integers reports an integer.
///
/// PHP: the `float|int` amounts instruments take.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Number {
    /// A whole number.
    Int(i64),
    /// A floating-point number.
    Float(f64),
}

impl std::ops::Add for Number {
    type Output = Number;

    /// The sum of two numbers: an integer while both are integers and the sum
    /// fits, a float otherwise.
    ///
    /// PHP: `+` on `int|float`, where an overflowing integer sum becomes a float.
    fn add(self, other: Number) -> Number {
        match (self, other) {
            (Number::Int(a), Number::Int(b)) => {
                a.checked_add(b).map_or(Number::Float(a as f64 + b as f64), Number::Int)
            }
            (a, b) => Number::Float(a.as_f64() + b.as_f64()),
        }
    }
}

impl Number {
    /// The value as a float.
    pub fn as_f64(self) -> f64 {
        match self {
            Number::Int(i) => i as f64,
            Number::Float(f) => f,
        }
    }
}

impl From<i64> for Number {
    /// An integer measurement.
    fn from(value: i64) -> Self {
        Number::Int(value)
    }
}

impl From<i32> for Number {
    /// An integer measurement.
    fn from(value: i32) -> Self {
        Number::Int(value.into())
    }
}

impl From<usize> for Number {
    /// An integer measurement (saturating at `i64::MAX`).
    fn from(value: usize) -> Self {
        Number::Int(i64::try_from(value).unwrap_or(i64::MAX))
    }
}

impl From<f64> for Number {
    /// A float measurement.
    fn from(value: f64) -> Self {
        Number::Float(value)
    }
}

impl fmt::Display for Number {
    /// Writes the number as PHP prints it (`1`, `0.5`, `1.0E+25`).
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Number::Int(i) => write!(f, "{i}"),
            Number::Float(x) => f.write_str(&php_std::number::to_string(*x)),
        }
    }
}

/// The value of an attribute.
///
/// PHP: the scalar attribute values (`string|int|float|bool`).
#[derive(Debug, Clone, PartialEq)]
pub enum Value<'a> {
    /// A string.
    Str(Cow<'a, str>),
    /// An integer.
    Int(i64),
    /// A float.
    Float(f64),
    /// A boolean.
    Bool(bool),
}

impl Value<'_> {
    /// A copy that owns its string.
    pub fn into_owned(self) -> Value<'static> {
        match self {
            Value::Str(s) => Value::Str(Cow::Owned(s.into_owned())),
            Value::Int(i) => Value::Int(i),
            Value::Float(f) => Value::Float(f),
            Value::Bool(b) => Value::Bool(b),
        }
    }

    /// The string, when the value is one.
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Value::Str(s) => Some(s),
            _ => None,
        }
    }
}

impl<'a> From<&'a str> for Value<'a> {
    /// A string attribute.
    fn from(value: &'a str) -> Self {
        Value::Str(Cow::Borrowed(value))
    }
}

impl<'a> From<&'a String> for Value<'a> {
    /// A string attribute.
    fn from(value: &'a String) -> Self {
        Value::Str(Cow::Borrowed(value))
    }
}

impl From<String> for Value<'static> {
    /// A string attribute.
    fn from(value: String) -> Self {
        Value::Str(Cow::Owned(value))
    }
}

impl From<i64> for Value<'_> {
    /// An integer attribute.
    fn from(value: i64) -> Self {
        Value::Int(value)
    }
}

impl From<f64> for Value<'_> {
    /// A float attribute.
    fn from(value: f64) -> Self {
        Value::Float(value)
    }
}

impl From<bool> for Value<'_> {
    /// A boolean attribute.
    fn from(value: bool) -> Self {
        Value::Bool(value)
    }
}

/// The attributes of one measurement: `(name, value)` pairs, in order.
pub type Attributes<'a> = &'a [(&'a str, Value<'a>)];

/// Owned attributes, as backends keep them.
pub type OwnedAttributes = Vec<(String, Value<'static>)>;

/// Copies borrowed attributes into owned ones.
pub fn to_owned(attributes: Attributes<'_>) -> OwnedAttributes {
    attributes.iter().map(|(k, v)| ((*k).to_owned(), v.clone().into_owned())).collect()
}

/// What a backend does with a measurement of one synchronous instrument.
/// Implement it, with [`Observe`], to add a backend.
pub trait Record: Send + Sync {
    /// Records `value` with `attributes`.
    fn record(&self, value: Number, attributes: Attributes<'_>);
}

macro_rules! instrument {
    ($(#[$doc:meta])* $name:ident, $method:ident, $what:literal) => {
        $(#[$doc])*
        #[derive(Clone)]
        pub struct $name(Arc<dyn Record>);

        impl $name {
            /// An instrument recording through `backend`.
            pub fn new(backend: Arc<dyn Record>) -> Self {
                Self(backend)
            }

            #[doc = concat!("Records ", $what, " with `attributes`.")]
            #[inline]
            pub fn $method(&self, value: impl Into<Number>, attributes: Attributes<'_>) {
                self.0.record(value.into(), attributes);
            }
        }

        impl fmt::Debug for $name {
            /// Writes the instrument's kind.
            fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
                f.write_str(stringify!($name))
            }
        }
    };
}

instrument!(
    /// A monotonic sum: add the size of each event (requests served, bytes
    /// sent).
    ///
    /// PHP: `Utopia\Telemetry\Counter` (`add()`).
    Counter,
    add,
    "`value` (an amount, never negative)"
);
instrument!(
    /// A sum that can go down: add when something starts and subtract when it
    /// ends (requests in flight).
    ///
    /// PHP: `Utopia\Telemetry\UpDownCounter` (`add()`).
    UpDownCounter,
    add,
    "`value` (negative to decrease)"
);
instrument!(
    /// A distribution of measurements (request durations), bucketed by the
    /// backend.
    ///
    /// PHP: `Utopia\Telemetry\Histogram` (`record()`).
    Histogram,
    record,
    "the measurement `value`"
);
instrument!(
    /// The current value of something; the last measurement wins.
    ///
    /// PHP: `Utopia\Telemetry\Gauge` (`record()`).
    Gauge,
    record,
    "the current `value`"
);

/// Receives the measurements of an [`ObservableGauge`] callback.
pub trait Observer {
    /// Reports `value` with `attributes`.
    fn observe(&mut self, value: Number, attributes: Attributes<'_>);
}

/// One measurement an [`ObservableGauge`] callback reported.
#[derive(Debug, Clone, PartialEq)]
pub struct Observation {
    /// The value.
    pub value: Number,
    /// Its attributes, in order.
    pub attributes: OwnedAttributes,
}

impl Observer for Vec<Observation> {
    /// Keeps the measurement.
    fn observe(&mut self, value: Number, attributes: Attributes<'_>) {
        self.push(Observation { value, attributes: to_owned(attributes) });
    }
}

/// A callback an [`ObservableGauge`] runs when the backend collects: it
/// returns the measurements to report, and may wait (on a shared store, say)
/// before it does.
pub type Callback = Arc<dyn Fn() -> BoxFuture<'static, Vec<Observation>> + Send + Sync>;

/// What a backend does with the callbacks of an observable gauge. Implement
/// it, with [`Record`], to add a backend.
pub trait Observe: Send + Sync {
    /// Adds a callback; every callback contributes its own measurements.
    fn observe(&self, callback: Callback);
}

/// A gauge read when the backend collects, by callbacks that report the
/// current values (pool sizes, queue depths), instead of being recorded on
/// every change.
///
/// Backends share one instrument per name, so several sources (one per
/// connection pool) can each add a callback and report their own series.
///
/// PHP: `Utopia\Telemetry\ObservableGauge`.
#[derive(Clone)]
pub struct ObservableGauge(Arc<dyn Observe>);

impl ObservableGauge {
    /// A gauge observing through `backend`.
    pub fn new(backend: Arc<dyn Observe>) -> Self {
        Self(backend)
    }

    /// Adds `callback`, run on every collection with an [`Observer`] to report
    /// values to.
    ///
    /// PHP: `observe(callable $callback)`, whose callback receives a
    /// `$observer(float|int $value, iterable $attributes = [])` callable.
    pub fn observe(&self, callback: impl Fn(&mut dyn Observer) + Send + Sync + 'static) {
        self.0.observe(Arc::new(move || {
            let mut observations = Vec::new();
            callback(&mut observations);
            Box::pin(std::future::ready(observations))
        }));
    }

    /// Adds an async `callback`, for values that take I/O to read (state kept
    /// in Redis): it returns the measurements to report.
    ///
    /// PHP: `observe()` with a callback that reads a shared store before it
    /// reports.
    pub fn observe_async<F, Fut>(&self, callback: F)
    where
        F: Fn() -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Vec<Observation>> + Send + 'static,
    {
        self.0.observe(Arc::new(move || Box::pin(callback())));
    }
}

impl fmt::Debug for ObservableGauge {
    /// Writes the instrument's kind.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("ObservableGauge")
    }
}

/// Creates instruments and exports what they recorded. Implement it to add a
/// backend.
///
/// Creating an instrument is cheap and records nothing: backends register an
/// instrument on its first measurement, so one that never records is never
/// exported.
///
/// PHP: `Utopia\Telemetry\Adapter`.
pub trait Telemetry: Send + Sync {
    /// A counter named `name`, with an optional unit (`{request}`, `By`) and
    /// description.
    ///
    /// PHP: `createCounter()`.
    fn counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Counter;

    /// An up/down counter named `name`.
    ///
    /// PHP: `createUpDownCounter()`.
    fn up_down_counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> UpDownCounter;

    /// A histogram named `name`, with explicit bucket boundaries when given
    /// (the backend's defaults otherwise).
    ///
    /// PHP: `createHistogram()`; `boundaries` is the
    /// `['ExplicitBucketBoundaries' => [...]]` advisory.
    fn histogram(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        boundaries: Option<&[f64]>,
    ) -> Histogram;

    /// A gauge named `name`.
    ///
    /// PHP: `createGauge()`.
    fn gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Gauge;

    /// The observable gauge named `name`: the same instrument for every call
    /// with that name, so callbacks accumulate.
    ///
    /// PHP: `createObservableGauge()`.
    fn observable_gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> ObservableGauge;

    /// Collects every instrument and exports the result. Returns whether the
    /// export succeeded (nothing to export counts as success).
    ///
    /// PHP: `collect()`.
    fn collect(&self) -> BoxFuture<'_, bool>;
}

impl<T: Telemetry + ?Sized> Telemetry for Arc<T> {
    /// The shared backend's counter.
    fn counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Counter {
        (**self).counter(name, unit, description)
    }

    /// The shared backend's up/down counter.
    fn up_down_counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> UpDownCounter {
        (**self).up_down_counter(name, unit, description)
    }

    /// The shared backend's histogram.
    fn histogram(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        boundaries: Option<&[f64]>,
    ) -> Histogram {
        (**self).histogram(name, unit, description, boundaries)
    }

    /// The shared backend's gauge.
    fn gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Gauge {
        (**self).gauge(name, unit, description)
    }

    /// The shared backend's observable gauge.
    fn observable_gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> ObservableGauge {
        (**self).observable_gauge(name, unit, description)
    }

    /// Collects the shared backend.
    fn collect(&self) -> BoxFuture<'_, bool> {
        (**self).collect()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn integer_sums_stay_integers_until_they_overflow() {
        assert_eq!(Number::Int(1) + Number::Int(2), Number::Int(3));
        assert_eq!(Number::Int(1) + Number::Float(0.5), Number::Float(1.5));
        assert_eq!(Number::Int(i64::MAX) + Number::Int(1), Number::Float(i64::MAX as f64 + 1.0));
    }

    #[test]
    fn numbers_print_like_php() {
        assert_eq!(Number::Float(1.0).to_string(), "1");
        assert_eq!(Number::Int(7).to_string(), "7");
    }
}
