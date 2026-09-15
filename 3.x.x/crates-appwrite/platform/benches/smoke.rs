use appwrite_event::DeletePublisher;
use appwrite_platform::AppwritePlatform;
use serde_json::json;

fn bench_ensure_ready(n: u64) -> f64 {
    let platform = AppwritePlatform::new();
    let start = std::time::Instant::now();
    for _ in 0..n {
        let _ = platform.ensure_ready();
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn bench_password_hook_trigger(n: u64) -> f64 {
    let platform = AppwritePlatform::new();
    let start = std::time::Instant::now();
    for _ in 0..n {
        let _ = platform.hooks().trigger(
            appwrite_hooks::PASSWORD_VALIDATOR,
            &[json!("longenoughpassword")],
        );
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn bench_delete_publisher_enqueue(n: u64) -> f64 {
    let platform = AppwritePlatform::new();
    let start = std::time::Instant::now();
    for _ in 0..n {
        platform
            .deletes()
            .enqueue(appwrite_event::DeleteMessage::new("document"));
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn main() {
    let n = 100_000u64;
    println!("ensure_ready ops_per_s={}", bench_ensure_ready(n));
    println!(
        "password_hook_trigger ops_per_s={}",
        bench_password_hook_trigger(n)
    );
    println!(
        "delete_publisher_enqueue ops_per_s={}",
        bench_delete_publisher_enqueue(n)
    );
}
