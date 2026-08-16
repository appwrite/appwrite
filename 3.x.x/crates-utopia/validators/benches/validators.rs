use serde_json::json;
use std::time::Instant;
use utopia_validators::{Integer, Json, Text, Url, Validator};

fn bench(name: &str, mut f: impl FnMut()) {
    let warmup = Instant::now();
    while warmup.elapsed().as_millis() < 50 {
        f();
    }
    let iters = 200_000u64;
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
    let text = Text::new(256);
    let integer = Integer::new().loose(true);
    let url = Url::new();
    let json_v = Json;
    let v_text = json!("hello world");
    let v_int = json!("42");
    let v_url = json!("https://example.com/path?q=1");
    let v_json = json!("{\"a\":1,\"b\":[1,2,3]}");

    bench("validator_text", || {
        std::hint::black_box(text.is_valid(&v_text));
    });
    bench("validator_integer_loose", || {
        std::hint::black_box(integer.is_valid(&v_int));
    });
    bench("validator_url", || {
        std::hint::black_box(url.is_valid(&v_url));
    });
    bench("validator_json", || {
        std::hint::black_box(json_v.is_valid(&v_json));
    });
}
