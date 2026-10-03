//! Big-file S3 upload benchmark against `MinIO`.
//!
//! Compare with PHP `benchmarks/storage/bench_s3_big_upload.php`.
//!
//! Environment variables: `BENCH_SIZE_MB`, `BENCH_ITERS`, `BENCH_PART_MB`,
//! `BENCH_PAYLOAD`, `BENCH_CONCURRENCY`, `S3_HOST`, `S3_BUCKET`,
//! `S3_ACCESS_KEY`, `S3_SECRET`, `S3_REGION`.
//!
//! Memory: samples OS RSS (`VmRSS` from `/proc/self/status`) every 2ms during
//! each timed upload and reports median peak / delta.

use std::fs::{self, File};
use std::io::{Read, Seek, SeekFrom, Write};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::thread;
use std::time::{Duration, Instant};

use utopia_storage::{
    Acl, Device, ParallelUploadOptions, UploadMetadata, MIN_MULTIPART_PART_SIZE, S3,
};

fn env_or(key: &str, default: &str) -> String {
    std::env::var(key).unwrap_or_else(|_| default.to_string())
}

fn env_usize(key: &str, default: usize) -> usize {
    std::env::var(key)
        .ok()
        .and_then(|value| value.parse().ok())
        .unwrap_or(default)
}

fn ensure_payload(path: &Path, size_mb: usize) -> std::io::Result<()> {
    let size = size_mb * 1024 * 1024;
    if path.is_file() && fs::metadata(path)?.len() == size as u64 {
        return Ok(());
    }
    eprintln!("Generating {size_mb} MiB payload at {}", path.display());
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)?;
    }
    let mut file = File::create(path)?;
    let chunk = vec![0xA5_u8; 1024 * 1024];
    for _ in 0..size_mb {
        file.write_all(&chunk)?;
    }
    Ok(())
}

fn mb_per_s(bytes: u64, seconds: f64) -> f64 {
    (bytes as f64 / (1024.0 * 1024.0)) / seconds.max(1e-9)
}

fn kib_to_mib(kib: u64) -> f64 {
    kib as f64 / 1024.0
}

fn read_rss_kib() -> u64 {
    let Ok(status) = fs::read_to_string("/proc/self/status") else {
        return 0;
    };
    for line in status.lines() {
        if let Some(rest) = line.strip_prefix("VmRSS:") {
            let kib = rest
                .split_whitespace()
                .next()
                .and_then(|value| value.parse().ok())
                .unwrap_or(0);
            return kib;
        }
    }
    0
}

struct UploadSample {
    elapsed: f64,
    peak_rss_kib: u64,
    delta_rss_kib: u64,
}

fn measure_upload(run: impl FnOnce()) -> UploadSample {
    let stop = AtomicBool::new(false);
    let peak = AtomicU64::new(read_rss_kib());
    let baseline = read_rss_kib();
    peak.fetch_max(baseline, Ordering::Relaxed);

    let (elapsed, peak_rss_kib) = thread::scope(|scope| {
        scope.spawn(|| {
            while !stop.load(Ordering::Relaxed) {
                peak.fetch_max(read_rss_kib(), Ordering::Relaxed);
                thread::sleep(Duration::from_millis(2));
            }
            peak.fetch_max(read_rss_kib(), Ordering::Relaxed);
        });

        let start = Instant::now();
        run();
        let elapsed = start.elapsed().as_secs_f64();
        stop.store(true, Ordering::Relaxed);
        (elapsed, peak.load(Ordering::Relaxed).max(baseline))
    });

    UploadSample {
        elapsed,
        peak_rss_kib,
        delta_rss_kib: peak_rss_kib.saturating_sub(baseline),
    }
}

fn median_f64(values: &mut [f64]) -> f64 {
    values.sort_by(|left, right| left.partial_cmp(right).unwrap());
    values[values.len() / 2]
}

fn median_u64(values: &mut [u64]) -> u64 {
    values.sort_unstable();
    values[values.len() / 2]
}

struct BenchStats {
    median_secs: f64,
    times: Vec<f64>,
    peak_rss_mib: f64,
    delta_rss_mib: f64,
    peak_rss_samples_mib: Vec<f64>,
}

fn time_upload(iters: usize, mut run: impl FnMut(usize)) -> BenchStats {
    run(0); // warmup
    let mut times = Vec::with_capacity(iters);
    let mut peaks = Vec::with_capacity(iters);
    let mut deltas = Vec::with_capacity(iters);
    for i in 1..=iters {
        let sample = measure_upload(|| run(i));
        times.push(sample.elapsed);
        peaks.push(sample.peak_rss_kib);
        deltas.push(sample.delta_rss_kib);
    }
    let median_secs = median_f64(&mut times.clone());
    let peak_rss_mib = kib_to_mib(median_u64(&mut peaks.clone()));
    let delta_rss_mib = kib_to_mib(median_u64(&mut deltas.clone()));
    BenchStats {
        median_secs,
        peak_rss_mib,
        delta_rss_mib,
        peak_rss_samples_mib: peaks.into_iter().map(kib_to_mib).collect(),
        times,
    }
}

fn format_samples(values: &[f64]) -> String {
    values
        .iter()
        .map(|value| format!("{value:.3}"))
        .collect::<Vec<_>>()
        .join(",")
}

fn device(host: &str, bucket: &str) -> S3 {
    S3::with_bucket(
        "/bench",
        env_or("S3_ACCESS_KEY", "minioadmin"),
        env_or("S3_SECRET", "minioadmin"),
        host,
        env_or("S3_REGION", "us-east-1"),
        Acl::Private,
        bucket,
    )
    .expect("S3 device")
}

fn print_stats(name: &str, size_mb: usize, size: u64, extra: &str, stats: &BenchStats) {
    println!(
        "{name}: {:.2} MB/s (median {:.3}s for {size_mb} MiB{extra}; peak_rss_mib={:.2}; delta_rss_mib={:.2}; samples={}; rss_samples={})",
        mb_per_s(size, stats.median_secs),
        stats.median_secs,
        stats.peak_rss_mib,
        stats.delta_rss_mib,
        format_samples(&stats.times),
        format_samples(&stats.peak_rss_samples_mib),
    );
}

fn main() {
    let size_mb = env_usize("BENCH_SIZE_MB", 64);
    let iters = env_usize("BENCH_ITERS", 3);
    let part_mb = env_usize("BENCH_PART_MB", 8).max(5);
    let concurrency = env_usize("BENCH_CONCURRENCY", 4).max(1);
    let mode = env_or("BENCH_MODE", "all");
    let bucket = env_or("S3_BUCKET", "utopia-storage-test");
    let host = env_or("S3_HOST", &format!("http://127.0.0.1:9805/{bucket}"));
    let payload = PathBuf::from(env_or(
        "BENCH_PAYLOAD",
        &std::env::temp_dir()
            .join("utopia-storage-bench-payload.bin")
            .to_string_lossy(),
    ));

    ensure_payload(&payload, size_mb).expect("payload");
    let size = (size_mb * 1024 * 1024) as u64;
    let part_size = part_mb * 1024 * 1024;
    let s3 = device(&host, &bucket);

    eprintln!("Rust big upload [{mode}]: {size_mb} MiB × {iters} iters → {host}");

    let run_write_from = mode == "all" || mode == "write_from";
    let run_parallel = mode == "all" || mode == "parallel";
    let run_seq = mode == "all" || mode == "seq";
    let run_manual = mode == "all" || mode == "manual";

    if run_write_from {
        let stats = time_upload(iters, |i| {
            let path = s3.get_path(&format!("bench/rust-write-from-{i}.bin"));
            let mut file = File::open(&payload).expect("open payload");
            s3.write_from(&path, &mut file, "application/octet-stream")
                .expect("write_from");
            let _ = s3.delete(&path, false);
        });
        print_stats("rust_s3_write_from_parallel", size_mb, size, "", &stats);
    }

    if run_parallel {
        let stats = time_upload(iters, |i| {
            let path = s3.get_path(&format!("bench/rust-parallel-{i}.bin"));
            let mut file = File::open(&payload).expect("open payload");
            s3.upload_parallel(
                &mut file,
                &path,
                "application/octet-stream",
                ParallelUploadOptions::new(part_size, concurrency),
            )
            .expect("upload_parallel");
            let _ = s3.delete(&path, false);
        });
        print_stats(
            "rust_s3_multipart_parallel",
            size_mb,
            size,
            &format!("; part={part_mb} MiB; concurrency={concurrency}"),
            &stats,
        );
    }

    if run_seq {
        let stats = time_upload(iters, |i| {
            let path = s3.get_path(&format!("bench/rust-seq-{i}.bin"));
            let mut file = File::open(&payload).expect("open payload");
            s3.upload_parallel(
                &mut file,
                &path,
                "application/octet-stream",
                ParallelUploadOptions::new(part_size, 1),
            )
            .expect("upload_parallel seq");
            let _ = s3.delete(&path, false);
        });
        print_stats(
            "rust_s3_multipart_seq",
            size_mb,
            size,
            &format!("; part={part_mb} MiB"),
            &stats,
        );
    }

    if run_manual {
        let stats = time_upload(iters, |i| {
            let path = s3.get_path(&format!("bench/rust-manual-{i}.bin"));
            let total_parts = size.div_ceil(part_size as u64) as u32;
            let mut metadata = UploadMetadata::default();
            let mut file = File::open(&payload).expect("open payload");
            let mut buffer = vec![0_u8; part_size.max(MIN_MULTIPART_PART_SIZE)];
            for part in 1..=total_parts {
                let offset = (u64::from(part) - 1) * part_size as u64;
                let window = ((size - offset) as usize).min(part_size);
                file.seek(SeekFrom::Start(offset)).expect("seek");
                file.read_exact(&mut buffer[..window]).expect("read part");
                s3.upload(
                    &buffer[..window],
                    &path,
                    "application/octet-stream",
                    part,
                    total_parts,
                    &mut metadata,
                )
                .expect("upload part");
            }
            let _ = s3.delete(&path, false);
        });
        print_stats(
            "rust_s3_multipart_manual_seq",
            size_mb,
            size,
            &format!("; part={part_mb} MiB"),
            &stats,
        );
    }
}
