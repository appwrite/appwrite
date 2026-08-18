use std::time::Instant;

use utopia_cache::adapter::Memory;
use utopia_cache::Cache;

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..iters.min(1_000) {
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
    let cache = Cache::new(Memory::new());
    cache.save("bench-key", "bench-value", "").unwrap();

    bench("cache_memory_save", 200_000, || {
        std::hint::black_box(cache.save("bench-key", "bench-value", "").unwrap());
    });

    bench("cache_memory_load_hit", 200_000, || {
        std::hint::black_box(cache.load("bench-key", 60, "").unwrap());
    });

    bench("cache_memory_load_miss", 200_000, || {
        std::hint::black_box(cache.load("missing-key", 60, "").unwrap());
    });
}
