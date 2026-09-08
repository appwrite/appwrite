use std::time::Instant;

use utopia_query::builder::MySql;
use utopia_query::query::Query;
use utopia_query::tokenizer::Tokenizer;

fn bench(name: &str, mut f: impl FnMut()) {
    let warmup = Instant::now();
    while warmup.elapsed().as_millis() < 50 {
        f();
    }
    let iters = 50_000u64;
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
    let json = r#"{"method":"equal","attribute":"status","values":["active"]}"#;
    bench("query_parse", || {
        std::hint::black_box(Query::parse(json).unwrap());
    });

    bench("query_build_mysql", || {
        let mut b = MySql::new();
        b.select(["id", "name"])
            .from_table("users")
            .filter([
                Query::equal("status", ["active"]),
                Query::greater_than("age", 18),
            ])
            .sort_asc("name", None)
            .limit(25);
        std::hint::black_box(b.build().unwrap());
    });

    let mut tok = Tokenizer::mysql();
    bench("query_tokenize", || {
        std::hint::black_box(
            tok.tokenize("SELECT id, name FROM users WHERE status = 'active' AND age > 18")
                .unwrap(),
        );
    });
}
