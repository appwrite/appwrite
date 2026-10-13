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
//! time per call with the fastest and slowest samples.
//!
//! Around the timed samples both drivers also read what the process spent:
//!
//! - `cpu`: process CPU time (user + system) per call, in nanoseconds:
//!   `clock_gettime(CLOCK_PROCESS_CPUTIME_ID)` here, `getrusage()` in PHP.
//!   Every thread of the driver counts; a service the call waits on (a
//!   database, Redis) does not.
//! - `peak`: the most heap the samples held above what was live when they
//!   started, in bytes. Here the live bytes the counting allocator
//!   ([`Counting`]) tracks; in PHP the Zend allocator's
//!   `memory_get_peak_usage()` after `memory_reset_peak_usage()`.
//! - `retained`: live heap growth per call in bytes, across the samples
//!   (what calls keep: caches, leaks). Same sources as `peak`.
//! - `rss`: the driver process's peak resident memory so far, in bytes
//!   (`getrusage` `ru_maxrss`): the runtime's baseline plus every benchmark
//!   run before this one, not this benchmark's cost.
//!
//! The Rust driver also counts heap allocations and bytes allocated per call
//! (`allocations`, `bytes`); PHP has no allocation counter.

use std::future::Future;
use std::pin::Pin;
use std::sync::atomic::{AtomicI64, AtomicU64, Ordering};
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
/// Bytes live on the heap, and the most live since [`measure`] last reset it.
pub static LIVE: AtomicI64 = AtomicI64::new(0);
pub static PEAK: AtomicI64 = AtomicI64::new(0);

/// Counts allocations into [`ALLOCATIONS`] and [`ALLOCATED`] and tracks
/// [`LIVE`] and [`PEAK`]; the driver binary's global allocator calls it.
pub struct Counting;

impl Counting {
    /// A new block of `size` bytes.
    pub fn alloc(size: usize) {
        ALLOCATIONS.fetch_add(1, Ordering::Relaxed);
        ALLOCATED.fetch_add(size as u64, Ordering::Relaxed);
        Self::grow(size as i64);
    }

    /// A block resized from `old` to `new` bytes.
    pub fn realloc(old: usize, new: usize) {
        ALLOCATIONS.fetch_add(1, Ordering::Relaxed);
        ALLOCATED.fetch_add(new as u64, Ordering::Relaxed);
        Self::grow(new as i64 - old as i64);
    }

    /// A block of `size` bytes freed.
    pub fn dealloc(size: usize) {
        LIVE.fetch_sub(size as i64, Ordering::Relaxed);
    }

    fn grow(by: i64) {
        let live = LIVE.fetch_add(by, Ordering::Relaxed) + by;
        PEAK.fetch_max(live, Ordering::Relaxed);
    }
}

/// CPU time the process has used (user and system, every thread), in ns.
fn cpu() -> f64 {
    let mut t = libc::timespec { tv_sec: 0, tv_nsec: 0 };
    // SAFETY: `t` is a valid timespec for the call to fill.
    unsafe { libc::clock_gettime(libc::CLOCK_PROCESS_CPUTIME_ID, &mut t) };
    t.tv_sec as f64 * 1e9 + t.tv_nsec as f64
}

/// The process's peak resident set so far, in bytes.
fn rss() -> f64 {
    // SAFETY: an all-zero rusage is valid for `getrusage` to fill.
    let mut usage: libc::rusage = unsafe { std::mem::zeroed() };
    // SAFETY: `usage` is a valid rusage for the call to fill.
    unsafe { libc::getrusage(libc::RUSAGE_SELF, &mut usage) };
    // Kilobytes on Linux, bytes on macOS.
    let unit = if cfg!(target_os = "macos") { 1.0 } else { 1024.0 };
    usage.ru_maxrss as f64 * unit
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
    // Before the baseline, as the PHP driver builds its sample array: the
    // harness's own allocation is not the benchmark's heap or CPU.
    let mut samples = Vec::with_capacity(SAMPLES);
    let allocations = ALLOCATIONS.load(Ordering::Relaxed);
    let allocated = ALLOCATED.load(Ordering::Relaxed);
    let live = LIVE.load(Ordering::Relaxed);
    PEAK.store(live, Ordering::Relaxed);
    let cpu_started = cpu();
    for _ in 0..SAMPLES {
        let t = Instant::now();
        bench.run(per_sample).await;
        samples.push(t.elapsed().as_nanos() as f64 / per_sample as f64);
    }
    let cpu_used = cpu() - cpu_started;
    let calls = (SAMPLES as u64 * per_sample) as f64;
    let mut out = summary(&mut samples, per_sample);
    out["cpu"] = json!(cpu_used / calls);
    out["rss"] = json!(rss());
    // Only a counting global allocator (the driver binary's) fills these.
    if ALLOCATIONS.load(Ordering::Relaxed) > allocations {
        out["allocations"] = json!((ALLOCATIONS.load(Ordering::Relaxed) - allocations) as f64 / calls);
        out["bytes"] = json!((ALLOCATED.load(Ordering::Relaxed) - allocated) as f64 / calls);
        out["peak"] = json!((PEAK.load(Ordering::Relaxed) - live).max(0) as f64);
        out["retained"] = json!((LIVE.load(Ordering::Relaxed) - live) as f64 / calls);
    }
    out
}

/// The timing both drivers report: nanoseconds per call (median, fastest
/// and slowest sample) and calls per sample. [`measure`] adds CPU and memory.
pub fn summary(samples: &mut [f64], per_sample: u64) -> Value {
    samples.sort_by(f64::total_cmp);
    let median = samples[samples.len() / 2];
    json!({
        "ns": median,
        "min": samples[0],
        "max": samples[samples.len() - 1],
        "samples": samples.len(),
        "calls": per_sample,
    })
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
        assert!(r["cpu"].as_f64().unwrap() > 0.0);
        assert!(r["rss"].as_f64().unwrap() > 0.0);
    }
}
