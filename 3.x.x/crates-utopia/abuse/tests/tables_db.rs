use serde_json::json;
use utopia_abuse::adapters::time_limit::appwrite::{Client, TablesDB, TABLE_ID, TABLE_LOCK};
use utopia_abuse::{Abuse, Adapter};
use utopia_test_wiremock::{method, Mock, MockServer, RecordedRequest, Respond, ResponseTemplate};

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .enable_all()
        .build()
        .expect("tokio")
}

#[derive(Debug)]
struct AppwriteMock {
    inner: std::sync::Mutex<State>,
}

#[derive(Debug, Default)]
struct State {
    databases: std::collections::HashSet<String>,
    tables: std::collections::HashSet<(String, String)>,
    columns: Vec<serde_json::Value>,
    indexes: Vec<serde_json::Value>,
    rows: Vec<serde_json::Value>,
}

impl AppwriteMock {
    fn new() -> Self {
        Self {
            inner: std::sync::Mutex::new(State::default()),
        }
    }
}

impl Respond for AppwriteMock {
    fn respond(&self, request: &RecordedRequest) -> ResponseTemplate {
        let mut state = self.inner.lock().expect("lock");
        let path = request.url.path();
        let method = request.method.as_str();
        match (method, path) {
            ("GET", p) if p.ends_with(&format!("/tables/{TABLE_LOCK}")) => {
                if state.tables.iter().any(|(_, id)| id == TABLE_LOCK) {
                    ResponseTemplate::new(200).set_body_json(json!({ "$id": TABLE_LOCK }))
                } else {
                    ResponseTemplate::new(404).set_body_json(json!({
                        "message": "missing",
                        "type": "table_not_found",
                    }))
                }
            }
            ("POST", "/v1/tablesdb" | "/tablesdb") => {
                let body: serde_json::Value =
                    serde_json::from_slice(&request.body).unwrap_or(json!({}));
                let id = body
                    .get("databaseId")
                    .and_then(serde_json::Value::as_str)
                    .unwrap_or("")
                    .to_owned();
                if !state.databases.insert(id.clone()) {
                    return ResponseTemplate::new(409).set_body_json(json!({
                        "message": "exists",
                        "type": "database_already_exists",
                    }));
                }
                ResponseTemplate::new(201).set_body_json(json!({ "$id": id }))
            }
            ("POST", p) if p.ends_with("/tables") => {
                let body: serde_json::Value =
                    serde_json::from_slice(&request.body).unwrap_or(json!({}));
                let table_id = body
                    .get("tableId")
                    .and_then(serde_json::Value::as_str)
                    .unwrap_or("")
                    .to_owned();
                let db = path
                    .split('/')
                    .find(|s| s.starts_with("abuse") || s.contains("cicd") || !s.is_empty())
                    .unwrap_or("db")
                    .to_owned();
                let db_id = path
                    .split('/')
                    .nth(path.split('/').count().saturating_sub(2))
                    .unwrap_or("db")
                    .to_owned();
                let _ = db;
                if !state.tables.insert((db_id.clone(), table_id.clone())) {
                    return ResponseTemplate::new(409).set_body_json(json!({
                        "message": "exists",
                        "type": "table_already_exists",
                    }));
                }
                if table_id == TABLE_ID {
                    if let Some(cols) = body.get("columns").and_then(serde_json::Value::as_array) {
                        state.columns = cols
                            .iter()
                            .cloned()
                            .map(|mut col| {
                                if let serde_json::Value::Object(map) = &mut col {
                                    map.insert("status".into(), json!("available"));
                                }
                                col
                            })
                            .collect();
                    }
                    if let Some(indexes) = body.get("indexes").and_then(serde_json::Value::as_array)
                    {
                        state.indexes = indexes
                            .iter()
                            .cloned()
                            .map(|mut index| {
                                if let serde_json::Value::Object(map) = &mut index {
                                    map.insert("status".into(), json!("available"));
                                }
                                index
                            })
                            .collect();
                    }
                }
                ResponseTemplate::new(201).set_body_json(json!({ "$id": table_id }))
            }
            ("GET", p) if p.ends_with("/columns") => ResponseTemplate::new(200)
                .set_body_json(json!({ "columns": state.columns.clone() })),
            ("GET", p) if p.ends_with("/indexes") => ResponseTemplate::new(200)
                .set_body_json(json!({ "indexes": state.indexes.clone() })),
            ("GET", p) if p.ends_with("/rows") => {
                ResponseTemplate::new(200).set_body_json(json!({ "rows": state.rows.clone() }))
            }
            ("POST", p) if p.ends_with("/rows") => {
                let body: serde_json::Value =
                    serde_json::from_slice(&request.body).unwrap_or(json!({}));
                let mut row = body.get("data").cloned().unwrap_or(json!({}));
                if let serde_json::Value::Object(map) = &mut row {
                    map.insert(
                        "$id".into(),
                        body.get("rowId").cloned().unwrap_or(json!("row1")),
                    );
                }
                state.rows.push(row.clone());
                ResponseTemplate::new(201).set_body_json(row)
            }
            ("PATCH", p) if p.ends_with("/increment") => {
                if let Some(row) = state.rows.first_mut() {
                    if let Some(obj) = row.as_object_mut() {
                        let count = obj
                            .get("count")
                            .and_then(serde_json::Value::as_i64)
                            .unwrap_or(0);
                        obj.insert("count".into(), json!(count + 1));
                    }
                }
                ResponseTemplate::new(200).set_body_json(json!({}))
            }
            ("PATCH", p) if p.contains("/rows/") => {
                let body: serde_json::Value =
                    serde_json::from_slice(&request.body).unwrap_or(json!({}));
                if let Some(count) = body.pointer("/data/count") {
                    if let Some(row) = state.rows.first_mut() {
                        if let Some(obj) = row.as_object_mut() {
                            obj.insert("count".into(), count.clone());
                        }
                    }
                }
                ResponseTemplate::new(200).set_body_json(json!({}))
            }
            ("DELETE", p) if p.ends_with("/rows") => {
                let n = state.rows.len();
                state.rows.clear();
                ResponseTemplate::new(200).set_body_json(json!({ "total": n }))
            }
            _ => ResponseTemplate::new(404).set_body_json(json!({
                "message": format!("unhandled {method} {path}"),
                "type": "general_not_found",
            })),
        }
    }
}

fn client_for(server: &MockServer) -> Client {
    let mut client = Client::new();
    client
        .set_endpoint(format!("{}/v1", server.uri()))
        .set_project("proj")
        .set_key("key");
    client.clone_client()
}

#[test]
fn setup_hit_cleanup_with_shared_mock() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    let mock = std::sync::Arc::new(AppwriteMock::new());
    rt.block_on(async {
        Mock::given(method("GET"))
            .respond_with_dyn(Shared(mock.clone()))
            .mount(&server)
            .await;
        Mock::given(method("POST"))
            .respond_with_dyn(Shared(mock.clone()))
            .mount(&server)
            .await;
        Mock::given(method("PATCH"))
            .respond_with_dyn(Shared(mock.clone()))
            .mount(&server)
            .await;
        Mock::given(method("DELETE"))
            .respond_with_dyn(Shared(mock.clone()))
            .mount(&server)
            .await;
    });

    let client = client_for(&server);
    let adapter = TablesDB::new("login-{{ip}}", 2, 60, client, "abuse-test");
    adapter.setup().expect("setup");
    adapter.setup().expect("idempotent");

    let mut adapter = adapter;
    adapter.set_param("{{ip}}", "0.0.0.20");
    let mut abuse = Abuse::new(adapter);
    assert!(!abuse.check().unwrap());
    assert!(!abuse.check().unwrap());
    assert!(abuse.check().unwrap());
    assert!(abuse.cleanup(0).unwrap());
}

#[derive(Clone, Debug)]
struct Shared(std::sync::Arc<AppwriteMock>);

impl Respond for Shared {
    fn respond(&self, request: &RecordedRequest) -> ResponseTemplate {
        self.0.respond(request)
    }
}
