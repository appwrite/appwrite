use std::time::Instant;

use tempfile::TempDir;
use utopia_storage::{Device, Local};

fn main() {
    let temp = TempDir::new().expect("tempdir");
    let device = Local::new(temp.path());
    let path = device.get_path("bench.txt");
    let payload = vec![0_u8; 4096];
    let iters = 50_000_u64;

    let _ = device.write(&path, &payload, "application/octet-stream");

    let start = Instant::now();
    for _ in 0..iters {
        device
            .write(&path, &payload, "application/octet-stream")
            .unwrap();
        std::hint::black_box(());
    }
    let write_elapsed = start.elapsed();

    let start = Instant::now();
    for _ in 0..iters {
        let data = device.read(&path, 0, None).unwrap();
        std::hint::black_box(data);
    }
    let read_elapsed = start.elapsed();

    println!(
        "storage_local_write: {:.0} ops/s ({write_elapsed:?} for {iters} iters)",
        iters as f64 / write_elapsed.as_secs_f64()
    );
    println!(
        "storage_local_read: {:.0} ops/s ({read_elapsed:?} for {iters} iters)",
        iters as f64 / read_elapsed.as_secs_f64()
    );
}
