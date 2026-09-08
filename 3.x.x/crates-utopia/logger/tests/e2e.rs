//! Provider e2e against the WireMock container (PHP hits live SaaS APIs).

use std::time::{SystemTime, UNIX_EPOCH};

use utopia_logger::{AppSignal, Breadcrumb, Log, LogOwl, Logger, Raygun, Sentry, User};
use utopia_test_wiremock::{method, path, query_param, Mock, MockServer, ResponseTemplate};

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .expect("tokio runtime")
}

fn sample_log() -> Log {
    let ts = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .expect("time")
        .as_secs_f64();
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
    log.add_tag("sdk", "Flutter");
    log.add_extra("urgent", false);
    log
}

fn mount_post(rt: &tokio::runtime::Runtime, server: &MockServer, status: u16, path_suffix: &str) {
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path(path_suffix))
            .respond_with(ResponseTemplate::new(status))
            .mount(server)
            .await;
    });
}

#[test]
fn e2e_sentry() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_post(&rt, &server, 200, "/api/proj/store/");
    let adapter = Sentry::new_with("proj", "key", server.uri(), 5, 1);
    let status = Logger::new(adapter).add_log(&sample_log()).unwrap();
    assert_eq!(status, 200);
}

#[test]
fn e2e_sentry_invalid_credentials() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_post(&rt, &server, 401, "/api/proj/store/");
    let adapter = Sentry::new_with("proj", "bad-key", server.uri(), 5, 1);
    let status = Logger::new(adapter).add_log(&sample_log()).unwrap();
    assert!(status > 400);
}

#[test]
fn e2e_raygun() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_post(&rt, &server, 202, "/entries");
    let adapter = Raygun::new("key").with_host(server.uri());
    let status = Logger::new(adapter).add_log(&sample_log()).unwrap();
    assert_eq!(status, 202);
}

#[test]
fn e2e_appsignal() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    rt.block_on(async {
        Mock::given(method("POST"))
            .and(path("/collect"))
            .and(query_param("api_key", "key"))
            .respond_with(ResponseTemplate::new(204))
            .mount(&server)
            .await;
    });
    let adapter = AppSignal::new("key").with_host(server.uri());
    let status = Logger::new(adapter).add_log(&sample_log()).unwrap();
    assert_eq!(status, 204);
}

#[test]
fn e2e_logowl() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_post(&rt, &server, 200, "/logging/error");
    let host = format!("{}/logging/", server.uri());
    let adapter = LogOwl::new_with("ticket", host, 5, 1);
    let status = Logger::new(adapter).add_log(&sample_log()).unwrap();
    assert_eq!(status, 200);
}

#[test]
fn e2e_logowl_invalid_host() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount_post(&rt, &server, 502, "/logging/error");
    let host = format!("{}/logging/", server.uri());
    let adapter = LogOwl::new_with("abc", host, 5, 1);
    let status = Logger::new(adapter).add_log(&sample_log()).unwrap();
    assert!(status > 400);
}

#[test]
fn adapters_are_named_for_providers() {
    assert!(Logger::has_provider(Sentry::get_name()));
    assert!(Logger::has_provider(Raygun::get_name()));
    assert!(Logger::has_provider(AppSignal::get_name()));
    assert!(Logger::has_provider(LogOwl::get_name()));
}
