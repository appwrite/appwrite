use std::collections::HashMap;

use utopia_telemetry::{
    attrs, lazy_counter, lazy_gauge, lazy_histogram, lazy_up_down_counter, Adapter, NoneAdapter,
    TestAdapter,
};

#[test]
fn none_adapter_counter_is_noop() {
    let telemetry = NoneAdapter::new();
    let counter = telemetry.create_counter(
        "http.server.requests",
        Some("{request}"),
        Some("Total HTTP requests"),
        HashMap::new(),
    );

    counter.add(1.0, &HashMap::new());
    counter.add(2.0, &attrs(&[("method", "GET")]));
    assert!(telemetry.collect());
    assert!(!telemetry.enabled());
}

#[test]
fn none_adapter_histogram_is_noop() {
    let telemetry = NoneAdapter::new();
    let histogram = telemetry.create_histogram(
        "http.server.request.duration",
        Some("ms"),
        Some("HTTP request duration"),
        HashMap::new(),
    );

    histogram.record(142.0, &HashMap::new());
    histogram.record(98.5, &attrs(&[("route", "/api/users")]));
    assert!(telemetry.collect());
}

#[test]
fn none_adapter_gauge_and_up_down_counter_are_noop() {
    let telemetry = NoneAdapter::new();

    let gauge = telemetry.create_gauge("memory.usage", Some("By"), None, HashMap::new());
    gauge.record(1024.0, &HashMap::new());

    let up_down =
        telemetry.create_up_down_counter("connections.active", None, None, HashMap::new());
    up_down.add(1.0, &HashMap::new());
    up_down.add(-1.0, &HashMap::new());

    let observable = telemetry.create_observable_gauge("cpu.load", None, None, HashMap::new());
    observable.observe(Box::new(|observer| {
        observer.observe(1.0, &HashMap::new());
    }));
    assert!(telemetry.collect());
}

#[test]
fn test_adapter_does_not_register_until_first_write() {
    let telemetry = TestAdapter::new();
    let _counter = telemetry.create_counter("requests.total", None, None, HashMap::new());
    assert!(telemetry.snapshot().counters.is_empty());
    assert!(telemetry.counter_names().is_empty());
}

#[test]
fn test_adapter_records_counter_with_attributes() {
    let telemetry = TestAdapter::new();
    let counter = telemetry.create_counter("requests.total", None, None, HashMap::new());

    counter.add(1.0, &HashMap::new());
    counter.add(1.0, &attrs(&[("method", "GET"), ("status", "200")]));

    let measurements = telemetry.counter_measurements("requests.total");
    assert_eq!(measurements.len(), 2);
    assert_eq!(measurements[0].value.to_bits(), 1.0f64.to_bits());
    assert!(measurements[0].attributes.is_empty());
    assert_eq!(
        measurements[1].attributes.get("method").map(String::as_str),
        Some("GET")
    );
    assert_eq!(
        measurements[1].attributes.get("status").map(String::as_str),
        Some("200")
    );
}

#[test]
fn test_adapter_records_histogram_snapshot() {
    let telemetry = TestAdapter::new();
    let histogram = telemetry.create_histogram("latency", Some("ms"), None, HashMap::new());

    histogram.record(12.5, &attrs(&[("route", "/health")]));
    histogram.record(48.0, &HashMap::new());

    let snapshot = telemetry.snapshot();
    let latency = snapshot
        .histograms
        .get("latency")
        .expect("histogram present");
    assert_eq!(latency.len(), 2);
    assert_eq!(latency[0].value.to_bits(), 12.5f64.to_bits());
    assert_eq!(
        latency[0].attributes.get("route").map(String::as_str),
        Some("/health")
    );
    assert_eq!(latency[1].value.to_bits(), 48.0f64.to_bits());
}

#[test]
fn test_adapter_records_gauge_and_up_down_counter() {
    let telemetry = TestAdapter::new();

    let gauge = telemetry.create_gauge("queue.depth", None, None, HashMap::new());
    gauge.record(3.0, &attrs(&[("queue", "default")]));

    let up_down = telemetry.create_up_down_counter("workers", None, None, HashMap::new());
    up_down.add(2.0, &HashMap::new());
    up_down.add(-1.0, &attrs(&[("pool", "a")]));

    let snapshot = telemetry.snapshot();
    assert_eq!(
        snapshot.gauges["queue.depth"][0].value.to_bits(),
        3.0f64.to_bits()
    );
    assert_eq!(snapshot.up_down_counters["workers"].len(), 2);
    assert_eq!(
        snapshot.up_down_counters["workers"][1].value.to_bits(),
        (-1.0f64).to_bits()
    );
}

#[test]
fn test_adapter_collect_returns_true() {
    let telemetry = TestAdapter::new();
    assert!(telemetry.collect());
}

#[test]
fn lazy_counter_creates_inner_on_first_add() {
    let telemetry = TestAdapter::new();
    let counter = lazy_counter(
        &telemetry,
        "events.total",
        Some("{event}"),
        Some("Event count"),
        HashMap::new(),
    );

    assert!(telemetry.snapshot().counters.is_empty());

    counter.add(1.0, &attrs(&[("event.name", "created")]));
    assert_eq!(telemetry.counter_values("events.total"), vec![1.0]);

    counter.add(2.0, &HashMap::new());
    assert_eq!(telemetry.counter_values("events.total"), vec![1.0, 2.0]);
}

#[test]
fn lazy_gauge_creates_inner_on_first_record() {
    let telemetry = TestAdapter::new();
    let gauge = lazy_gauge(
        &telemetry,
        "event.timestamp",
        Some("s"),
        Some("Event timestamp"),
        HashMap::new(),
    );

    assert!(telemetry.snapshot().gauges.is_empty());
    gauge.record(123.45, &attrs(&[("event.name", "transition")]));
    assert_eq!(telemetry.gauge_values("event.timestamp"), vec![123.45]);
    gauge.record(456.78, &HashMap::new());
    assert_eq!(
        telemetry.gauge_values("event.timestamp"),
        vec![123.45, 456.78]
    );
}

#[test]
fn lazy_histogram_creates_inner_on_first_record() {
    let telemetry = TestAdapter::new();
    let histogram = lazy_histogram(
        &telemetry,
        "request.duration",
        Some("ms"),
        Some("Request duration"),
        HashMap::new(),
    );

    assert!(telemetry.snapshot().histograms.is_empty());
    histogram.record(12.3, &attrs(&[("route", "/v1/health")]));
    assert_eq!(telemetry.histogram_values("request.duration"), vec![12.3]);
    histogram.record(45.6, &HashMap::new());
    assert_eq!(
        telemetry.histogram_values("request.duration"),
        vec![12.3, 45.6]
    );
}

#[test]
fn lazy_up_down_counter_creates_inner_on_first_add() {
    let telemetry = TestAdapter::new();
    let counter = lazy_up_down_counter(
        &telemetry,
        "active.requests",
        Some("{request}"),
        Some("Active requests"),
        HashMap::new(),
    );

    assert!(telemetry.snapshot().up_down_counters.is_empty());
    counter.add(1.0, &attrs(&[("route", "/v1/health")]));
    assert_eq!(
        telemetry.up_down_counter_values("active.requests"),
        vec![1.0]
    );
    counter.add(-1.0, &HashMap::new());
    assert_eq!(
        telemetry.up_down_counter_values("active.requests"),
        vec![1.0, -1.0]
    );
}

#[test]
fn observable_gauge_registers_on_observe() {
    let telemetry = TestAdapter::new();
    let gauge = telemetry.create_observable_gauge("cpu.load", Some("%"), None, HashMap::new());
    assert!(!telemetry.observable_gauge_observed("cpu.load"));
    gauge.observe(Box::new(|observer| {
        observer.observe(72.4, &HashMap::new());
    }));
    assert!(telemetry.observable_gauge_observed("cpu.load"));
}
