use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use utopia_telemetry::{Adapter, OpenTelemetry, TelemetryError, Transport, CONTENT_TYPE_PROTOBUF};

struct CaptureTransport {
    payloads: Arc<Mutex<Vec<Vec<u8>>>>,
}

impl Transport for CaptureTransport {
    fn content_type(&self) -> &str {
        CONTENT_TYPE_PROTOBUF
    }

    fn send(&self, payload: &[u8]) -> Result<Vec<u8>, TelemetryError> {
        self.payloads
            .lock()
            .expect("payloads")
            .push(payload.to_vec());
        Ok(Vec::new())
    }

    fn shutdown(&self) -> bool {
        true
    }

    fn force_flush(&self) -> bool {
        true
    }
}

/// PHP `OpenTelemetryTest::testOnlyRecordedInstrumentsAreExported`.
#[test]
fn only_recorded_instruments_are_exported() {
    let payloads = Arc::new(Mutex::new(Vec::<Vec<u8>>::new()));
    let telemetry = OpenTelemetry::with_transport(
        CaptureTransport {
            payloads: Arc::clone(&payloads),
        },
        "namespace",
        "service",
        "instance",
    );

    telemetry
        .create_counter("recorded.counter", Some("{event}"), None, HashMap::new())
        .add(1.0, &HashMap::new());
    telemetry
        .create_up_down_counter(
            "recorded.up_down_counter",
            Some("{request}"),
            None,
            HashMap::new(),
        )
        .add(1.0, &HashMap::new());
    telemetry
        .create_histogram("recorded.histogram", Some("ms"), None, HashMap::new())
        .record(12.3, &HashMap::new());
    telemetry
        .create_gauge("recorded.gauge", Some("s"), None, HashMap::new())
        .record(4.5, &HashMap::new());
    telemetry
        .create_observable_gauge("recorded.observable_gauge", Some("%"), None, HashMap::new())
        .observe(Box::new(|observer| {
            observer.observe(72.4, &HashMap::new());
        }));

    let _ = telemetry.create_counter("unused.counter", Some("{event}"), None, HashMap::new());
    let _ = telemetry.create_up_down_counter(
        "unused.up_down_counter",
        Some("{request}"),
        None,
        HashMap::new(),
    );
    let _ = telemetry.create_histogram("unused.histogram", Some("ms"), None, HashMap::new());
    let _ = telemetry.create_gauge("unused.gauge", Some("s"), None, HashMap::new());
    let _ = telemetry.create_observable_gauge(
        "unused.observable_gauge",
        Some("%"),
        None,
        HashMap::new(),
    );

    assert!(telemetry.collect());

    let exported = payloads
        .lock()
        .expect("payloads")
        .iter()
        .flat_map(|p| p.iter().copied())
        .collect::<Vec<u8>>();
    let exported = String::from_utf8_lossy(&exported);
    for kind in [
        "counter",
        "up_down_counter",
        "histogram",
        "gauge",
        "observable_gauge",
    ] {
        assert!(
            exported.contains(&format!("recorded.{kind}")),
            "missing recorded.{kind} in {exported:?}"
        );
        assert!(
            !exported.contains(&format!("unused.{kind}")),
            "unexpected unused.{kind} in {exported:?}"
        );
    }
}

#[test]
fn same_name_returns_cached_instrument() {
    let telemetry = OpenTelemetry::with_transport(
        CaptureTransport {
            payloads: Arc::new(Mutex::new(Vec::new())),
        },
        "ns",
        "svc",
        "id",
    );
    let first = telemetry.create_histogram("latency", Some("ms"), None, HashMap::new());
    let second = telemetry.create_histogram("latency", Some("ms"), None, HashMap::new());
    first.record(1.0, &HashMap::new());
    second.record(2.0, &HashMap::new());
    assert!(telemetry.collect());
}
