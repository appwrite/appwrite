use appwrite_network::{Cors, HEADER_ALLOW_ORIGIN};

fn main() {
    let cors = Cors::from_allowed_hosts(["localhost", "example.com"]).unwrap();
    let start = std::time::Instant::now();
    let n = 200_000u64;
    let mut hits = 0u64;
    for _ in 0..n {
        let headers = cors.headers("http://127.0.0.1:5173");
        if headers.iter().any(|(k, _)| k == HEADER_ALLOW_ORIGIN) {
            hits += 1;
        }
    }
    let elapsed = start.elapsed().as_secs_f64();
    std::hint::black_box(hits);
    println!("ops_per_s={}", (n as f64) / elapsed);
}
