use std::time::Instant;

use serde_json::json;
use utopia_queue::broker::Redis;
use utopia_queue::prelude::*;

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..iters.min(1_000) {
        f();
    }
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}

fn main() {
    bench("queue_construct", 200_000, || {
        std::hint::black_box(Queue::new("emails").unwrap());
    });

    bench("message_as_array", 200_000, || {
        let mut msg = Message::new();
        msg.set_pid("pid")
            .set_queue("emails")
            .set_timestamp(1_700_000_000)
            .set_payload(json!({"n": 1}))
            .set_attempts(0);
        std::hint::black_box(msg.as_array());
    });

    let connection = InMemoryConnection::new().with_empty_yield(std::time::Duration::ZERO);
    let broker = Redis::new(connection.clone(), connection);
    let queue = Queue::new("bench").unwrap();
    bench("enqueue_dequeue", 50_000, || {
        broker.enqueue(&queue, json!({"n": 1}), false).unwrap();
        let msg = broker.receive(&queue, 0).unwrap().unwrap();
        broker.commit(&queue, &msg).unwrap();
        std::hint::black_box(msg);
    });
}
