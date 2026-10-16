use appwrite_response::{dynamic, MODEL_USER, MODEL_USER_LIST};
use serde_json::json;

fn main() {
    let doc = json!({
        "$id": "u1",
        "$createdAt": "2024-01-01T00:00:00.000+00:00",
        "$updatedAt": "2024-01-01T00:00:00.000+00:00",
        "name": "Ada Lovelace",
        "email": "ada@appwrite.io",
        "phone": "+15555550100",
        "status": true,
        "prefs": { "theme": "dark" },
        "targets": [],
    });
    let list = json!({ "total": 1, "documents": [doc] });

    let start = std::time::Instant::now();
    let n = 100_000u64;
    let mut total_users: u64 = 0;
    for _ in 0..n {
        let filtered = dynamic(&list, MODEL_USER_LIST);
        total_users += filtered["users"].as_array().map_or(0, |a| a.len() as u64);
        std::hint::black_box(dynamic(&doc, MODEL_USER));
    }
    let elapsed = start.elapsed().as_secs_f64();
    std::hint::black_box(total_users);
    println!("ops_per_s={}", (n as f64) / elapsed);
}
