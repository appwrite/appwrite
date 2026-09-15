use std::time::Instant;

use utopia_cloudevents::CloudEvent;

fn main() {
    let event = CloudEvent::create("io.example.test", "urn:test", "1-0");
    let iters = 200_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(event.to_array());
    }
    let elapsed = start.elapsed();
    println!(
        "cloudevents_to_array: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
