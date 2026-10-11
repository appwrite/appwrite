//! Benchmarks: a library's hot paths, timed in-process by each driver.
//!
//! A library adapter declares its benchmarks by name (`BENCHES` in
//! `src/libs/<lib>.rs`, `benchmarks()` in `tests/compat/<lib>/Adapter.php`).
//! Each one is a setup that returns the closure to time: the setup builds
//! what the closure needs (a parsed value, a connection), so only the library
//! call is measured, not the adapter's argument decoding.
//!
//! Both drivers time a closure the same way ([`measure`] here,
//! `tests/compat/php/Bench.php` there): warm up, find how many calls take a
//! sample of about 20 ms, take [`SAMPLES`] samples, and report the median
//! time per call with the fastest and slowest samples. The Rust driver also
//! counts heap allocations (its global allocator is [`Counting`]).

use std::future::Future;
use std::pin::Pin;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant};

use serde_json::{Value, json};

/// A closure to time: plain, or returning a future (for async libraries).
pub enum Bench {
    Sync(Box<dyn FnMut()>),
    Async(Box<dyn FnMut() -> Pin<Box<dyn Future<Output = ()>>>>),
}

impl Bench {
    /// A plain closure. Pass results through [`std::hint::black_box`] so the
    /// compiler cannot drop the work.
    pub fn sync(f: impl FnMut() + 'static) -> Self {
        Bench::Sync(Box::new(f))
    }

    /// A closure whose call returns a future.
    pub fn future<F: Future<Output = ()> + 'static>(mut f: impl FnMut() -> F + 'static) -> Self {
        Bench::Async(Box::new(move || Box::pin(f())))
    }

    async fn run(&mut self, n: u64) {
        match self {
            Bench::Sync(f) => (0..n).for_each(|_| f()),
            Bench::Async(f) => {
                for _ in 0..n {
                    f().await;
                }
            }
        }
    }
}

/// Samples per benchmark.
pub const SAMPLES: usize = 15;
const SAMPLE: Duration = Duration::from_millis(20);
const WARMUP: Duration = Duration::from_millis(200);

/// Heap allocations and bytes allocated since the driver started, counted by
/// [`Counting`] when it is the global allocator (zero otherwise).
pub static ALLOCATIONS: AtomicU64 = AtomicU64::new(0);
pub static ALLOCATED: AtomicU64 = AtomicU64::new(0);

/// Counts allocations into [`ALLOCATIONS`] and [`ALLOCATED`]; the driver
/// binary installs it as its global allocator.
pub struct Counting;

impl Counting {
    pub fn record(size: usize) {
        ALLOCATIONS.fetch_add(1, Ordering::Relaxed);
        ALLOCATED.fetch_add(size as u64, Ordering::Relaxed);
    }
}

/// Times `bench`; the result is what `$bench` returns.
pub async fn measure(mut bench: Bench) -> Value {
    let started = Instant::now();
    let mut warm = 0u64;
    while started.elapsed() < WARMUP || warm < 10 {
        bench.run(1).await;
        warm += 1;
    }
    // Calls per sample: double until a batch is long enough to time, then scale.
    let mut n = 1u64;
    let batch = loop {
        let t = Instant::now();
        bench.run(n).await;
        let took = t.elapsed();
        if took >= SAMPLE / 4 || n >= 1 << 30 {
            break took;
        }
        n *= 2;
    };
    let per_sample = ((n as f64 * SAMPLE.as_secs_f64() / batch.as_secs_f64().max(1e-9)) as u64).max(1);
    let allocations = ALLOCATIONS.load(Ordering::Relaxed);
    let allocated = ALLOCATED.load(Ordering::Relaxed);
    let mut samples = Vec::with_capacity(SAMPLES);
    for _ in 0..SAMPLES {
        let t = Instant::now();
        bench.run(per_sample).await;
        samples.push(t.elapsed().as_nanos() as f64 / per_sample as f64);
    }
    let calls = (SAMPLES as u64 * per_sample) as f64;
    let allocations = (ALLOCATIONS.load(Ordering::Relaxed) - allocations) as f64 / calls;
    let allocated = (ALLOCATED.load(Ordering::Relaxed) - allocated) as f64 / calls;
    summary(&mut samples, per_sample, Some((allocations, allocated)))
}

/// The JSON both drivers report: nanoseconds per call (median, fastest and
/// slowest sample), calls per sample, and allocations when counted.
pub fn summary(samples: &mut [f64], per_sample: u64, allocations: Option<(f64, f64)>) -> Value {
    samples.sort_by(f64::total_cmp);
    let median = samples[samples.len() / 2];
    let mut out = json!({
        "ns": median,
        "min": samples[0],
        "max": samples[samples.len() - 1],
        "samples": samples.len(),
        "calls": per_sample,
    });
    if let Some((count, bytes)) = allocations {
        out["allocations"] = json!(count);
        out["bytes"] = json!(bytes);
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test(flavor = "current_thread")]
    async fn measures_a_closure() {
        let mut x = 0u64;
        let r = measure(Bench::sync(move || {
            x = std::hint::black_box(x.wrapping_add(1));
        }))
        .await;
        assert!(r["ns"].as_f64().unwrap() >= 0.0);
        assert_eq!(r["samples"], SAMPLES);
        assert!(r["min"].as_f64() <= r["ns"].as_f64() && r["ns"].as_f64() <= r["max"].as_f64());
    }
}
