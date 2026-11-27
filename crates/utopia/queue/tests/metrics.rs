//! A worker's metrics, end to end: a message `Broker\Nats` would have published is received
//! and committed through a real JetStream server, and the SDK the application installed
//! exports the three histograms `Utopia\Queue\Server` records.
//!
//! Needs a JetStream server, so it does not run by default:
//!
//! ```sh
//! docker run -d --rm -p 4222:4222 nats:2.11-alpine -js
//! NATS_URL=nats://127.0.0.1:4222 cargo test -p utopia-queue --test metrics -- --ignored
//! ```

#![cfg(feature = "nats")]

use std::time::{Duration, SystemTime, UNIX_EPOCH};

use async_nats::jetstream::{self, consumer, stream};
use opentelemetry_sdk::metrics::data::{AggregatedMetrics, MetricData};
use opentelemetry_sdk::metrics::{InMemoryMetricExporter, PeriodicReader, SdkMeterProvider};
use utopia_queue::jetstream::Worker;

#[utopia_async::test(threads = 2)]
#[ignore = "needs a JetStream server at NATS_URL"]
async fn a_committed_message_exports_what_php_workers_do() {
    let url = std::env::var("NATS_URL").expect("NATS_URL");
    let exporter = InMemoryMetricExporter::default();
    let provider = SdkMeterProvider::builder().with_reader(PeriodicReader::builder(exporter.clone()).build()).build();
    opentelemetry::global::set_meter_provider(provider.clone());

    // The work stream and consumer as Broker\Nats provisions them for the queue `metrics-test`.
    let js = jetstream::new(async_nats::connect(&url).await.unwrap());
    let _ = js.delete_stream("Q_METRICS-TEST").await;
    let stream = js
        .create_stream(stream::Config {
            name: "Q_METRICS-TEST".into(),
            subjects: vec!["q.metrics-test.normal".into()],
            retention: stream::RetentionPolicy::WorkQueue,
            ..Default::default()
        })
        .await
        .unwrap();
    stream
        .create_consumer(consumer::pull::Config {
            durable_name: Some("worker".into()),
            ack_policy: consumer::AckPolicy::Explicit,
            filter_subject: "q.metrics-test.normal".into(),
            ..Default::default()
        })
        .await
        .unwrap();

    let enqueued = SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs() - 3;
    let body = format!(r#"{{"pid":"1","queue":"metrics-test","timestamp":{enqueued},"payload":{{"to":"a@b.c"}}}}"#);
    js.publish("q.metrics-test.normal", body.clone().into()).await.unwrap().await.unwrap();

    let worker = Worker::join(js.clone(), "metrics-test").await.unwrap();
    // Room for two, so the receive tops up for 250 ms after the first message arrives.
    let mut deliveries = worker.receive(2, Duration::from_secs(5)).await.unwrap();
    assert_eq!(deliveries.len(), 1);
    worker.commit(deliveries.remove(0)).await.unwrap();

    provider.force_flush().unwrap();
    let exported = exporter.get_finished_metrics().unwrap();
    let histogram = |name: &str| {
        let metric = exported
            .iter()
            .flat_map(|resource| resource.scope_metrics())
            .flat_map(|scope| scope.metrics())
            .find(|metric| metric.name() == name)
            .unwrap_or_else(|| panic!("{name} was not exported"));
        match metric.data() {
            AggregatedMetrics::F64(MetricData::Histogram(h)) => {
                let point = h.data_points().next().unwrap();
                (point.count(), point.sum())
            }
            AggregatedMetrics::U64(MetricData::Histogram(h)) => {
                let point = h.data_points().next().unwrap();
                (point.count(), point.sum() as f64)
            }
            _ => panic!("{name} is not a histogram"),
        }
    };

    let (count, waited) = histogram("messaging.process.wait.duration");
    assert_eq!(count, 1);
    assert!((2.0..6.0).contains(&waited), "waited {waited}s");
    assert_eq!(histogram("messaging.process.message.size"), (1, body.len() as f64));
    // Committed as soon as it was handed over: the top-up is waiting, not processing.
    let (count, processed) = histogram("messaging.process.duration");
    assert_eq!(count, 1);
    assert!(processed < 0.2, "processed in {processed}s");

    js.delete_stream("Q_METRICS-TEST").await.unwrap();
}
