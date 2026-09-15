use std::collections::BTreeMap;

use appwrite_event::{
    generate_events, DeleteMessage, DeletePublisher, Event, MemoryDeletePublisher,
};
use serde_json::json;

fn bench_event_to_message(n: u64) -> f64 {
    let start = std::time::Instant::now();
    for i in 0..n {
        let user_id = format!("user{i}");
        let _ = Event::new()
            .set_project(json!({"$id": "proj1"}))
            .set_event("users.[userId].create")
            .set_param("userId", user_id)
            .set_payload(json!({"email": "a@b.com"}))
            .to_message();
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn bench_generate_events_sub_resource(n: u64) -> f64 {
    let mut params = BTreeMap::new();
    params.insert("userId".to_string(), "user1".to_string());
    params.insert("sessionId".to_string(), "session1".to_string());

    let start = std::time::Instant::now();
    for _ in 0..n {
        let _ = generate_events("users.[userId].sessions.[sessionId].create", &params);
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn bench_memory_delete_publisher(n: u64) -> f64 {
    let publisher = MemoryDeletePublisher::new();
    let start = std::time::Instant::now();
    for _ in 0..n {
        publisher.enqueue(DeleteMessage::new("document"));
    }
    let elapsed = start.elapsed().as_secs_f64();
    (n as f64) / elapsed
}

fn main() {
    let n = 50_000u64;
    println!("event_to_message ops_per_s={}", bench_event_to_message(n));
    println!(
        "generate_events_sub_resource ops_per_s={}",
        bench_generate_events_sub_resource(n)
    );
    println!(
        "memory_delete_publisher_enqueue ops_per_s={}",
        bench_memory_delete_publisher(n)
    );
}
