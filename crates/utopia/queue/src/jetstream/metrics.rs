//! The metrics `Utopia\Queue\Server` records, under the same names, units, buckets and
//! attributes, so Rust and PHP workers on one queue add to the same series.
//!
//! They go to the global meter provider. An application that wants them installs an
//! OpenTelemetry SDK with an exporter before it joins a queue; without one they cost nothing.

use std::time::{Instant, SystemTime, UNIX_EPOCH};

use opentelemetry::KeyValue;
use opentelemetry::metrics::{Histogram, Meter};

/// `Server::DURATION_BUCKETS`, in seconds.
const DURATION_BUCKETS: [f64; 24] = [
    0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0, 20.0, 30.0, 60.0, 120.0, 300.0,
    600.0, 1200.0, 1800.0, 3600.0, 7200.0,
];
/// `Server::SIZE_BUCKETS`, in bytes.
const SIZE_BUCKETS: [f64; 10] =
    [1024.0, 4096.0, 16384.0, 65536.0, 262144.0, 1048576.0, 4194304.0, 16777216.0, 67108864.0, 268435456.0];
/// The namespace every Appwrite queue runs under (`Utopia\Queue\Adapter`'s default). JetStream
/// streams do not carry one, so a worker cannot read it from the queue.
const NAMESPACE: &str = "utopia-queue";

pub(crate) struct Metrics {
    wait: Histogram<f64>,
    process: Histogram<f64>,
    size: Histogram<u64>,
    attributes: [KeyValue; 2],
}

impl Metrics {
    pub(crate) fn new(meter: &Meter, queue: &str) -> Self {
        Self {
            wait: meter
                .f64_histogram("messaging.process.wait.duration")
                .with_unit("s")
                .with_boundaries(DURATION_BUCKETS.to_vec())
                .build(),
            process: meter
                .f64_histogram("messaging.process.duration")
                .with_unit("s")
                .with_boundaries(DURATION_BUCKETS.to_vec())
                .build(),
            size: meter
                .u64_histogram("messaging.process.message.size")
                .with_unit("By")
                .with_boundaries(SIZE_BUCKETS.to_vec())
                .build(),
            attributes: [
                KeyValue::new("messaging.destination.name", queue.to_owned()),
                KeyValue::new("messaging.destination.namespace", NAMESPACE),
            ],
        }
    }

    /// A message was taken to be worked on: how long it waited since it was enqueued, and its
    /// size as it arrived.
    pub(crate) fn received(&self, enqueued: i64, bytes: usize) {
        let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs_f64();
        // The publisher's clock and this one can disagree by a little. A negative sample would
        // lower the histogram's cumulative sum, which Prometheus reads as a counter reset
        // (Server.php records the same floor, for the same reason).
        self.wait.record((now - enqueued as f64).max(0.0), &self.attributes);
        self.size.record(bytes as u64, &self.attributes);
    }

    /// A message received at `received` was committed or rejected.
    pub(crate) fn settled(&self, received: Instant) {
        self.process.record(received.elapsed().as_secs_f64(), &self.attributes);
    }
}

#[cfg(test)]
mod tests {
    use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

    use opentelemetry::KeyValue;
    use opentelemetry::metrics::MeterProvider as _;
    use opentelemetry_sdk::metrics::data::{AggregatedMetrics, HistogramDataPoint, MetricData};
    use opentelemetry_sdk::metrics::{InMemoryMetricExporter, PeriodicReader, SdkMeterProvider};

    use super::Metrics;

    /// What the SDK exported for one histogram: unit, bucket bounds and its one data point.
    struct Exported {
        unit: String,
        bounds: Vec<f64>,
        count: u64,
        sum: f64,
        attributes: Vec<KeyValue>,
    }

    fn export(record: impl FnOnce(&Metrics)) -> impl Fn(&str) -> Exported {
        let exporter = InMemoryMetricExporter::default();
        let provider =
            SdkMeterProvider::builder().with_reader(PeriodicReader::builder(exporter.clone()).build()).build();
        record(&Metrics::new(&provider.meter("utopia-queue"), "v1-mails"));
        provider.force_flush().unwrap();
        let exported = exporter.get_finished_metrics().unwrap();
        move |name: &str| {
            let metric = exported
                .iter()
                .flat_map(|resource| resource.scope_metrics())
                .flat_map(|scope| scope.metrics())
                .find(|metric| metric.name() == name)
                .unwrap_or_else(|| panic!("{name} was not exported"));
            let (bounds, count, sum, attributes) = match metric.data() {
                AggregatedMetrics::F64(MetricData::Histogram(histogram)) => {
                    let point: &HistogramDataPoint<f64> = histogram.data_points().next().unwrap();
                    (point.bounds().collect(), point.count(), point.sum(), point.attributes().cloned().collect())
                }
                AggregatedMetrics::U64(MetricData::Histogram(histogram)) => {
                    let point: &HistogramDataPoint<u64> = histogram.data_points().next().unwrap();
                    (point.bounds().collect(), point.count(), point.sum() as f64, point.attributes().cloned().collect())
                }
                _ => panic!("{name} is not a histogram"),
            };
            Exported { unit: metric.unit().to_owned(), bounds, count, sum, attributes }
        }
    }

    fn now() -> i64 {
        SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs() as i64
    }

    #[test]
    fn records_what_utopia_php_queue_records() {
        let get = export(|metrics| {
            metrics.received(now() - 30, 2048);
            metrics.settled(Instant::now() - Duration::from_millis(200));
        });

        let wait = get("messaging.process.wait.duration");
        assert_eq!((wait.unit.as_str(), wait.count), ("s", 1));
        assert!((29.0..32.0).contains(&wait.sum), "waited {}s", wait.sum);
        assert_eq!(wait.bounds.len(), 24);
        assert_eq!((wait.bounds[0], wait.bounds[23]), (0.005, 7200.0));

        let process = get("messaging.process.duration");
        assert_eq!((process.unit.as_str(), process.count), ("s", 1));
        assert!((0.2..1.0).contains(&process.sum), "processed in {}s", process.sum);

        let size = get("messaging.process.message.size");
        assert_eq!((size.unit.as_str(), size.count, size.sum), ("By", 1, 2048.0));
        assert_eq!((size.bounds[0], size.bounds[9]), (1024.0, 268435456.0));

        let mut attributes = size.attributes;
        attributes.sort_by(|a, b| a.key.as_str().cmp(b.key.as_str()));
        assert_eq!(
            attributes,
            [
                KeyValue::new("messaging.destination.name", "v1-mails"),
                KeyValue::new("messaging.destination.namespace", "utopia-queue"),
            ]
        );
    }

    #[test]
    fn a_publisher_clock_ahead_of_ours_waits_zero_not_less() {
        let get = export(|metrics| metrics.received(now() + 5, 10));
        assert_eq!(get("messaging.process.wait.duration").sum, 0.0);
    }
}
