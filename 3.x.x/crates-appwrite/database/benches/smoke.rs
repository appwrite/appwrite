use appwrite_database::{queries, resolve_id, CustomId, UNIQUE_SENTINEL};
use serde_json::json;
use utopia_validators::Validator;

fn bench_custom_id_validator(n: u64) -> f64 {
    let validator = CustomId::default();
    let start = std::time::Instant::now();
    for i in 0..n {
        let id = format!("user-{i}");
        let _ = validator.is_valid(&json!(id));
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn bench_resolve_id_unique(n: u64) -> f64 {
    let start = std::time::Instant::now();
    for _ in 0..n {
        let _ = resolve_id(UNIQUE_SENTINEL);
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn bench_query_helpers(n: u64) -> f64 {
    let start = std::time::Instant::now();
    for i in 0..n {
        let _ = queries::by_user_id(format!("user-{i}"));
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn main() {
    let n = 100_000u64;
    println!(
        "custom_id_validator ops_per_s={}",
        bench_custom_id_validator(n)
    );
    println!("resolve_id_unique ops_per_s={}", bench_resolve_id_unique(n));
    println!("query_helpers ops_per_s={}", bench_query_helpers(n));
}
