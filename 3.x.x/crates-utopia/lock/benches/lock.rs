use std::time::Instant;

use utopia_lock::{Lock, Mutex};

fn main() {
    let mutex = Mutex::new();
    mutex.with_lock(|| (), 0.0).unwrap();

    let iters = 200_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(mutex.with_lock(|| 1_u32, 0.0).unwrap());
    }
    let elapsed = start.elapsed();
    println!(
        "lock_with_lock: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
