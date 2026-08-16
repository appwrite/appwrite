fn main() {
    let start = std::time::Instant::now();
    let n = 100_000u64;
    for _ in 0..n {
        let _ = 1 + 1;
    }
    let elapsed = start.elapsed().as_secs_f64();
    println!("ops_per_s={}", (n as f64) / elapsed);
}
