//! Shared helpers for `/v1/users*` MemoryAdapter HTTP integration tests.

use std::collections::HashMap;
use std::sync::Arc;

use appwrite_platform::AppwriteState;
use serde_json::{json, Value};
use utopia_http::{Http, MemoryAdapter, Request, Response};
use utopia_platform::Platform;

pub const PROJECT_ID: &str = "proj1";
pub const KEY_SECRET: &str = "standard_test_key_secret";

/// Keeps the platform alive for the lifetime of the HTTP adapter.
pub struct Harness {
    pub http: Http,
    _platform: Platform,
}

pub fn request(method: &str, uri: &str, payload: HashMap<String, Value>) -> Request {
    let mut req = Request::new(method, uri);
    req.set_header("x-appwrite-project", PROJECT_ID);
    req.set_header("x-appwrite-key", KEY_SECRET);
    req.set_payload(payload);
    req
}

pub fn body_json(res: &Response) -> Value {
    serde_json::from_str(&res.body_string()).expect("response body should be valid JSON")
}

pub fn map(pairs: &[(&str, Value)]) -> HashMap<String, Value> {
    pairs
        .iter()
        .map(|(k, v)| ((*k).to_string(), v.clone()))
        .collect()
}

/// Seeded project with `users.read` / `users.write` (enough for all Users
/// routes: session scopes are OR'd with `users.*`).
pub async fn boot() -> Harness {
    boot_with_scopes(&["users.read", "users.write"]).await
}

pub async fn boot_with_scopes(scopes: &[&str]) -> Harness {
    let state = Arc::new(AppwriteState::new());
    state.seed_dev_project(PROJECT_ID, KEY_SECRET, scopes);
    let (resources, mut platform) = appwrite_platform::build(state);
    let mut http = Http::new(MemoryAdapter::new(resources), "UTC");
    platform.init_http(&mut http).unwrap();
    Harness {
        http,
        _platform: platform,
    }
}

pub async fn run(
    http: &Http,
    method: &str,
    uri: &str,
    payload: HashMap<String, Value>,
) -> Response {
    let res = Response::new();
    http.run(request(method, uri, payload), res.clone())
        .await
        .unwrap();
    res
}

pub async fn create_user(http: &Http, email: &str, name: &str) -> Value {
    let res = run(
        http,
        "POST",
        "/v1/users",
        map(&[
            ("userId", json!("unique()")),
            ("email", json!(email)),
            ("password", json!("correcthorsebattery")),
            ("name", json!(name)),
        ]),
    )
    .await;
    assert_eq!(
        res.status_code(),
        201,
        "create user failed: {}",
        res.body_string()
    );
    body_json(&res)
}

pub async fn create_user_id(http: &Http, email: &str, name: &str) -> String {
    create_user(http, email, name).await["$id"]
        .as_str()
        .expect("$id")
        .to_string()
}
