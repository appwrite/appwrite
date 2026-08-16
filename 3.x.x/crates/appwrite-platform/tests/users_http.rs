//! End-to-end `/v1/users*` HTTP tests against the in-memory
//! [`appwrite_platform::build`] platform: exercises the `api`-group `Init`
//! hook's project/API-key resolution (mirroring
//! `app/controllers/shared/api.php`) plus the Users module's `create`/`get`/
//! `list`/`delete` actions end to end, the same way a real
//! `X-Appwrite-Project`/`X-Appwrite-Key` request would.

use std::collections::HashMap;
use std::sync::Arc;

use appwrite_platform::AppwriteState;
use serde_json::{json, Value};
use utopia_http::{Http, MemoryAdapter, Request, Response};

const PROJECT_ID: &str = "proj1";
const KEY_SECRET: &str = "standard_test_key_secret";

fn request(method: &str, uri: &str, payload: HashMap<String, Value>) -> Request {
    let mut req = Request::new(method, uri);
    req.set_header("x-appwrite-project", PROJECT_ID);
    req.set_header("x-appwrite-key", KEY_SECRET);
    req.set_payload(payload);
    req
}

fn body_json(res: &Response) -> Value {
    serde_json::from_str(&res.body_string()).expect("response body should be valid JSON")
}

#[tokio::test]
async fn create_and_get_user_round_trip() {
    let state = Arc::new(AppwriteState::new());
    state.seed_dev_project(PROJECT_ID, KEY_SECRET, &["users.read", "users.write"]);
    let (resources, mut platform) = appwrite_platform::build(state);

    let mut http = Http::new(MemoryAdapter::new(resources), "UTC");
    platform.init_http(&mut http).unwrap();

    let mut payload = HashMap::new();
    payload.insert("userId".to_string(), json!("unique()"));
    payload.insert("email".to_string(), json!("user@example.com"));
    payload.insert("password".to_string(), json!("correcthorsebattery"));
    payload.insert("name".to_string(), json!("Ada Lovelace"));

    let res = Response::new();
    http.run(request("POST", "/v1/users", payload), res.clone())
        .await
        .unwrap();
    assert_eq!(
        res.status_code(),
        201,
        "create should return 201: {}",
        res.body_string()
    );

    let created = body_json(&res);
    let user_id = created["$id"]
        .as_str()
        .expect("created user has an $id")
        .to_string();
    assert_eq!(created["email"], json!("user@example.com"));
    assert_eq!(created["name"], json!("Ada Lovelace"));
    assert_eq!(created["status"], json!(true));

    let res = Response::new();
    http.run(
        request("GET", &format!("/v1/users/{user_id}"), HashMap::new()),
        res.clone(),
    )
    .await
    .unwrap();
    assert_eq!(
        res.status_code(),
        200,
        "get should return 200: {}",
        res.body_string()
    );
    let fetched = body_json(&res);
    assert_eq!(fetched["$id"], json!(user_id));
    assert_eq!(fetched["email"], json!("user@example.com"));

    let res = Response::new();
    http.run(request("GET", "/v1/users", HashMap::new()), res.clone())
        .await
        .unwrap();
    assert_eq!(res.status_code(), 200);
    let listed = body_json(&res);
    assert_eq!(listed["total"], json!(1));
    assert_eq!(listed["users"][0]["$id"], json!(user_id));

    let res = Response::new();
    http.run(
        request("DELETE", &format!("/v1/users/{user_id}"), HashMap::new()),
        res.clone(),
    )
    .await
    .unwrap();
    assert_eq!(res.status_code(), 204);

    let res = Response::new();
    http.run(
        request("GET", &format!("/v1/users/{user_id}"), HashMap::new()),
        res.clone(),
    )
    .await
    .unwrap();
    assert_eq!(res.status_code(), 404);
}

#[tokio::test]
async fn missing_project_header_is_rejected() {
    let state = Arc::new(AppwriteState::new());
    state.seed_dev_project(PROJECT_ID, KEY_SECRET, &["users.read", "users.write"]);
    let (resources, mut platform) = appwrite_platform::build(state);

    let mut http = Http::new(MemoryAdapter::new(resources), "UTC");
    platform.init_http(&mut http).unwrap();

    let req = Request::new("GET", "/v1/users");
    let res = Response::new();
    http.run(req, res.clone()).await.unwrap();
    assert_eq!(res.status_code(), 404);
}

#[tokio::test]
async fn key_without_required_scope_is_unauthorized() {
    let state = Arc::new(AppwriteState::new());
    state.seed_dev_project(PROJECT_ID, KEY_SECRET, &["users.read"]);
    let (resources, mut platform) = appwrite_platform::build(state);

    let mut http = Http::new(MemoryAdapter::new(resources), "UTC");
    platform.init_http(&mut http).unwrap();

    let mut payload = HashMap::new();
    payload.insert("userId".to_string(), json!("unique()"));
    let res = Response::new();
    http.run(request("POST", "/v1/users", payload), res.clone())
        .await
        .unwrap();
    assert_eq!(res.status_code(), 401);
}
