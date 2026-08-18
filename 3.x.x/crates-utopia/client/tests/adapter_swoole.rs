//! PHP Swoole coroutine adapter tests (Tokio backend).

mod support;

use support::request;
use utopia_client::adapter::swoole_coroutine::{Client as SwooleClient, SwooleSettings};
use utopia_client::{ErrorKind, StreamingClient};

#[test]
fn it_requires_coroutine_context() {
    let client = SwooleClient::new();
    let error = client
        .send_request(request("GET", "https://example.com"))
        .unwrap_err();
    assert_eq!(error.kind(), ErrorKind::AdapterPrecondition);
    assert!(error.to_string().contains("must run inside a coroutine"));
}

#[test]
fn it_rejects_invalid_settings_inside_runtime() {
    let runtime = tokio::runtime::Builder::new_multi_thread()
        .enable_all()
        .build()
        .unwrap();
    runtime.block_on(async {
        let client = SwooleClient::with_settings(SwooleSettings::invalid_timeout());
        let error = client
            .send_request(request("GET", "http://127.0.0.1:1/binary"))
            .unwrap_err();
        assert!(error.to_string().contains("must be a finite number"));
    });
}

#[tokio::test(flavor = "multi_thread")]
async fn it_sends_inside_tokio_runtime() {
    use support::http_server::TestServer;
    let server = TestServer::serve();
    let client = SwooleClient::new();
    let response = client
        .send_request(request("GET", &server.url("/binary")))
        .unwrap();
    assert_eq!(response.status(), 200);
    assert_eq!(response.body().as_ref(), b"\x00\x01hello\xff");
}
