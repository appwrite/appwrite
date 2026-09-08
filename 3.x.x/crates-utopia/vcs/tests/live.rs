//! Provider initialize tests against the WireMock container.
//!
//! Gated live credentials are not required; HTTP is stubbed locally.

use utopia_test_wiremock::{method, path, Mock, MockServer, ResponseTemplate};
use utopia_vcs::adapter::git::{Bitbucket, GitHub, GitLab};
use utopia_vcs::cache::MemoryCache;

/// Disposable GitHub App RSA key used only in tests.
const TEST_GITHUB_APP_PEM: &str = include_str!("github-app-test.pem");

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .expect("tokio runtime")
}

#[test]
fn live_github_initialize() {
    runtime().block_on(async {
        let server = MockServer::start().await;
        Mock::given(method("POST"))
            .and(path("/app/installations/42/access_tokens"))
            .respond_with(ResponseTemplate::new(201).set_body_json(serde_json::json!({
                "token": "ghs_test_token",
            })))
            .mount(&server)
            .await;
        Mock::given(method("GET"))
            .and(path("/app/installations/42"))
            .respond_with(ResponseTemplate::new(200).set_body_json(serde_json::json!({
                "account": { "login": "octocat" }
            })))
            .mount(&server)
            .await;

        let uri = server.uri();
        tokio::task::spawn_blocking(move || {
            let mut adapter = GitHub::new(MemoryCache::new());
            adapter.set_endpoint(uri);
            adapter
                .initialize_variables("42", TEST_GITHUB_APP_PEM, Some("1"), None, None)
                .expect("initialize GitHub App");
            let owner = adapter.get_owner_name("42", None).expect("owner");
            assert_eq!(owner, "octocat");
        })
        .await
        .unwrap();
    });
}

#[test]
fn live_bitbucket_initialize() {
    runtime().block_on(async {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/user/workspaces"))
            .respond_with(ResponseTemplate::new(200).set_body_json(serde_json::json!({
                "values": [{ "workspace": { "slug": "bb-workspace" } }]
            })))
            .mount(&server)
            .await;

        let uri = server.uri();
        tokio::task::spawn_blocking(move || {
            let mut adapter = Bitbucket::new(MemoryCache::new());
            adapter
                .initialize_variables("", "", None, Some("bb-token"), None)
                .expect("initialize Bitbucket");
            adapter.set_endpoint(uri);
            let owner = adapter.get_owner_name("", None).expect("owner");
            assert_eq!(owner, "bb-workspace");
        })
        .await
        .unwrap();
    });
}

#[test]
fn live_gitlab_initialize() {
    let mut adapter = GitLab::new(MemoryCache::new());
    adapter
        .initialize_variables("", "", None, Some("gl-token"), None)
        .expect("initialize GitLab");
    adapter.set_endpoint("http://127.0.0.1:8089");
    assert_eq!(adapter.get_name(), "gitlab");
}
