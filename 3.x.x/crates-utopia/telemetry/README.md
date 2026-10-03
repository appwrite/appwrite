# utopia-telemetry

Metrics telemetry adapters for Utopia. Rust port of [utopia-php/telemetry](https://github.com/utopia-php/telemetry).

Adapters: **None** (no-op), **Test** (in-memory recordings), and **OpenTelemetry** (official OTel SDK + OTLP/HTTP protobuf via [`HttpTransport`](#httptransport)). Instruments reach the exporter only after the first write - unused instruments are never exported, matching PHP (Prometheus 3.13+ rejects empty metrics and drops the whole OTLP batch).

## Install

```toml
utopia-telemetry = { path = "../utopia-telemetry" } # workspace
```

## Usage

```rust
use std::collections::HashMap;
use utopia_telemetry::{attrs, Adapter, NoneAdapter, OpenTelemetry, TestAdapter};

// Production no-op (default when telemetry is disabled)
let telemetry = NoneAdapter::new();
let counter = telemetry.create_counter(
    "http.server.requests",
    Some("{request}"),
    Some("Total HTTP requests"),
    HashMap::new(),
);
counter.add(1.0, &attrs(&[("method", "GET"), ("status", "200")]));

// OpenTelemetry OTLP/HTTP (PHP `new OpenTelemetry($endpoint, $namespace, $name, $instanceId)`)
let otel = OpenTelemetry::new(
    "http://localhost:4318/v1/metrics",
    "namespace",
    "app",
    "unique-instance-id",
).expect("endpoint");
otel.collect();

// Tests: in-memory recordings with snapshot assertions
let test = TestAdapter::new();
let histogram = test.create_histogram(
    "http.server.request.duration",
    Some("ms"),
    None,
    HashMap::new(),
);
histogram.record(142.0, &attrs(&[("route", "/api/users")]));

let snapshot = test.snapshot();
assert_eq!(snapshot.histograms["http.server.request.duration"][0].value, 142.0);
```

## Prelude

```rust
use utopia_telemetry::prelude::*;
// NoneAdapter, TestAdapter, OpenTelemetry, HttpTransport,
// Adapter, Attributes, Counter, Gauge, Histogram, ObservableGauge, UpDownCounter, TelemetryError
```

**Not in prelude:** `Advisory`, `attrs`, `lazy_*`, `adapters::{RecordedMeasurement, TestSnapshot}`, `Transport`, content-type constants.

## API Reference

### Type aliases

```rust
pub type Attributes = HashMap<String, String>;  // measurement labels
pub type Advisory = HashMap<String, String>;    // create-time hints (e.g. buckets)
```

PHP allows mixed attribute values (`string|int|float|bool|null|array`). This port stores labels as strings; callers already pass string maps (`utopia-http`, `utopia-storage`).

### `Adapter` trait

```rust
pub trait Adapter: Send + Sync {
    fn create_counter(...) -> Arc<dyn Counter>;
    fn create_histogram(...) -> Arc<dyn Histogram>;
    fn create_gauge(...) -> Arc<dyn Gauge>;
    fn create_up_down_counter(...) -> Arc<dyn UpDownCounter>;
    fn create_observable_gauge(...) -> Arc<dyn ObservableGauge>;
    fn collect(&self) -> bool;   // flush/export; true on success
    fn enabled(&self) -> bool;   // default true; false → callers may skip attr build
}
```

When `enabled() == false` (e.g. `NoneAdapter`), skip building attributes and recording - `utopia-http` does this via `metrics_enabled`. `enabled()` is a Rust-only addition (PHP has no equivalent).

### Instrument traits

| Trait | Method | Description |
|-------|--------|-------------|
| `Counter` | `fn add(&self, value: f64, attributes: &Attributes)` | Monotonically increasing (non-negative expected). |
| `Histogram` | `fn record(&self, value: f64, attributes: &Attributes)` | Value distribution. |
| `UpDownCounter` | `fn add(&self, value: f64, attributes: &Attributes)` | Can increase or decrease. |
| `Gauge` | `fn record(&self, value: f64, attributes: &Attributes)` | Latest value. |
| `ObservableGauge` | `fn observe(&self, callback: ObserveCallback)` | Callback invoked at `collect`; receives an `Observer`. |

`Observer::observe(value, attributes)` is PHP `$observer(float|int $value, iterable $attributes = [])`.

### Helpers

```rust
pub fn attrs(pairs: &[(&str, &str)]) -> HashMap<String, String>;

pub fn lazy_counter(adapter: &dyn Adapter, name, unit, description, advisory) -> Arc<dyn Counter>;
pub fn lazy_histogram(...) -> Arc<dyn Histogram>;
pub fn lazy_gauge(...) -> Arc<dyn Gauge>;
pub fn lazy_up_down_counter(...) -> Arc<dyn UpDownCounter>;
```

`lazy_*` match PHP `Counter::lazy()` / `Histogram::lazy()` / … (deprecated shims that call `create_*`). Adapters themselves defer exporter registration until the first write.

### `NoneAdapter`

```rust
#[derive(Debug, Default, Clone, Copy)]
pub struct NoneAdapter;
```

Implements `Adapter`: returns process-wide shared noop instruments (`OnceLock`); `collect` → `true`; **`enabled` → `false`**.

### `TestAdapter`

In-memory recorder. Instruments appear in snapshots **on first write** (PHP `Adapter\Test`). Creating the same name twice yields independent instruments; the last writer is the map entry. Observable gauges are cached by name; callbacks accumulate.

### `OpenTelemetry`

PHP `new OpenTelemetry($endpoint, $serviceNamespace, $serviceName, $serviceInstanceId, $transport = null)`.

| Method | Description |
|--------|-------------|
| `new(endpoint, namespace, name, instance_id)` | Builds an [`HttpTransport`](#httptransport) (protobuf OTLP/HTTP). |
| `with_transport(transport, namespace, name, instance_id)` | Inject a [`Transport`](#transport) (tests). |
| `collect` | `SdkMeterProvider::force_flush` → OTLP protobuf over [`Transport`](#transport). |

Resource attributes: `service.namespace`, `service.name`, `service.instance.id`. Meter name is `cloud` (PHP). Schema URL `https://opentelemetry.io/schemas/1.21.0`.

### `HttpTransport`

Rust equivalent of PHP `Utopia\Telemetry\Adapter\OpenTelemetry\Transport\Swoole` (Swoole coroutine pool → reqwest keep-alive pool).

| Method | Description |
|--------|-------------|
| `new(endpoint)` | Protobuf, 10s timeout, pool size 8. |
| `new_with(endpoint, content_type, headers, timeout, pool_size, socket_buffer_size)` | Full PHP constructor. |
| `content_type` | `application/x-protobuf` (default), `application/json`, or `application/x-ndjson`. |
| `send` | POST payload. Errors: `Transport has been shut down`, `OTLP connection failed: …`, `OTLP export failed with status …`. |
| `shutdown` / `force_flush` | Drop the client / no-op. |

Malformed endpoints that make PHP `parse_url` return `false` (e.g. `http:///v1/metrics`) raise `TelemetryError::InvalidEndpoint` (`Invalid endpoint URL: {endpoint}`).

### `Transport` trait

PHP `OpenTelemetry\SDK\Common\Export\TransportInterface`:

```rust
pub trait Transport: Send + Sync {
    fn content_type(&self) -> &str;
    fn send(&self, payload: &[u8]) -> Result<Vec<u8>, TelemetryError>;
    fn shutdown(&self) -> bool;
    fn force_flush(&self) -> bool;
}
```

### `TelemetryError`

| Variant | PHP message |
|---------|-------------|
| `InvalidEndpoint` | `Invalid endpoint URL: {endpoint}` |
| `TransportShutdown` | `Transport has been shut down` |
| `ConnectionFailed` | `OTLP connection failed: {message} (code: {code})` |
| `ExportFailed` | `OTLP export failed with status {status}: {body}` |

### Intentional deviations

- PHP `Transport\Swoole` is named [`HttpTransport`](#httptransport) (Tokio/reqwest). Constructor args, content types, and error messages match.
- Attribute maps are `HashMap<String, String>` rather than mixed PHP iterables.
- `Adapter::enabled()` is Rust-only (skip-path for `NoneAdapter`).
- Metrics aggregation and OTLP protobuf encoding use the official `opentelemetry` / `opentelemetry_sdk` / `opentelemetry-otlp` crates (same role as PHP `open-telemetry/sdk` + `open-telemetry/exporter-otlp`). The Utopia [`HttpTransport`](#httptransport) is injected as the OTLP HTTP client, matching PHP `MetricExporter($transport)`.
- The adapter does **not** install a process-global meter provider (`Sdk::buildAndRegisterGlobal()` in PHP). Each `OpenTelemetry` instance owns its `SdkMeterProvider`.
- `collect()` maps to `SdkMeterProvider::force_flush()`. The SDK reader is periodic with a year-long interval so export is pull-based like PHP `ExportingReader`.

## Tests

```bash
cargo test -p utopia-telemetry
```

Ports PHP `LazyInstrumentTest`, `OpenTelemetryTest`, and Swoole transport unit/integration tests (utopia-test-wiremock instead of a Swoole mock server). Default CI does not need a live OTLP collector.

## Benchmarks

```bash
cargo bench -p utopia-telemetry
```

PHP twin: `benchmarks/telemetry/`.

## Code quality

This crate inherits workspace linting:

- **rustfmt** - `cargo fmt -p <crate>` (config: repo-root `rustfmt.toml`)
- **Clippy + rustc lints** - `cargo clippy -p <crate> --all-targets -- -D warnings`
- **Docs** - `cargo doc -p <crate> --no-deps` (`RUSTDOCFLAGS=-Dwarnings` in CI)
- **Supply chain** - `cargo deny check` (config: `deny.toml`)
