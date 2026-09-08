use std::time::Instant;
use utopia_system::get_cpu_cores;

fn main() {
    let _ = get_cpu_cores();
    let iters = 5_000_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(get_cpu_cores());
    }
    let elapsed = start.elapsed();
    println!(
        "system_get_cpu_cores: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
