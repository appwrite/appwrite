use std::collections::HashMap;
use std::time::Instant;
use utopia_telemetry::{Adapter, NoneAdapter, TestAdapter};

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..50_000 {
        f();
    }
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}

fn main() {
    let none = NoneAdapter::new();
    let counter = none.create_counter("bench.counter", None, None, HashMap::new());
    let empty = HashMap::new();
    bench("none_counter_add", 5_000_000, || {
        counter.add(1.0, &empty);
        std::hint::black_box(&counter);
    });
    let test = TestAdapter::new();
    let hist = test.create_histogram("bench.histogram", Some("ms"), None, HashMap::new());
    // Fair vs PHP default empty attributes.
    bench("test_histogram_record", 1_000_000, || {
        hist.record(42.0, &empty);
        std::hint::black_box(&hist);
    });
}
