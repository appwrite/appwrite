use std::time::Instant;

use utopia_cache::adapter::Memory as MemoryCache;
use utopia_cache::Cache;
use utopia_database::adapter::Memory;
use utopia_database::{AttrValue, Database, Document};

fn main() {
    let mut db = Database::new(Memory::new(), Cache::new(MemoryCache::new()));
    db.set_database("bench").unwrap();
    db.set_namespace("ns").unwrap();
    db.create(None).unwrap();
    db.skip_authorization(|db| db.create_collection("items", vec![], vec![], None, true))
        .unwrap();

    let iters = 5_000u64;
    let start = Instant::now();
    for i in 0..iters {
        let id = format!("id{i}");
        db.skip_authorization(|db| {
            db.create_document(
                "items",
                Document::from_pairs([
                    ("$id", AttrValue::from(id.as_str())),
                    ("n", AttrValue::from(i as i64)),
                ])
                .unwrap(),
            )
        })
        .unwrap();
    }
    let elapsed = start.elapsed();
    println!(
        "database_create_document: ops_per_s={:.0} ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
