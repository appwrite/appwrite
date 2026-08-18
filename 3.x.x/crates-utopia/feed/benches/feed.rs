use serde_json::json;
use std::sync::Arc;
use std::time::Instant;
use utopia_feed::{Consumer, MemoryCursor, MemoryStore, Producer, Readable};

fn main() {
    let store = MemoryStore::new("bench").unwrap();
    let producer = Producer::new(store.clone(), "urn:bench").unwrap();
    for i in 0..100 {
        producer
            .produce("bench.event", json!({"n": i}), "")
            .unwrap();
    }
    let consumer = Consumer::new(Arc::new(store), Arc::new(MemoryCursor::new()), "reader").unwrap();
    let _ = consumer.consume_any(|_| {});

    let iters = 20_000u64;
    let start = Instant::now();
    for i in 0..iters {
        let store = MemoryStore::new("bench").unwrap();
        let producer = Producer::new(store.clone(), "urn:bench").unwrap();
        producer
            .produce("bench.event", json!({"n": i}), "")
            .unwrap();
        std::hint::black_box(store.read(None, 10).unwrap());
    }
    let elapsed = start.elapsed();
    println!(
        "feed_produce_read: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
