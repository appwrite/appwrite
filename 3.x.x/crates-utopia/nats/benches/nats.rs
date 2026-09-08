use std::time::Instant;
use utopia_nats::protocol::Writer;
use utopia_nats::Headers;

fn main() {
    let iters = 200_000u64;
    let payload = b"hello-nats-benchmark";
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(Writer.pub_cmd("bench.subject", payload, None));
    }
    let elapsed = start.elapsed();
    println!(
        "nats_pub_encode: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );

    let mut headers = Headers::new();
    headers.set("X-Key", "value");
    headers.set("Nats-Msg-Id", "abc-123");
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(headers.to_wire());
    }
    let elapsed = start.elapsed();
    println!(
        "nats_headers_to_wire: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
