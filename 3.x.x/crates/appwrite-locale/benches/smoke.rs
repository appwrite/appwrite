use appwrite_locale::GeoRecord;

fn main() {
    let start = std::time::Instant::now();
    let n = 500_000u64;
    let mut empties: u64 = 0;
    for i in 0..n {
        let record = if i % 2 == 0 {
            GeoRecord::unknown()
        } else {
            GeoRecord::new("US", "United States", "North America", "NA").with_eu(false)
        };
        if record.is_empty() {
            empties += 1;
        }
    }
    let elapsed = start.elapsed().as_secs_f64();
    std::hint::black_box(empties);
    println!("ops_per_s={}", (n as f64) / elapsed);
}
