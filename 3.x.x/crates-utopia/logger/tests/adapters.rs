//! Adapter unit tests with wiremock. PHP e2e hits live APIs; these assert
//! URL, headers, and JSON body shape 1:1 with the PHP adapters.

use std::time::{SystemTime, UNIX_EPOCH};

use serde_json::{json, Value};
use utopia_logger::{
    Adapter, AppSignal, Breadcrumb, Log, LogOwl, Logger, LoggerError, Raygun, Sentry, User,
};
use utopia_test_wiremock::{
    method, path, query_param, Mock, MockServer, RecordedRequest, ResponseTemplate,
};

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .expect("tokio runtime")
}

fn now() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .expect("time")
        .as_secs_f64()
}

/// Matches `tests/e2e/AdapterBase.php` `setUp()`.
fn sample_log() -> Log {
    let ts = now();
    let mut log = Log::new();
    log.set_action("controller.database.deleteDocument");
    log.set_environment(Log::ENVIRONMENT_PRODUCTION).unwrap();
    log.set_namespace("api");
    log.set_server(Some("digitalocean-us-001"));
    log.set_type(Log::TYPE_ERROR).unwrap();
    log.set_version("0.11.5");
    log.set_message("Document efgh5678 not found");
    log.set_user(User::new(Some("efgh5678"), None, None));
    log.add_breadcrumb(
        Breadcrumb::new(
            Log::TYPE_DEBUG,
            "http",
            "DELETE /api/v1/database/abcd1234/efgh5678",
            ts - 500.0,
        )
        .unwrap(),
    );
    log.add_breadcrumb(
        Breadcrumb::new(Log::TYPE_DEBUG, "auth", "Using API key", ts - 400.0).unwrap(),
    );
    log.add_breadcrumb(
        Breadcrumb::new(
            Log::TYPE_INFO,
            "auth",
            "Authenticated with * Using API Key",
            ts - 350.0,
        )
        .unwrap(),
    );
    log.add_breadcrumb(
        Breadcrumb::new(
            Log::TYPE_INFO,
            "database",
            "Found collection abcd1234",
            ts - 300.0,
        )
        .unwrap(),
    );
    log.add_breadcrumb(
        Breadcrumb::new(
            Log::TYPE_DEBUG,
            "database",
            "Permission for collection abcd1234 met",
            ts - 200.0,
        )
        .unwrap(),
    );
    log.add_breadcrumb(
        Breadcrumb::new(
            Log::TYPE_ERROR,
            "database",
            "Missing document when searching by ID!",
            ts,
        )
        .unwrap(),
    );
    log.add_tag("sdk", "Flutter");
    log.add_tag("sdkVersion", "0.0.1");
    log.add_tag("authMode", "default");
    log.add_tag("authMethod", "cookie");
    log.add_tag("authProvider", "MagicLink");
    log.add_extra("urgent", false);
    log.add_extra("isExpected", true);
    log.add_extra("file", "/User/example/server/src/server/server.js");
    log.add_extra("line", "15");
    log
}

fn header<'a>(request: &'a RecordedRequest, name: &str) -> &'a str {
    request
        .headers
        .get(name)
        .and_then(|v| v.to_str().ok())
        .unwrap_or("")
}

fn body_json(request: &RecordedRequest) -> Value {
    serde_json::from_slice(&request.body).expect("json body")
}

fn mount_ok(rt: &tokio::runtime::Runtime, server: &MockServer, status: u16, path_suffix: &str) {
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path(path_suffix))
            .respond_with(ResponseTemplate::new(status))
            .mount(server)
            .await;
    });
}

#[test]
fn sentry_name_and_supported() {
    let adapter = Sentry::new("proj", "key");
    assert_eq!(adapter.get_name(), "sentry");
    assert_eq!(Sentry::get_name(), "sentry");
    assert_eq!(
        adapter.get_supported_types(),
        &[
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR
        ]
    );
    assert_eq!(
        adapter.get_supported_environments(),
        &[Log::ENVIRONMENT_STAGING, Log::ENVIRONMENT_PRODUCTION]
    );
    assert_eq!(
        adapter.get_supported_breadcrumb_types(),
        &[
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR
        ]
    );
}

#[test]
fn sentry_push_url_headers_and_body() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_ok(&rt, &server, 200, "/api/my-project/store/");

    let adapter = Sentry::new_with("my-project", "sentry-key", server.uri(), 5, 1);
    let log = sample_log();
    let logger = Logger::new(adapter);
    assert_eq!(logger.add_log(&log).unwrap(), 200);

    let requests = rt.block_on(server.received_requests()).expect("requests");
    assert_eq!(requests.len(), 1);
    let request = &requests[0];
    assert_eq!(request.method.as_str(), "POST");
    assert_eq!(request.url.path(), "/api/my-project/store/");
    assert!(
        header(request, "content-type").starts_with("application/json"),
        "content-type={}",
        header(request, "content-type")
    );
    assert_eq!(
        header(request, "x-sentry-auth"),
        "Sentry sentry_version=7, sentry_key=sentry-key, sentry_client=utopia-logger/0.1.0"
    );

    let body = body_json(request);
    assert_eq!(body["platform"], json!("php"));
    assert_eq!(body["level"], json!("error"));
    assert_eq!(body["logger"], json!("api"));
    assert_eq!(
        body["transaction"],
        json!("controller.database.deleteDocument")
    );
    assert_eq!(body["server_name"], json!("digitalocean-us-001"));
    assert_eq!(body["release"], json!("0.11.5"));
    assert_eq!(body["environment"], json!("production"));
    assert_eq!(
        body["message"]["message"],
        json!("Document efgh5678 not found")
    );
    assert_eq!(
        body["exception"]["values"][0]["type"],
        json!("Document efgh5678 not found")
    );
    assert_eq!(
        body["exception"]["values"][0]["stacktrace"]["frames"],
        json!([])
    );
    assert_eq!(body["tags"]["sdk"], json!("Flutter"));
    assert_eq!(body["tags"]["sdkVersion"], json!("0.0.1"));
    assert_eq!(body["extra"]["urgent"], json!(false));
    assert_eq!(body["extra"]["isExpected"], json!(true));
    assert_eq!(
        body["extra"]["file"],
        json!("/User/example/server/src/server/server.js")
    );
    assert_eq!(body["extra"]["line"], json!("15"));
    assert_eq!(body["user"]["id"], json!("efgh5678"));
    assert_eq!(body["user"]["email"], Value::Null);
    assert_eq!(body["user"]["username"], Value::Null);
    assert_eq!(body["breadcrumbs"].as_array().map(Vec::len), Some(6));
    assert_eq!(body["breadcrumbs"][0]["type"], json!("default"));
    assert_eq!(body["breadcrumbs"][0]["level"], json!("debug"));
    assert_eq!(body["breadcrumbs"][0]["category"], json!("http"));
    assert_eq!(
        body["breadcrumbs"][0]["message"],
        json!("DELETE /api/v1/database/abcd1234/efgh5678")
    );
    assert!(body["timestamp"].as_f64().is_some() || body["timestamp"].as_i64().is_some());
}

#[test]
fn sentry_detailed_trace_is_reversed() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_ok(&rt, &server, 200, "/api/p/store/");

    let mut log = sample_log();
    log.add_extra(
        "detailedTrace",
        json!([
            {"file": "newest.php", "line": 30, "function": "inner"},
            {"file": "oldest.php", "line": 10, "function": "outer"}
        ]),
    );

    let adapter = Sentry::new_with("p", "k", server.uri(), 5, 1);
    assert_eq!(adapter.push(&log).unwrap(), 200);

    let requests = rt.block_on(server.received_requests()).expect("requests");
    let frames = &body_json(&requests[0])["exception"]["values"][0]["stacktrace"]["frames"];
    assert_eq!(frames[0]["filename"], json!("oldest.php"));
    assert_eq!(frames[0]["lineno"], json!(10));
    assert_eq!(frames[0]["function"], json!("outer"));
    assert_eq!(frames[1]["filename"], json!("newest.php"));
    assert_eq!(frames[1]["lineno"], json!(30));
}

#[test]
fn sentry_detailed_trace_must_be_array() {
    let mut log = sample_log();
    log.add_extra("detailedTrace", "not-an-array");
    let adapter = Sentry::new("p", "k");
    let err = adapter.push(&log).unwrap_err();
    assert_eq!(
        err,
        LoggerError::Message("detailedTrace must be an array".to_string())
    );
}

#[test]
fn sentry_user_null_when_missing() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_ok(&rt, &server, 200, "/api/p/store/");

    let mut log = sample_log();
    log.set_user(User::new(None, None, None));
    // PHP empty(User object) is false, so user is still serialized.
    let adapter = Sentry::new_with("p", "k", server.uri(), 5, 1);
    adapter.push(&log).unwrap();
    let body = body_json(&rt.block_on(server.received_requests()).unwrap()[0]);
    assert_eq!(body["user"]["id"], Value::Null);
    drop(server);

    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_ok(&rt, &server, 200, "/api/p/store/");
    let mut log = Log::new();
    log.set_action("a");
    log.set_environment(Log::ENVIRONMENT_PRODUCTION).unwrap();
    log.set_message("m");
    log.set_type(Log::TYPE_ERROR).unwrap();
    log.set_version("1");
    let adapter = Sentry::new_with("p", "k", server.uri(), 5, 1);
    adapter.push(&log).unwrap();
    let body = body_json(&rt.block_on(server.received_requests()).unwrap()[0]);
    assert_eq!(body["user"], Value::Null);
    assert_eq!(body["tags"], json!([]));
    assert_eq!(body["extra"], json!([]));
}

#[test]
fn sentry_http_error_status_is_returned() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_ok(&rt, &server, 403, "/api/p/store/");
    let adapter = Sentry::new_with("p", "k", server.uri(), 5, 1);
    assert_eq!(adapter.push(&sample_log()).unwrap(), 403);
}

#[test]
fn sentry_fetch_error_returns_500() {
    let adapter = Sentry::new_with("p", "k", "http://127.0.0.1:1", 1, 1);
    assert_eq!(adapter.push(&sample_log()).unwrap(), 500);
}

#[test]
fn sentry_rejects_verbose() {
    let adapter = Sentry::new("p", "k");
    let mut log = sample_log();
    log.set_type(Log::TYPE_VERBOSE).unwrap();
    let err = adapter.validate(&log).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Supported log types for this adapter are: info, debug, warning, error"
    );
}

#[test]
fn raygun_name_and_supported() {
    let adapter = Raygun::new("key");
    assert_eq!(adapter.get_name(), "raygun");
    assert_eq!(Raygun::get_name(), "raygun");
    assert_eq!(adapter.get_supported_types().len(), 5);
    assert!(adapter.get_supported_types().contains(&Log::TYPE_VERBOSE));
}

#[test]
fn raygun_push_url_headers_and_body() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_ok(&rt, &server, 202, "/entries");

    let adapter = Raygun::new("raygun-key").with_host(server.uri());
    let log = sample_log();
    let logger = Logger::new(adapter);
    assert_eq!(logger.add_log(&log).unwrap(), 202);

    let requests = rt.block_on(server.received_requests()).expect("requests");
    assert_eq!(requests.len(), 1);
    let request = &requests[0];
    assert_eq!(request.url.path(), "/entries");
    assert!(header(request, "content-type").starts_with("application/json"));
    assert_eq!(header(request, "x-apikey"), "raygun-key");

    let body = body_json(request);
    assert!(body["occurredOn"].as_i64().is_some());
    assert_eq!(body["details"]["machineName"], json!("digitalocean-us-001"));
    assert_eq!(body["details"]["groupingKey"], json!("api"));
    assert_eq!(body["details"]["version"], json!("0.11.5"));
    assert_eq!(
        body["details"]["error"]["className"],
        json!("controller.database.deleteDocument")
    );
    assert_eq!(
        body["details"]["error"]["message"],
        json!("Document efgh5678 not found")
    );
    let tags = body["details"]["tags"].as_array().expect("tags");
    assert!(tags.iter().any(|t| t == &json!("sdk: Flutter")));
    assert!(tags.iter().any(|t| t == &json!("type: error")));
    assert!(tags.iter().any(|t| t == &json!("environment: production")));
    assert!(tags.iter().any(|t| t == &json!("sdk: utopia-logger/0.1.0")));
    assert_eq!(body["details"]["userCustomData"]["urgent"], json!(false));
    assert_eq!(body["details"]["user"]["isAnonymous"], json!(false));
    assert_eq!(body["details"]["user"]["identifier"], json!("efgh5678"));
    assert_eq!(body["details"]["user"]["email"], Value::Null);
    assert_eq!(body["details"]["user"]["fullName"], Value::Null);
    assert_eq!(
        body["details"]["breadcrumbs"].as_array().map(Vec::len),
        Some(6)
    );
    assert_eq!(body["details"]["breadcrumbs"][0]["level"], json!("request"));
    assert_eq!(body["details"]["breadcrumbs"][0]["type"], json!("debug"));
    assert!(body["details"]["breadcrumbs"][0]["timestamp"]
        .as_i64()
        .is_some());
}

#[test]
fn raygun_anonymous_user_when_missing() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_ok(&rt, &server, 202, "/entries");
    let mut log = Log::new();
    log.set_action("a");
    log.set_environment(Log::ENVIRONMENT_PRODUCTION).unwrap();
    log.set_message("m");
    log.set_type(Log::TYPE_ERROR).unwrap();
    log.set_version("1");
    Raygun::new("k").with_host(server.uri()).push(&log).unwrap();
    let body = body_json(&rt.block_on(server.received_requests()).unwrap()[0]);
    assert_eq!(body["details"]["user"]["isAnonymous"], json!(true));
    assert_eq!(body["details"]["user"]["identifier"], Value::Null);
}

#[test]
fn raygun_http_error_and_fetch_error() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_ok(&rt, &server, 401, "/entries");
    assert_eq!(
        Raygun::new("k")
            .with_host(server.uri())
            .push(&sample_log())
            .unwrap(),
        401
    );
    assert_eq!(
        Raygun::new("k")
            .with_host("http://127.0.0.1:1")
            .push(&sample_log())
            .unwrap(),
        500
    );
}

#[test]
fn appsignal_name_and_supported() {
    let adapter = AppSignal::new("key");
    assert_eq!(adapter.get_name(), "appSignal");
    assert_eq!(AppSignal::get_name(), "appSignal");
    assert!(adapter.get_supported_types().contains(&Log::TYPE_VERBOSE));
}

#[test]
fn appsignal_push_url_headers_and_body() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path("/collect"))
            .and(query_param("api_key", "app-key"))
            .and(query_param("version", "1.3.19"))
            .respond_with(ResponseTemplate::new(204))
            .mount(&server)
            .await;
    });

    let adapter = AppSignal::new("app-key").with_host(server.uri());
    let log = sample_log();
    let logger = Logger::new(adapter);
    assert_eq!(logger.add_log(&log).unwrap(), 204);

    let requests = rt.block_on(server.received_requests()).expect("requests");
    assert_eq!(requests.len(), 1);
    let request = &requests[0];
    assert_eq!(request.url.path(), "/collect");
    let query: std::collections::HashMap<_, _> = request.url.query_pairs().collect();
    assert_eq!(
        query.get("api_key").map(std::ops::Deref::deref),
        Some("app-key")
    );
    assert_eq!(
        query.get("version").map(std::ops::Deref::deref),
        Some("1.3.19")
    );
    assert!(header(request, "content-type").starts_with("application/json"));

    let body = body_json(request);
    assert!(body["timestamp"].as_i64().is_some());
    assert_eq!(body["namespace"], json!("api"));
    assert_eq!(body["error"]["name"], json!("Document efgh5678 not found"));
    assert_eq!(
        body["error"]["message"],
        json!("Document efgh5678 not found")
    );
    assert_eq!(body["error"]["backtrace"], json!([]));
    assert_eq!(body["environment"]["environment"], json!("production"));
    assert_eq!(body["environment"]["server"], json!("digitalocean-us-001"));
    assert_eq!(body["environment"]["version"], json!("0.11.5"));
    assert_eq!(body["revision"], json!("0.11.5"));
    assert_eq!(body["action"], json!("controller.database.deleteDocument"));
    assert_eq!(body["params"]["urgent"], json!("false"));
    assert_eq!(body["params"]["isExpected"], json!("true"));
    assert_eq!(
        body["params"]["file"],
        json!("'/User/example/server/src/server/server.js'")
    );
    assert_eq!(body["params"]["line"], json!("'15'"));
    assert_eq!(body["tags"]["sdkVersion"], json!("0.0.1"));
    assert_eq!(body["tags"]["type"], json!("error"));
    assert_eq!(body["tags"]["userId"], json!("efgh5678"));
    assert_eq!(body["tags"]["sdk"], json!("utopia-logger/0.1.0"));
}

#[test]
fn appsignal_sdk_tag_overwrites_log_sdk_tag() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path("/collect"))
            .respond_with(ResponseTemplate::new(204))
            .mount(&server)
            .await;
    });
    AppSignal::new("k")
        .with_host(server.uri())
        .push(&sample_log())
        .unwrap();
    let body = body_json(&rt.block_on(server.received_requests()).unwrap()[0]);
    assert_eq!(body["tags"]["sdk"], json!("utopia-logger/0.1.0"));
    assert_eq!(body["tags"]["userId"], json!("efgh5678"));
    assert!(body["tags"].get("userName").is_none());
    assert!(body["tags"].get("userEmail").is_none());
    assert_eq!(body["breadcrumbs"].as_array().map(Vec::len), Some(6));
    assert_eq!(body["breadcrumbs"][0]["category"], json!("http"));
    assert_eq!(
        body["breadcrumbs"][0]["action"],
        json!("DELETE /api/v1/database/abcd1234/efgh5678")
    );
    assert_eq!(body["breadcrumbs"][0]["metadata"]["type"], json!("debug"));
}

#[test]
fn appsignal_fetch_error_returns_500() {
    assert_eq!(
        AppSignal::new("k")
            .with_host("http://127.0.0.1:1")
            .push(&sample_log())
            .unwrap(),
        500
    );
}

#[test]
fn logowl_name_and_supported() {
    let adapter = LogOwl::new("ticket");
    assert_eq!(adapter.get_name(), "logOwl");
    assert_eq!(LogOwl::get_name(), "logOwl");
    assert_eq!(LogOwl::get_adapter_type(), "utopia-logger");
    assert_eq!(LogOwl::get_adapter_version(), "0.1.0");
    assert_eq!(adapter.get_supported_types(), &[Log::TYPE_ERROR]);
    assert!(!adapter
        .get_supported_breadcrumb_types()
        .contains(&Log::TYPE_VERBOSE));
}

#[test]
fn logowl_push_url_headers_and_body() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    let host = format!("{}/logging/", server.uri());
    mount_ok(&rt, &server, 200, "/logging/error");

    let adapter = LogOwl::new_with("service-ticket", host, 5, 1);
    let log = sample_log();
    let logger = Logger::new(adapter);
    assert_eq!(logger.add_log(&log).unwrap(), 200);

    let requests = rt.block_on(server.received_requests()).expect("requests");
    assert_eq!(requests.len(), 1);
    let request = &requests[0];
    assert_eq!(request.url.path(), "/logging/error");
    assert!(header(request, "content-type").starts_with("application/json"));

    let body = body_json(request);
    assert_eq!(body["ticket"], json!("service-ticket"));
    assert_eq!(body["message"], json!("controller.database.deleteDocument"));
    assert_eq!(
        body["path"],
        json!("/User/example/server/src/server/server.js")
    );
    assert_eq!(body["line"], json!("15"));
    assert_eq!(body["stacktrace"], json!(""));
    assert_eq!(body["badges"]["environment"], json!("production"));
    assert_eq!(body["badges"]["namespace"], json!("api"));
    assert_eq!(body["badges"]["version"], json!("0.11.5"));
    assert_eq!(
        body["badges"]["message"],
        json!("Document efgh5678 not found")
    );
    assert_eq!(body["badges"]["id"], json!("efgh5678"));
    assert_eq!(body["badges"]["$email"], Value::Null);
    assert_eq!(body["badges"]["$username"], Value::Null);
    assert_eq!(body["type"], json!("error"));
    assert_eq!(body["metrics"]["platform"], json!("digitalocean-us-001"));
    assert_eq!(body["logs"].as_array().map(Vec::len), Some(6));
    assert_eq!(body["logs"][0]["type"], json!("log"));
    assert_eq!(
        body["logs"][0]["log"],
        json!("DELETE /api/v1/database/abcd1234/efgh5678")
    );
    assert!(body["timestamp"].as_i64().is_some());
    assert_eq!(body["adapter"]["name"], json!("logOwl"));
    assert_eq!(body["adapter"]["type"], json!("utopia-logger"));
    assert_eq!(body["adapter"]["version"], json!("0.1.0"));
}

#[test]
fn logowl_rejects_info_type() {
    let adapter = LogOwl::new("t");
    let mut log = sample_log();
    log.set_type(Log::TYPE_INFO).unwrap();
    let err = adapter.validate(&log).unwrap_err();
    assert_eq!(
        err.to_string(),
        "Supported log types for this adapter are: error"
    );
}

#[test]
fn logowl_fetch_error_returns_500() {
    let adapter = LogOwl::new_with("t", "http://127.0.0.1:1/", 1, 1);
    assert_eq!(adapter.push(&sample_log()).unwrap(), 500);
}

#[test]
fn timeout_zero_uses_defaults() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_ok(&rt, &server, 200, "/api/p/store/");
    let adapter = Sentry::new_with("p", "k", server.uri(), 0, 0);
    assert_eq!(adapter.push(&sample_log()).unwrap(), 200);
}
