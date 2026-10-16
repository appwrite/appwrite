use serde_json::{json, Map};
use std::time::Instant;
use utopia_audit::{Audit, Memory, Query};

fn main() {
    let mut audit = Audit::new(Memory::new());
    audit.setup().unwrap();
    let mut data = Map::new();
    data.insert("k".into(), json!("v"));
    for i in 0..100 {
        audit
            .log(
                Some("user"),
                "update",
                format!("doc/{i}"),
                "ua",
                "127.0.0.1",
                data.clone(),
            )
            .unwrap();
    }

    let iters = 20_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(
            audit
                .find(&[Query::equal("userId", "user"), Query::limit(10)])
                .unwrap(),
        );
    }
    let elapsed = start.elapsed();
    println!(
        "audit_find: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
