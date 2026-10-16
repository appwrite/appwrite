use appwrite_auth::{Key, Password, Phone};
use serde_json::json;
use utopia_validators::Validator;

fn bench_password_validator(n: u64) -> f64 {
    let validator = Password::new(false);
    let start = std::time::Instant::now();
    for i in 0..n {
        let pw = format!("password-{i}");
        let _ = validator.is_valid(&json!(pw));
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn bench_phone_validator(n: u64) -> f64 {
    let validator = Phone::new();
    let start = std::time::Instant::now();
    for _ in 0..n {
        let _ = validator.is_valid(&json!("+16175551212"));
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn bench_key_decode(n: u64) -> f64 {
    let project = json!({
        "$id": "proj1",
        "keys": [
            { "secret": "abc123", "scopes": ["users.read"], "name": "CI Key" },
        ],
    });
    let start = std::time::Instant::now();
    for _ in 0..n {
        let _ = Key::decode_standard(&project, "abc123");
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn main() {
    let n = 100_000u64;
    println!(
        "password_validator ops_per_s={}",
        bench_password_validator(n)
    );
    println!("phone_validator ops_per_s={}", bench_phone_validator(n));
    println!("key_decode_standard ops_per_s={}", bench_key_decode(n));
}
