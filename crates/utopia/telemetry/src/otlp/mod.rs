//! `OpenTelemetry`: aggregates measurements in memory and exports them as
//! OTLP/protobuf through a [`Transport`].

mod proto;
mod transport;

use std::sync::{Arc, Mutex, MutexGuard, OnceLock};
use std::time::{SystemTime, UNIX_EPOCH};

pub use transport::{HttpTransport, Transport};

use crate::{
    Attributes, BoxFuture, Callback, Counter, Gauge, Histogram, Number, ObservableGauge, Observation, Observe,
    OwnedAttributes, Record, Telemetry, UpDownCounter, Value,
};
use proto::Message;

/// The bucket boundaries of a histogram created without its own.
///
/// PHP: the OpenTelemetry SDK's default for explicit-bucket histograms.
const DEFAULT_BOUNDARIES: [f64; 10] = [0.0, 5.0, 10.0, 25.0, 50.0, 75.0, 100.0, 250.0, 500.0, 1000.0];

/// The semantic conventions version the resource attributes follow.
const SCHEMA_URL: &str = "https://opentelemetry.io/schemas/1.32.0";

/// The instrumentation scope every metric is reported under.
const SCOPE: &str = "cloud";

/// The OpenTelemetry backend: instruments aggregate in memory (cumulative
/// sums, explicit-bucket histograms, last-value gauges) and
/// [`Telemetry::collect`] exports everything as one OTLP request.
///
/// Instruments are registered on their first measurement, so an instrument
/// that never records is never exported, and metrics without data points
/// (an observable gauge whose callbacks reported nothing) are left out: a
/// collector such as Prometheus rejects a whole request over one of them.
/// When nothing is left, nothing is sent. Gauges carry no start time.
///
/// ```
/// use std::sync::{Arc, Mutex};
///
/// use utopia_telemetry::{BoxFuture, Error, OpenTelemetry, Telemetry, Transport, Value};
///
/// /// Keeps the payloads instead of sending them.
/// #[derive(Default)]
/// struct Capture(Mutex<Vec<Vec<u8>>>);
///
/// impl Transport for Capture {
///     fn content_type(&self) -> &str {
///         "application/x-protobuf"
///     }
///
///     fn send(&self, payload: Vec<u8>) -> BoxFuture<'_, Result<Vec<u8>, Error>> {
///         self.0.lock().unwrap().push(payload);
///         Box::pin(async { Ok(Vec::new()) })
///     }
///
///     fn shutdown(&self) -> bool {
///         true
///     }
/// }
///
/// let capture = Arc::new(Capture::default());
/// let telemetry = OpenTelemetry::with_transport("edge", "edge-databases", "instance", capture.clone());
/// telemetry.counter("http.requests", Some("{request}"), None).add(1, &[("route", Value::from("/v1/health"))]);
///
/// let exported = tokio::runtime::Runtime::new()?.block_on(telemetry.collect());
/// assert!(exported);
/// assert_eq!(capture.0.lock().unwrap().len(), 1);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use OpenTelemetry\Contrib\Otlp\ContentTypes;
/// use OpenTelemetry\SDK\Common\Export\TransportInterface;
/// use OpenTelemetry\SDK\Common\Future\CancellationInterface;
/// use OpenTelemetry\SDK\Common\Future\CompletedFuture;
/// use OpenTelemetry\SDK\Common\Future\FutureInterface;
/// use Utopia\Telemetry\Adapter\OpenTelemetry;
///
/// $capture = new class () implements TransportInterface {
///     public array $payloads = [];
///     public function contentType(): string { return ContentTypes::PROTOBUF; }
///     public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
///     {
///         $this->payloads[] = $payload;
///         return new CompletedFuture(null);
///     }
///     public function shutdown(?CancellationInterface $cancellation = null): bool { return true; }
///     public function forceFlush(?CancellationInterface $cancellation = null): bool { return true; }
/// };
///
/// $telemetry = new OpenTelemetry('http://localhost:4318/v1/metrics', 'edge', 'edge-databases', 'instance', $capture);
/// $telemetry->createCounter('http.requests', '{request}')->add(1, ['route' => '/v1/health']);
///
/// var_dump($telemetry->collect()); // bool(true)
/// echo count($capture->payloads), "\n"; // 1
/// ```
///
/// PHP: `Utopia\Telemetry\Adapter\OpenTelemetry`, with its
/// `OpenTelemetry\MetricExporter` wrapper.
#[derive(Clone)]
pub struct OpenTelemetry {
    inner: Arc<Inner>,
}

struct Inner {
    transport: Arc<dyn Transport>,
    resource: OwnedAttributes,
    /// Instruments by kind and name: creating one twice returns the first.
    instruments: Mutex<Vec<(Kind, String, Instrument)>>,
    /// Streams in the order they were registered (first measurement).
    streams: Mutex<Vec<Arc<Stream>>>,
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum Kind {
    Counter,
    UpDownCounter,
    Histogram,
    Gauge,
    ObservableGauge,
}

#[derive(Clone)]
enum Instrument {
    Counter(Counter),
    UpDownCounter(UpDownCounter),
    Histogram(Histogram),
    Gauge(Gauge),
    ObservableGauge(ObservableGauge),
}

/// What a stream needs to describe its metric.
struct Meta {
    kind: Kind,
    name: String,
    unit: String,
    description: String,
    boundaries: Vec<f64>,
}

/// One registered instrument and what it aggregated.
struct Stream {
    meta: Meta,
    start: u64,
    state: Mutex<State>,
}

#[derive(Clone)]
struct Summary {
    count: u64,
    sum: Number,
    min: f64,
    max: f64,
    buckets: Vec<u64>,
}

enum State {
    /// Sums and last values, by attribute set in order of first appearance.
    Numbers(Vec<(OwnedAttributes, Number)>),
    Histogram(Vec<(OwnedAttributes, Summary)>),
    Observable(Vec<Callback>),
}

fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

fn now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| d.as_nanos() as u64)
}

fn same(owned: &OwnedAttributes, attributes: Attributes<'_>) -> bool {
    owned.len() == attributes.len() && owned.iter().zip(attributes).all(|((k, v), (k2, v2))| k == k2 && v == v2)
}

/// A synchronous instrument: registers its stream on the first measurement.
struct Lazy {
    inner: Arc<Inner>,
    meta: Mutex<Option<Meta>>,
    stream: OnceLock<Arc<Stream>>,
}

impl Lazy {
    fn stream(&self) -> &Arc<Stream> {
        self.stream.get_or_init(|| {
            let meta = lock(&self.meta).take().expect("an instrument registers once");
            let state = match meta.kind {
                Kind::Histogram => State::Histogram(Vec::new()),
                Kind::ObservableGauge => State::Observable(Vec::new()),
                _ => State::Numbers(Vec::new()),
            };
            let stream = Arc::new(Stream { meta, start: now(), state: Mutex::new(state) });
            lock(&self.inner.streams).push(stream.clone());
            stream
        })
    }
}

impl Record for Lazy {
    fn record(&self, value: Number, attributes: Attributes<'_>) {
        let stream = self.stream();
        let mut state = lock(&stream.state);
        match &mut *state {
            State::Numbers(series) => {
                let last = stream.meta.kind == Kind::Gauge;
                match series.iter_mut().find(|(a, _)| same(a, attributes)) {
                    Some((_, n)) => *n = if last { value } else { *n + value },
                    None => {
                        series.push((crate::to_owned(attributes), if last { value } else { Number::Int(0) + value }))
                    }
                }
            }
            State::Histogram(series) => {
                let index = match series.iter().position(|(a, _)| same(a, attributes)) {
                    Some(i) => i,
                    None => {
                        let buckets = vec![0; stream.meta.boundaries.len() + 1];
                        let summary = Summary {
                            count: 0,
                            sum: Number::Int(0),
                            min: f64::INFINITY,
                            max: f64::NEG_INFINITY,
                            buckets,
                        };
                        series.push((crate::to_owned(attributes), summary));
                        series.len() - 1
                    }
                };
                let summary = &mut series[index].1;
                let x = value.as_f64();
                let bucket = stream.meta.boundaries.iter().take_while(|b| **b < x).count();
                summary.count += 1;
                summary.sum = summary.sum + value;
                summary.min = if x <= summary.min {
                    x
                } else if summary.min <= x {
                    summary.min
                } else {
                    f64::NAN
                };
                summary.max = if x >= summary.max {
                    x
                } else if summary.max >= x {
                    summary.max
                } else {
                    f64::NAN
                };
                summary.buckets[bucket] += 1;
            }
            State::Observable(_) => {}
        }
    }
}

impl Observe for Lazy {
    fn observe(&self, callback: Callback) {
        let stream = self.stream();
        if let State::Observable(callbacks) = &mut *lock(&stream.state) {
            callbacks.push(callback);
        }
    }
}

impl OpenTelemetry {
    /// A backend exporting to the OTLP/HTTP `endpoint`
    /// (`http://collector:4318/v1/metrics`) through an [`HttpTransport`],
    /// reporting under a resource named by `service_namespace`,
    /// `service_name` and `service_instance_id`.
    ///
    /// Fails with [`crate::Error::InvalidArgument`] when the endpoint is not a URL.
    ///
    /// PHP: `new OpenTelemetry($endpoint, $serviceNamespace, $serviceName,
    /// $serviceInstanceId)`.
    pub fn new(
        endpoint: &str,
        service_namespace: &str,
        service_name: &str,
        service_instance_id: &str,
    ) -> Result<Self, crate::Error> {
        let transport = HttpTransport::new(endpoint)?;
        Ok(Self::with_transport(service_namespace, service_name, service_instance_id, Arc::new(transport)))
    }

    /// A backend exporting through `transport`.
    ///
    /// PHP: the constructor's `$transport` argument (the endpoint is then
    /// unused).
    pub fn with_transport(
        service_namespace: &str,
        service_name: &str,
        service_instance_id: &str,
        transport: Arc<dyn Transport>,
    ) -> Self {
        let resource = vec![
            ("service.namespace".to_owned(), Value::from(service_namespace.to_owned())),
            ("service.name".to_owned(), Value::from(service_name.to_owned())),
            ("service.instance.id".to_owned(), Value::from(service_instance_id.to_owned())),
        ];
        OpenTelemetry {
            inner: Arc::new(Inner {
                transport,
                resource,
                instruments: Mutex::new(Vec::new()),
                streams: Mutex::new(Vec::new()),
            }),
        }
    }

    /// The transport exports go through.
    pub fn transport(&self) -> &Arc<dyn Transport> {
        &self.inner.transport
    }

    fn instrument(
        &self,
        kind: Kind,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        boundaries: Option<&[f64]>,
        wrap: impl FnOnce(Arc<Lazy>) -> Instrument,
    ) -> Instrument {
        let mut instruments = lock(&self.inner.instruments);
        if let Some((_, _, i)) = instruments.iter().find(|(k, n, _)| *k == kind && n == name) {
            return i.clone();
        }
        let meta = Meta {
            kind,
            name: name.to_owned(),
            unit: unit.unwrap_or_default().to_owned(),
            description: description.unwrap_or_default().to_owned(),
            boundaries: boundaries.map_or_else(|| DEFAULT_BOUNDARIES.to_vec(), <[f64]>::to_vec),
        };
        let lazy = Arc::new(Lazy { inner: self.inner.clone(), meta: Mutex::new(Some(meta)), stream: OnceLock::new() });
        let instrument = wrap(lazy);
        instruments.push((kind, name.to_owned(), instrument.clone()));
        instrument
    }

    /// The OTLP `ExportMetricsServiceRequest` for everything recorded so far,
    /// `None` when no metric has a data point.
    async fn request(&self) -> Option<Vec<u8>> {
        let streams: Vec<Arc<Stream>> = lock(&self.inner.streams).clone();
        let time = now();
        let mut metrics = Message::default();
        let mut any = false;
        for stream in streams {
            let observed = {
                let callbacks = match &*lock(&stream.state) {
                    State::Observable(callbacks) => Some(callbacks.clone()),
                    _ => None,
                };
                match callbacks {
                    Some(callbacks) => {
                        let mut series: Vec<(OwnedAttributes, Number)> = Vec::new();
                        for callback in callbacks {
                            for Observation { value, attributes } in callback().await {
                                match series.iter_mut().find(|(a, _)| *a == attributes) {
                                    Some((_, n)) => *n = value,
                                    None => series.push((attributes, value)),
                                }
                            }
                        }
                        Some(series)
                    }
                    None => None,
                }
            };
            let state = lock(&stream.state);
            let meta = &stream.meta;
            let encoded = match (&*state, observed) {
                (_, Some(series)) => gauge(&series, time),
                (State::Numbers(series), None) if meta.kind == Kind::Gauge => gauge(series, time),
                (State::Numbers(series), None) => sum(series, meta.kind == Kind::Counter, stream.start, time),
                (State::Histogram(series), None) => histogram(series, &meta.boundaries, stream.start, time),
                (State::Observable(_), None) => None,
            };
            if let Some((field, data)) = encoded {
                any = true;
                metrics.message(2, |m| {
                    m.string(1, &meta.name);
                    m.string(2, &meta.description);
                    m.string(3, &meta.unit);
                    m.bytes(field, &data.0);
                });
            }
        }
        if !any {
            return None;
        }
        let mut request = Message::default();
        request.message(1, |rm| {
            rm.message(1, |resource| {
                for (k, v) in &self.inner.resource {
                    resource.key_value(1, k, v);
                }
            });
            rm.message(2, |sm| {
                sm.message(1, |scope| scope.string(1, SCOPE));
                sm.0.extend_from_slice(&metrics.0);
            });
            rm.string(3, SCHEMA_URL);
        });
        Some(request.0)
    }
}

fn number_point(m: &mut Message, attributes: &OwnedAttributes, value: Number, start: u64, time: u64) {
    m.message(1, |p| {
        for (k, v) in attributes {
            p.key_value(7, k, v);
        }
        p.fixed64(2, start);
        p.fixed64(3, time);
        match value {
            Number::Int(i) => p.sfixed64(6, i),
            Number::Float(f) => p.double(4, f),
        }
    });
}

/// A `Gauge` (field 5 of `Metric`), without start times.
fn gauge(series: &[(OwnedAttributes, Number)], time: u64) -> Option<(u64, Message)> {
    if series.is_empty() {
        return None;
    }
    let mut data = Message::default();
    for (attributes, value) in series {
        number_point(&mut data, attributes, *value, 0, time);
    }
    Some((5, data))
}

/// A cumulative `Sum` (field 7 of `Metric`).
fn sum(series: &[(OwnedAttributes, Number)], monotonic: bool, start: u64, time: u64) -> Option<(u64, Message)> {
    if series.is_empty() {
        return None;
    }
    let mut data = Message::default();
    for (attributes, value) in series {
        number_point(&mut data, attributes, *value, start, time);
    }
    data.uint(2, 2);
    data.uint(3, u64::from(monotonic));
    Some((7, data))
}

/// A cumulative explicit-bucket `Histogram` (field 9 of `Metric`).
fn histogram(series: &[(OwnedAttributes, Summary)], bounds: &[f64], start: u64, time: u64) -> Option<(u64, Message)> {
    let mut data = Message::default();
    let mut any = false;
    for (attributes, summary) in series.iter().filter(|(_, s)| s.count > 0) {
        any = true;
        data.message(1, |p| {
            for (k, v) in attributes {
                p.key_value(9, k, v);
            }
            p.fixed64(2, start);
            p.fixed64(3, time);
            p.fixed64(4, summary.count);
            p.double(5, summary.sum.as_f64());
            p.packed_fixed64(6, &summary.buckets);
            p.packed_double(7, bounds);
            p.double(11, summary.min);
            p.double(12, summary.max);
        });
    }
    if !any {
        return None;
    }
    data.uint(2, 2);
    Some((9, data))
}

impl Telemetry for OpenTelemetry {
    /// A cumulative monotonic sum.
    fn counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Counter {
        match self.instrument(Kind::Counter, name, unit, description, None, |l| Instrument::Counter(Counter::new(l))) {
            Instrument::Counter(c) => c,
            _ => unreachable!("instruments are cached by kind"),
        }
    }

    /// A cumulative non-monotonic sum.
    fn up_down_counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> UpDownCounter {
        match self.instrument(Kind::UpDownCounter, name, unit, description, None, |l| {
            Instrument::UpDownCounter(UpDownCounter::new(l))
        }) {
            Instrument::UpDownCounter(c) => c,
            _ => unreachable!("instruments are cached by kind"),
        }
    }

    /// An explicit-bucket histogram (default boundaries 0, 5, 10, 25, 50,
    /// 75, 100, 250, 500 and 1000).
    fn histogram(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        boundaries: Option<&[f64]>,
    ) -> Histogram {
        match self.instrument(Kind::Histogram, name, unit, description, boundaries, |l| {
            Instrument::Histogram(Histogram::new(l))
        }) {
            Instrument::Histogram(h) => h,
            _ => unreachable!("instruments are cached by kind"),
        }
    }

    /// A last-value gauge.
    fn gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Gauge {
        match self.instrument(Kind::Gauge, name, unit, description, None, |l| Instrument::Gauge(Gauge::new(l))) {
            Instrument::Gauge(g) => g,
            _ => unreachable!("instruments are cached by kind"),
        }
    }

    /// The observable gauge `name`, registered on its first callback.
    fn observable_gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> ObservableGauge {
        match self.instrument(Kind::ObservableGauge, name, unit, description, None, |l| {
            Instrument::ObservableGauge(ObservableGauge::new(l))
        }) {
            Instrument::ObservableGauge(g) => g,
            _ => unreachable!("instruments are cached by kind"),
        }
    }

    /// Runs the observable gauges' callbacks and sends every metric with a
    /// data point in one request; `false` when the transport fails.
    fn collect(&self) -> BoxFuture<'_, bool> {
        Box::pin(async move {
            match self.request().await {
                None => true,
                Some(payload) => self.inner.transport.send(payload).await.is_ok(),
            }
        })
    }
}
