//! Wiremock HTTP call-shape tests (PHP `Adapter::call` / GitHub `getUser`).

use serde_json::json;
use utopia_test_wiremock::{method, path, Mock, MockServer, ResponseTemplate};
use utopia_vcs::adapter::git::GitHub;
use utopia_vcs::cache::MemoryCache;
use utopia_vcs::php::USER_AGENT;

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .expect("tokio runtime")
}

#[test]
fn github_get_user_path_headers_and_full_response() {
    runtime().block_on(async {
        let server = MockServer::start().await;
        Mock::given(method("GET"))
            .and(path("/users/octocat"))
            .respond_with(
                ResponseTemplate::new(200)
                    .insert_header("content-type", "application/json")
                    .set_body_json(json!({
                        "login": "octocat",
                        "id": 1,
                    })),
            )
            .mount(&server)
            .await;

        let uri = server.uri();
        let response = tokio::task::spawn_blocking(move || {
            let mut github = GitHub::new(MemoryCache::new());
            github.set_endpoint(uri);
            github.get_user("octocat")
        })
        .await
        .expect("join")
        .expect("get_user");

        let requests = server.received_requests().await.expect("received");
        assert_eq!(requests.len(), 1);
        let sent = &requests[0];
        assert_eq!(sent.url.path(), "/users/octocat");
        let ua = sent
            .headers
            .get("user-agent")
            .and_then(|v| v.to_str().ok())
            .unwrap_or_default();
        assert_eq!(ua, USER_AGENT);
        let content_type = sent
            .headers
            .get("content-type")
            .and_then(|v| v.to_str().ok())
            .unwrap_or_default();
        assert!(
            content_type.starts_with("application/json"),
            "content-type={content_type}"
        );

        assert!(response.get("headers").is_some(), "{response}");
        assert_eq!(response["body"]["login"], json!("octocat"), "{response}");
        assert_eq!(response["headers"]["status-code"], json!(200));
    });
}
