use std::time::Instant;
use utopia_openapi::Parser;

fn main() {
    let doc = r#"{"openapi":"3.1.0","info":{"title":"Bench","version":"1"},"paths":{"/x":{"get":{"responses":{"200":{"description":"ok"}}}}}}"#;
    let _ = Parser::parse(doc, None).unwrap();

    let iters = 20_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        std::hint::black_box(Parser::parse(doc, None).unwrap());
    }
    let elapsed = start.elapsed();
    println!(
        "openapi_parse: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}
