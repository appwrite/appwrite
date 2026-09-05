use appwrite_hooks::{Hooks, PASSWORD_VALIDATOR};
use serde_json::json;

fn main() {
    let mut hooks = Hooks::new();
    hooks.add(PASSWORD_VALIDATOR, |params| {
        let password = params.first().and_then(|v| v.as_str()).unwrap_or_default();
        json!(password.chars().count() >= 8)
    });

    let start = std::time::Instant::now();
    let n = 500_000u64;
    let mut passed: u64 = 0;
    for _ in 0..n {
        if hooks.trigger(PASSWORD_VALIDATOR, &[json!("longenoughpassword")]) == Some(json!(true)) {
            passed += 1;
        }
    }
    let elapsed = start.elapsed().as_secs_f64();
    std::hint::black_box(passed);
    println!("ops_per_s={}", (n as f64) / elapsed);
}
