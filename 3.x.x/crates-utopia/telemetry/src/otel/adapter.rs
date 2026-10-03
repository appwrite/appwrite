//! OpenTelemetry metrics adapter (PHP `Utopia\Telemetry\Adapter\OpenTelemetry`).
//!
//! Instruments are registered with the SDK meter on first write, not when they
//! are created. An unused instrument never reaches the wire - Prometheus 3.13+
//! rejects an OTLP batch that contains a metric with zero data points.

use std::collections::HashMap;
use std::fmt;
use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;
use std::time::Duration;

use bytes::Bytes;
use http::{Request, Response};
use opentelemetry::metrics::{
    AsyncInstrument, Counter as SdkCounter, Gauge as SdkGauge, Histogram as SdkHistogram, Meter,
    MeterProvider, ObservableGauge as SdkObservableGauge, UpDownCounter as SdkUpDownCounter,
};
use opentelemetry::KeyValue;
use opentelemetry_http::{HttpClient, HttpError};
use opentelemetry_otlp::{MetricExporter, Protocol, WithExportConfig, WithHttpConfig};
use opentelemetry_sdk::metrics::{PeriodicReader, SdkMeterProvider, Temporality};
use opentelemetry_sdk::Resource;
use parking_lot::Mutex;

use crate::instrument::ObserveCallback;
use crate::otel::transport::{HttpTransport, Transport, CONTENT_TYPE_PROTOBUF};
use crate::{
    Adapter, Advisory, Attributes, Counter, Gauge, Histogram, ObservableGauge, Observer,
    TelemetryError, UpDownCounter,
};

const METER_NAME: &str = "cloud";
const SCHEMA_URL: &str = "https://opentelemetry.io/schemas/1.21.0";
/// PHP `ExportingReader` is pull-based. The SDK only offers a periodic reader;
/// use a year-long interval and export from [`Adapter::collect`] via `force_flush`.
const MANUAL_COLLECT_INTERVAL: Duration = Duration::from_secs(365 * 24 * 60 * 60);

/// Dummy URI for the OTLP HTTP exporter. PHP's `MetricExporter` sends only a
/// payload; the [`Transport`] owns the real endpoint (Swoole / [`HttpTransport`]).
const TRANSPORT_OWNED_ENDPOINT: &str = "http://127.0.0.1/v1/metrics";

type BoxedSendFuture<T> = Pin<Box<dyn Future<Output = T> + Send>>;

/// Bridges Utopia [`Transport`] into the OTLP exporter (PHP `TransportInterface`).
struct TransportClient {
    transport: Arc<dyn Transport>,
}

impl fmt::Debug for TransportClient {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("TransportClient").finish_non_exhaustive()
    }
}

impl HttpClient for TransportClient {
    fn send_bytes<'a, 'async_trait>(
        &'a self,
        request: Request<Bytes>,
    ) -> BoxedSendFuture<Result<Response<Bytes>, HttpError>>
    where
        'a: 'async_trait,
        Self: 'async_trait,
    {
        let transport = Arc::clone(&self.transport);
        let body = request.into_body();
        Box::pin(async move {
            let payload = transport.send(body.as_ref())?;
            Ok(Response::builder()
                .status(200)
                .body(Bytes::from(payload))
                .expect("empty 200 response"))
        })
    }
}

fn otel_attrs(attributes: &Attributes) -> Vec<KeyValue> {
    attributes
        .iter()
        .map(|(key, value)| KeyValue::new(key.clone(), value.clone()))
        .collect()
}

struct ObserverBridge<'a> {
    inner: &'a dyn AsyncInstrument<f64>,
}

impl Observer for ObserverBridge<'_> {
    fn observe(&mut self, value: f64, attributes: &Attributes) {
        let attrs = otel_attrs(attributes);
        self.inner.observe(value, &attrs);
    }
}

struct LazyCounter {
    meter: Meter,
    name: String,
    unit: Option<String>,
    description: Option<String>,
    inner: Mutex<Option<SdkCounter<f64>>>,
}

struct LazyHistogram {
    meter: Meter,
    name: String,
    unit: Option<String>,
    description: Option<String>,
    inner: Mutex<Option<SdkHistogram<f64>>>,
}

struct LazyGauge {
    meter: Meter,
    name: String,
    unit: Option<String>,
    description: Option<String>,
    inner: Mutex<Option<SdkGauge<f64>>>,
}

struct LazyUpDown {
    meter: Meter,
    name: String,
    unit: Option<String>,
    description: Option<String>,
    inner: Mutex<Option<SdkUpDownCounter<f64>>>,
}

struct LazyObservable {
    meter: Meter,
    name: String,
    unit: Option<String>,
    description: Option<String>,
    callbacks: Arc<Mutex<Vec<ObserveCallback>>>,
    instrument: Mutex<Option<SdkObservableGauge<f64>>>,
}

impl Counter for LazyCounter {
    fn add(&self, value: f64, attributes: &Attributes) {
        let mut inner = self.inner.lock();
        let counter = inner.get_or_insert_with(|| {
            let mut builder = self.meter.f64_counter(self.name.clone());
            if let Some(unit) = &self.unit {
                builder = builder.with_unit(unit.clone());
            }
            if let Some(description) = &self.description {
                builder = builder.with_description(description.clone());
            }
            builder.build()
        });
        let attrs = otel_attrs(attributes);
        counter.add(value, &attrs);
    }
}

impl Histogram for LazyHistogram {
    fn record(&self, value: f64, attributes: &Attributes) {
        let mut inner = self.inner.lock();
        let histogram = inner.get_or_insert_with(|| {
            let mut builder = self.meter.f64_histogram(self.name.clone());
            if let Some(unit) = &self.unit {
                builder = builder.with_unit(unit.clone());
            }
            if let Some(description) = &self.description {
                builder = builder.with_description(description.clone());
            }
            builder.build()
        });
        let attrs = otel_attrs(attributes);
        histogram.record(value, &attrs);
    }
}

impl Gauge for LazyGauge {
    fn record(&self, value: f64, attributes: &Attributes) {
        let mut inner = self.inner.lock();
        let gauge = inner.get_or_insert_with(|| {
            let mut builder = self.meter.f64_gauge(self.name.clone());
            if let Some(unit) = &self.unit {
                builder = builder.with_unit(unit.clone());
            }
            if let Some(description) = &self.description {
                builder = builder.with_description(description.clone());
            }
            builder.build()
        });
        let attrs = otel_attrs(attributes);
        gauge.record(value, &attrs);
    }
}

impl UpDownCounter for LazyUpDown {
    fn add(&self, value: f64, attributes: &Attributes) {
        let mut inner = self.inner.lock();
        let counter = inner.get_or_insert_with(|| {
            let mut builder = self.meter.f64_up_down_counter(self.name.clone());
            if let Some(unit) = &self.unit {
                builder = builder.with_unit(unit.clone());
            }
            if let Some(description) = &self.description {
                builder = builder.with_description(description.clone());
            }
            builder.build()
        });
        let attrs = otel_attrs(attributes);
        counter.add(value, &attrs);
    }
}

impl ObservableGauge for LazyObservable {
    fn observe(&self, callback: ObserveCallback) {
        self.callbacks.lock().push(callback);
        let mut instrument = self.instrument.lock();
        if instrument.is_some() {
            return;
        }
        let callbacks = Arc::clone(&self.callbacks);
        let mut builder = self.meter.f64_observable_gauge(self.name.clone());
        if let Some(unit) = &self.unit {
            builder = builder.with_unit(unit.clone());
        }
        if let Some(description) = &self.description {
            builder = builder.with_description(description.clone());
        }
        *instrument = Some(
            builder
                .with_callback(move |observer| {
                    let callbacks = callbacks.lock();
                    for callback in callbacks.iter() {
                        callback(&mut ObserverBridge { inner: observer });
                    }
                })
                .build(),
        );
    }
}

struct MeterStorage {
    counters: HashMap<String, Arc<LazyCounter>>,
    histograms: HashMap<String, Arc<LazyHistogram>>,
    gauges: HashMap<String, Arc<LazyGauge>>,
    up_down: HashMap<String, Arc<LazyUpDown>>,
    observable: HashMap<String, Arc<LazyObservable>>,
}

impl MeterStorage {
    fn new() -> Self {
        Self {
            counters: HashMap::new(),
            histograms: HashMap::new(),
            gauges: HashMap::new(),
            up_down: HashMap::new(),
            observable: HashMap::new(),
        }
    }
}

/// OpenTelemetry OTLP metrics adapter.
pub struct OpenTelemetry {
    provider: SdkMeterProvider,
    meter: Meter,
    transport: Arc<dyn Transport>,
    storage: Mutex<MeterStorage>,
}

impl fmt::Debug for OpenTelemetry {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("OpenTelemetry")
            .field("provider", &self.provider)
            .field("meter", &self.meter)
            .finish_non_exhaustive()
    }
}

impl OpenTelemetry {
    /// PHP `new OpenTelemetry($endpoint, $serviceNamespace, $serviceName, $serviceInstanceId)`.
    pub fn new(
        endpoint: impl AsRef<str>,
        service_namespace: impl Into<String>,
        service_name: impl Into<String>,
        service_instance_id: impl Into<String>,
    ) -> Result<Self, TelemetryError> {
        let transport = HttpTransport::new(endpoint)?;
        Ok(Self::with_transport(
            transport,
            service_namespace,
            service_name,
            service_instance_id,
        ))
    }

    /// PHP constructor with an injected `TransportInterface`.
    pub fn with_transport(
        transport: impl Transport + 'static,
        service_namespace: impl Into<String>,
        service_name: impl Into<String>,
        service_instance_id: impl Into<String>,
    ) -> Self {
        let transport: Arc<dyn Transport> = Arc::new(transport);
        let service_namespace = service_namespace.into();
        let service_name = service_name.into();
        let service_instance_id = service_instance_id.into();

        let exporter = MetricExporter::builder()
            .with_http()
            .with_protocol(Protocol::HttpBinary)
            .with_endpoint(TRANSPORT_OWNED_ENDPOINT)
            .with_http_client(TransportClient {
                transport: Arc::clone(&transport),
            })
            .with_temporality(Temporality::Cumulative)
            .build()
            .expect("OTLP metric exporter");

        let reader = PeriodicReader::builder(exporter)
            .with_interval(MANUAL_COLLECT_INTERVAL)
            .build();

        let resource = Resource::builder_empty()
            .with_schema_url(
                [
                    KeyValue::new("service.namespace", service_namespace),
                    KeyValue::new("service.name", service_name),
                    KeyValue::new("service.instance.id", service_instance_id),
                ],
                SCHEMA_URL,
            )
            .build();

        let provider = SdkMeterProvider::builder()
            .with_resource(resource)
            .with_reader(reader)
            .build();
        let meter = provider.meter(METER_NAME);

        Self {
            provider,
            meter,
            transport,
            storage: Mutex::new(MeterStorage::new()),
        }
    }

    /// Content type used by the underlying transport.
    pub fn content_type(&self) -> &str {
        let ct = self.transport.content_type();
        if ct.is_empty() {
            CONTENT_TYPE_PROTOBUF
        } else {
            ct
        }
    }
}

fn opt_owned(value: Option<&str>) -> Option<String> {
    value.map(str::to_string)
}

impl Adapter for OpenTelemetry {
    fn create_counter(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn Counter> {
        let mut storage = self.storage.lock();
        let entry = storage.counters.entry(name.to_string()).or_insert_with(|| {
            Arc::new(LazyCounter {
                meter: self.meter.clone(),
                name: name.to_string(),
                unit: opt_owned(unit),
                description: opt_owned(description),
                inner: Mutex::new(None),
            })
        });
        entry.clone()
    }

    fn create_histogram(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn Histogram> {
        let mut storage = self.storage.lock();
        let entry = storage
            .histograms
            .entry(name.to_string())
            .or_insert_with(|| {
                Arc::new(LazyHistogram {
                    meter: self.meter.clone(),
                    name: name.to_string(),
                    unit: opt_owned(unit),
                    description: opt_owned(description),
                    inner: Mutex::new(None),
                })
            });
        entry.clone()
    }

    fn create_gauge(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn Gauge> {
        let mut storage = self.storage.lock();
        let entry = storage.gauges.entry(name.to_string()).or_insert_with(|| {
            Arc::new(LazyGauge {
                meter: self.meter.clone(),
                name: name.to_string(),
                unit: opt_owned(unit),
                description: opt_owned(description),
                inner: Mutex::new(None),
            })
        });
        entry.clone()
    }

    fn create_up_down_counter(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn UpDownCounter> {
        let mut storage = self.storage.lock();
        let entry = storage.up_down.entry(name.to_string()).or_insert_with(|| {
            Arc::new(LazyUpDown {
                meter: self.meter.clone(),
                name: name.to_string(),
                unit: opt_owned(unit),
                description: opt_owned(description),
                inner: Mutex::new(None),
            })
        });
        entry.clone()
    }

    fn create_observable_gauge(
        &self,
        name: &str,
        unit: Option<&str>,
        description: Option<&str>,
        _advisory: Advisory,
    ) -> Arc<dyn ObservableGauge> {
        let mut storage = self.storage.lock();
        let entry = storage
            .observable
            .entry(name.to_string())
            .or_insert_with(|| {
                Arc::new(LazyObservable {
                    meter: self.meter.clone(),
                    name: name.to_string(),
                    unit: opt_owned(unit),
                    description: opt_owned(description),
                    callbacks: Arc::new(Mutex::new(Vec::new())),
                    instrument: Mutex::new(None),
                })
            });
        entry.clone()
    }

    fn collect(&self) -> bool {
        self.provider.force_flush().is_ok()
    }
}
