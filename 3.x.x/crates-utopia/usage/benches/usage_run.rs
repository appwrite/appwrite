use serde_json::Map;
use std::time::Instant;
use utopia_usage::{Accumulator, Memory, Usage};

fn main() {
    let mut acc = Accumulator::new(Usage::new(Memory::new()));
    let iters = 50_000u64;
    let start = Instant::now();
    for i in 0..iters {
        acc.collect("t1", "requests", 1, "event", Map::new(), None, false)
            .unwrap();
        if i % 1000 == 0 {
            acc.flush().unwrap();
        }
    }
    acc.flush().unwrap();
    let elapsed = start.elapsed();
    println!(
        "usage_collect: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
