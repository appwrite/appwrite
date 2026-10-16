use std::time::Instant;

use utopia_circuit_breaker::CircuitBreaker;

fn main() {
    let breaker = CircuitBreaker::new();
    let iters = 200_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(breaker.call(|| 0, || Ok::<_, &str>(1)));
    }
    let elapsed = start.elapsed();
    println!(
        "breaker_call: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
