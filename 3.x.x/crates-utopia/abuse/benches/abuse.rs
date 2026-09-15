use std::time::Instant;
use utopia_abuse::adapters::time_limit::Memory;
use utopia_abuse::{Abuse, Adapter};

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..10_000 {
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
    let store = utopia_abuse::adapters::time_limit::MemoryStore::new();
    let mut adapter = Memory::with_store("login-attempt-from-{{ip}}", 1_000_000, 60 * 5, store);
    adapter.set_param("{{ip}}", "127.0.0.1");
    let mut abuse = Abuse::new(adapter);
    bench("timelimit_check", 200_000, || {
        std::hint::black_box(abuse.check().unwrap());
    });
}
