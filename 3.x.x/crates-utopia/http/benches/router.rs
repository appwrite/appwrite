use std::sync::Arc;
use std::time::Instant;
use utopia_http::{Route, Router};

fn main() {
    let router = Router::new();
    for i in 0..200 {
        let path = format!("/item/{i}/:id");
        let route = Arc::new(Route::new(vec!["GET".into()], path, i));
        router.add_route(route).unwrap();
    }
    let static_route = Arc::new(Route::new(vec!["GET".into()], "/health", 999));
    router.add_route(static_route).unwrap();

    let iters = 200_000u64;
    let start = Instant::now();
    for i in 0..iters {
        let path = if i % 10 == 0 {
            "/health".to_string()
        } else {
            format!("/item/{}/abc", i % 200)
        };
        let _ = router.match_route("GET", &path);
    }
    let elapsed = start.elapsed().as_secs_f64();
    println!(
        "router_match iters={iters} elapsed_s={elapsed:.4} ops_per_s={:.0}",
        iters as f64 / elapsed
    );
}
