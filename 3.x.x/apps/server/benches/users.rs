//! Users service-level microbench against an in-process Memory router.
//!
//! Run: `cargo bench -p appwrite-server --bench users`
//! Prints `ops_per_s=` for create+get pairs (same contract as
//! `benchmarks/users/run.sh`).

use std::collections::HashMap;
use std::sync::Arc;
use std::time::Instant;

use appwrite_platform::AppwriteState;
use serde_json::{json, Value};
use utopia_http::{Http, MemoryAdapter, Request, Response};

fn request(method: &str, uri: &str, payload: HashMap<String, Value>) -> Request {
    let mut req = Request::new(method, uri);
    req.set_header("x-appwrite-project", "console");
    req.set_header("x-appwrite-key", "bench-key");
    req.set_payload(payload);
    req
}

fn main() {
    let rt = tokio::runtime::Runtime::new().expect("tokio runtime");
    rt.block_on(async {
        let state = Arc::new(AppwriteState::new());
        state.seed_dev_project("console", "bench-key", &["users.read", "users.write"]);
        let (resources, mut platform) = appwrite_platform::build(state);
        let mut http = Http::new(MemoryAdapter::new(resources), "UTC");
        platform.init_http(&mut http).expect("init_http");

        let n = std::env::var("N")
            .ok()
            .and_then(|s| s.parse().ok())
            .unwrap_or(200u64);

        let start = Instant::now();
        for i in 0..n {
            let id = format!("bench_{i}");
            let mut payload = HashMap::new();
            payload.insert("userId".to_string(), json!(id));
            payload.insert("email".to_string(), json!(format!("{id}@bench.local")));
            payload.insert("password".to_string(), json!("password123"));
            payload.insert("name".to_string(), json!("Bench"));

            let res = Response::new();
            http.run(request("POST", "/v1/users", payload), res.clone())
                .await
                .expect("create");
            assert!(
                res.status_code() == 201 || res.status_code() == 200,
                "create status {} body {}",
                res.status_code(),
                res.body_string()
            );

            let res = Response::new();
            http.run(
                request("GET", &format!("/v1/users/{id}"), HashMap::new()),
                res.clone(),
            )
            .await
            .expect("get");
            assert_eq!(
                res.status_code(),
                200,
                "get status {} body {}",
                res.status_code(),
                res.body_string()
            );
        }
        let elapsed = start.elapsed().as_secs_f64();
        let ops = (n as f64 * 2.0) / elapsed;
        println!("ops_per_s={ops:.2}");
        println!("users_create_get: n={n} elapsed_s={elapsed:.6} ops_per_s={ops:.2}");
    });
}
