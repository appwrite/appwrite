use std::collections::HashMap;
use std::time::Duration;

use utopia_telemetry::{
    HttpTransport, TelemetryError, Transport, CONTENT_TYPE_JSON, CONTENT_TYPE_PROTOBUF,
};
use utopia_test_wiremock::{header, method, path, Mock, MockServer, ResponseTemplate};

fn runtime() -> tokio::runtime::Runtime {
    tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .expect("tokio runtime")
}

fn mount(rt: &tokio::runtime::Runtime, server: &MockServer, mock: Mock) {
    rt.block_on(async {
        mock.mount(server).await;
    });
}

#[test]
fn constructor_parses_endpoint() {
    let transport =
        HttpTransport::new("https://otel.example.com:4318/v1/metrics?foo=bar").expect("url");
    assert_eq!(transport.content_type(), CONTENT_TYPE_PROTOBUF);
}

#[test]
fn constructor_with_http_endpoint() {
    let transport = HttpTransport::new("http://localhost:4318/v1/metrics").expect("url");
    assert_eq!(transport.content_type(), CONTENT_TYPE_PROTOBUF);
}

#[test]
fn constructor_with_custom_content_type() {
    let transport = HttpTransport::new_with(
        "http://localhost:4318/v1/metrics",
        CONTENT_TYPE_JSON,
        HashMap::new(),
        10.0,
        8,
        64 * 1024,
    )
    .expect("url");
    assert_eq!(transport.content_type(), CONTENT_TYPE_JSON);
}

#[test]
fn constructor_with_custom_headers_timeout_pool() {
    let mut headers = HashMap::new();
    headers.insert("Authorization".to_string(), "Bearer token123".to_string());
    let transport = HttpTransport::new_with(
        "http://localhost:4318/v1/metrics",
        CONTENT_TYPE_PROTOBUF,
        headers,
        5.0,
        16,
        128 * 1024,
    )
    .expect("url");
    assert_eq!(transport.content_type(), CONTENT_TYPE_PROTOBUF);
}

#[test]
fn endpoint_with_query_string() {
    let transport = HttpTransport::new("http://localhost:4318/v1/metrics?api_key=secret&env=test")
        .expect("url");
    assert_eq!(transport.content_type(), CONTENT_TYPE_PROTOBUF);
}

#[test]
fn malformed_url_throws() {
    let err = HttpTransport::new("http:///v1/metrics").expect_err("invalid");
    assert_eq!(err.to_string(), "Invalid endpoint URL: http:///v1/metrics");
}

#[test]
fn default_ports() {
    assert!(HttpTransport::new("http://example.com/v1/metrics").is_ok());
    assert!(HttpTransport::new("https://example.com/v1/metrics").is_ok());
}

#[test]
fn shutdown_and_force_flush() {
    let transport = HttpTransport::new("http://localhost:4318/v1/metrics").expect("url");
    assert!(transport.force_flush());
    assert!(transport.shutdown());
    assert!(transport.shutdown());
}

#[test]
fn send_after_shutdown_returns_error() {
    let transport = HttpTransport::new("http://localhost:4318/v1/metrics").expect("url");
    transport.shutdown();
    let err = transport.send(b"test payload").expect_err("shutdown");
    assert_eq!(err, TelemetryError::TransportShutdown);
    assert_eq!(err.to_string(), "Transport has been shut down");
}

#[test]
fn send_payload_to_server() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(
        &rt,
        &server,
        Mock::given(method("POST"))
            .and(path("/v1/metrics"))
            .and(header("content-type", CONTENT_TYPE_PROTOBUF))
            .respond_with(ResponseTemplate::new(200).set_body_string("OK")),
    );

    let endpoint = format!("{}/v1/metrics", server.uri());
    let transport = HttpTransport::new(&endpoint).expect("url");
    let result = transport.send(b"test-metric-payload-data").expect("send");
    assert_eq!(result, b"OK");
    transport.shutdown();
}

#[test]
fn send_with_custom_headers() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(
        &rt,
        &server,
        Mock::given(method("POST"))
            .and(header("authorization", "Bearer test-token"))
            .and(header("x-custom-header", "custom-value"))
            .respond_with(ResponseTemplate::new(200).set_body_string("OK")),
    );

    let mut headers = HashMap::new();
    headers.insert("Authorization".to_string(), "Bearer test-token".to_string());
    headers.insert("X-Custom-Header".to_string(), "custom-value".to_string());
    let endpoint = format!("{}/v1/metrics", server.uri());
    let transport = HttpTransport::new_with(
        &endpoint,
        CONTENT_TYPE_PROTOBUF,
        headers,
        10.0,
        8,
        64 * 1024,
    )
    .expect("url");
    transport.send(b"payload").expect("send");
    transport.shutdown();
}

#[test]
fn send_handles_server_error() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(
        &rt,
        &server,
        Mock::given(method("POST"))
            .respond_with(ResponseTemplate::new(500).set_body_string("Internal Server Error")),
    );

    let endpoint = format!("{}/v1/metrics", server.uri());
    let transport = HttpTransport::new(&endpoint).expect("url");
    let err = transport.send(b"payload").expect_err("500");
    match err {
        TelemetryError::ExportFailed { status, body } => {
            assert_eq!(status, "500");
            assert!(body.contains("Internal Server Error"));
        }
        other => panic!("unexpected {other:?}"),
    }
    transport.shutdown();
}

#[test]
fn json_content_type() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(
        &rt,
        &server,
        Mock::given(method("POST"))
            .and(header("content-type", CONTENT_TYPE_JSON))
            .respond_with(ResponseTemplate::new(200)),
    );

    let endpoint = format!("{}/v1/metrics", server.uri());
    let transport = HttpTransport::new_with(
        &endpoint,
        CONTENT_TYPE_JSON,
        HashMap::new(),
        10.0,
        8,
        64 * 1024,
    )
    .expect("url");
    transport.send(b"{\"metrics\":[]}").expect("send");
    transport.shutdown();
}

#[test]
fn connection_timeout() {
    let transport = HttpTransport::new_with(
        "http://127.0.0.1:19999/v1/metrics",
        CONTENT_TYPE_PROTOBUF,
        HashMap::new(),
        0.5,
        8,
        64 * 1024,
    )
    .expect("url");
    let start = std::time::Instant::now();
    let err = transport.send(b"payload").expect_err("timeout");
    assert!(start.elapsed() < Duration::from_secs(2));
    assert!(matches!(err, TelemetryError::ConnectionFailed { .. }));
    transport.shutdown();
}

#[test]
fn multiple_sequential_sends() {
    let rt = runtime();
    let server = rt.block_on(MockServer::start());
    mount(
        &rt,
        &server,
        Mock::given(method("POST")).respond_with(ResponseTemplate::new(200).set_body_string("OK")),
    );

    let endpoint = format!("{}/v1/metrics", server.uri());
    let transport = HttpTransport::new_with(
        &endpoint,
        CONTENT_TYPE_PROTOBUF,
        HashMap::new(),
        10.0,
        2,
        64 * 1024,
    )
    .expect("url");
    for i in 0..10 {
        transport
            .send(format!("payload-{i}").as_bytes())
            .expect("send");
    }
    assert_eq!(
        rt.block_on(server.received_requests())
            .expect("recorded requests")
            .len(),
        10
    );
    transport.shutdown();
}
