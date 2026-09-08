use std::time::Instant;
use utopia_compression::Compression;

fn bench(name: &str, mut f: impl FnMut()) {
    let iters = 2_000u64;
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
    let kb = vec![b'x'; 1024];
    let mid = vec![b'y'; 64 * 1024];
    let gzip = Compression::Gzip;
    let brotli = Compression::brotli();
    bench("gzip_1kb", || {
        std::hint::black_box(gzip.compress(&kb).unwrap());
    });
    bench("gzip_64kb", || {
        std::hint::black_box(gzip.compress(&mid).unwrap());
    });
    bench("brotli_1kb", || {
        std::hint::black_box(brotli.compress(&kb).unwrap());
    });
    bench("accept_encoding", || {
        std::hint::black_box(Compression::from_accept_encoding("br, gzip;q=0.8"));
    });
}
