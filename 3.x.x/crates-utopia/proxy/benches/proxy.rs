use std::time::Instant;
use utopia_proxy::adapter::Adapter;
use utopia_proxy::protocol::Protocol;

fn main() {
    let iters = 500_000u64;
    let start = Instant::now();
    for i in 0..iters {
        std::hint::black_box(Protocol::from_port((i % 65535) as u16));
    }
    let elapsed = start.elapsed();
    println!(
        "proxy_protocol_from_port: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );

    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(Adapter::parse_endpoint("example.com:443", 80));
    }
    let elapsed = start.elapsed();
    println!(
        "proxy_parse_endpoint: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
