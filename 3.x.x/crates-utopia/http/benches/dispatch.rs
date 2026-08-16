use serde_json::json;
use std::time::Instant;
use utopia_http::prelude::*;

#[tokio::main]
async fn main() {
    let resources = Container::new();
    let adapter = MemoryAdapter::new(resources);
    let http = Http::new(adapter, "UTC");
    http.get("/work").unwrap().action(|ctx| async move {
        ctx.response().text("ok")?;
        Ok(())
    });

    let iters = 20_000u64;
    let start = Instant::now();
    for _ in 0..iters {
        let res = Response::new();
        http.execute(Request::new("GET", "/work"), res)
            .await
            .unwrap();
    }
    let elapsed = start.elapsed().as_secs_f64();
    println!(
        "dispatch_execute iters={iters} elapsed_s={elapsed:.4} ops_per_s={:.0}",
        iters as f64 / elapsed
    );
    let _ = json!({});
}
