use appwrite_exception::Exception;

fn main() {
    let start = std::time::Instant::now();
    let n = 200_000u64;
    let mut total_code: u64 = 0;
    for _ in 0..n {
        let err = Exception::new(Exception::USER_NOT_FOUND);
        let json = err.to_json();
        total_code += json["code"].as_u64().unwrap_or(0);
    }
    let elapsed = start.elapsed().as_secs_f64();
    std::hint::black_box(total_code);
    println!("ops_per_s={}", (n as f64) / elapsed);
}
