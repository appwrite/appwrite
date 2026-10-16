//! PHP `tests/ClientTest.php`.

mod support;

use std::sync::{Arc, Mutex};

use support::{request, RecordingAdapter};
use utopia_client::{Client, HeaderValues, StreamingClient, Tls};
use utopia_span::{Memory, Span};

static SPAN_LOCK: Mutex<()> = Mutex::new(());

fn span_guard() -> std::sync::MutexGuard<'static, ()> {
    SPAN_LOCK
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner)
}

#[test]
fn it_decorates_configurable_adapters() {
    let req = request("GET", "https://example.com");
    let client = Client::new(RecordingAdapter::new());
    let configured = client
        .with_timeout(5.5)
        .unwrap()
        .with_connect_timeout(1.25)
        .unwrap();
    let response = configured.send_request(req.clone()).unwrap();
    assert_eq!(
        client
            .send_request(req)
            .unwrap()
            .headers()
            .get("x-timeout")
            .map(|v| v.as_bytes()),
        None
    );
    assert_eq!(
        response.headers().get("x-timeout").unwrap().as_bytes(),
        b"5.5"
    );
    assert_eq!(
        response
            .headers()
            .get("x-connect-timeout")
            .unwrap()
            .as_bytes(),
        b"1.25"
    );
}

#[test]
fn it_decorates_tls_configuration() {
    let req = request("GET", "https://example.com");
    let client = Client::new(RecordingAdapter::new());
    let configured = client
        .with_ssl_verification(false)
        .with_custom_ca("/etc/ssl/ca.pem")
        .with_certificate(
            "/etc/ssl/client.pem",
            "/etc/ssl/client.key",
            Some("secret".into()),
        )
        .with_min_tls_version(Tls::V1_2);
    let response = configured.send_request(req.clone()).unwrap();
    assert!(client
        .send_request(req)
        .unwrap()
        .headers()
        .get("x-tls-verify")
        .is_none());
    assert_eq!(
        response.headers().get("x-tls-verify").unwrap().as_bytes(),
        b"off"
    );
    assert_eq!(
        response.headers().get("x-tls-ca").unwrap().as_bytes(),
        b"/etc/ssl/ca.pem"
    );
    assert_eq!(
        response.headers().get("x-tls-cert").unwrap().as_bytes(),
        b"/etc/ssl/client.pem:/etc/ssl/client.key:secret"
    );
    assert_eq!(
        response
            .headers()
            .get("x-tls-min-version")
            .unwrap()
            .as_bytes(),
        b"V1_2"
    );
}

#[test]
fn it_decorates_connection_reuse() {
    let req = request("GET", "https://example.com");
    let client = Client::new(RecordingAdapter::new());
    let configured = client.with_connection_reuse(true);
    assert!(client
        .send_request(req.clone())
        .unwrap()
        .headers()
        .get("x-connection-reuse")
        .is_none());
    assert_eq!(
        configured
            .send_request(req.clone())
            .unwrap()
            .headers()
            .get("x-connection-reuse")
            .unwrap()
            .as_bytes(),
        b"on"
    );
    assert_eq!(
        client
            .with_connection_reuse(false)
            .send_request(req)
            .unwrap()
            .headers()
            .get("x-connection-reuse")
            .unwrap()
            .as_bytes(),
        b"off"
    );
}

#[test]
fn it_rejects_invalid_timeouts() {
    let client = Client::new(RecordingAdapter::new());
    let error = client.with_timeout(-1.0).unwrap_err();
    assert!(error.is_value_error());
}

#[test]
fn it_applies_default_headers_immutably_without_overriding_request_headers() {
    let client = Client::new(RecordingAdapter::new());
    let configured = client.with_headers([
        ("Accept", HeaderValues::from("application/json")),
        ("X-Trace", HeaderValues::from(vec!["one", "two"])),
    ]);
    let plain = client
        .send_request(request("GET", "https://example.com"))
        .unwrap();
    let mut req = request("GET", "https://example.com");
    req.headers_mut()
        .insert("accept", "application/xml".parse().unwrap());
    let response = configured.send_request(req).unwrap();
    assert!(plain.headers().get("x-request-accept").is_none());
    assert_eq!(
        response
            .headers()
            .get("x-request-accept")
            .unwrap()
            .as_bytes(),
        b"application/xml"
    );
    assert_eq!(
        response
            .headers()
            .get("x-request-trace")
            .unwrap()
            .as_bytes(),
        b"one, two"
    );
}

#[test]
fn it_applies_auth_defaults_without_overriding_request_authorization() {
    let client = Client::new(RecordingAdapter::new());
    let basic = client
        .with_basic_auth("ada", "secret")
        .send_request(request("GET", "https://example.com"))
        .unwrap();
    let bearer = client
        .with_bearer_auth("token")
        .send_request(request("GET", "https://example.com"))
        .unwrap();
    let mut override_req = request("GET", "https://example.com");
    override_req
        .headers_mut()
        .insert("authorization", "Digest custom".parse().unwrap());
    let override_res = client
        .with_bearer_auth("token")
        .send_request(override_req)
        .unwrap();
    assert_eq!(
        basic
            .headers()
            .get("x-request-authorization")
            .unwrap()
            .as_bytes(),
        b"Basic YWRhOnNlY3JldA=="
    );
    assert_eq!(
        bearer
            .headers()
            .get("x-request-authorization")
            .unwrap()
            .as_bytes(),
        b"Bearer token"
    );
    assert_eq!(
        override_res
            .headers()
            .get("x-request-authorization")
            .unwrap()
            .as_bytes(),
        b"Digest custom"
    );
}

#[test]
fn it_applies_base_uri_to_relative_requests() {
    let client = Client::new(RecordingAdapter::new())
        .with_base_uri("https://api.example.com/v1")
        .unwrap();
    let relative = client
        .send_request(request("GET", "users?active=1"))
        .unwrap();
    let absolute_path = client.send_request(request("GET", "/status")).unwrap();
    let absolute_uri = client
        .send_request(request("GET", "https://other.example.com/users"))
        .unwrap();
    assert_eq!(
        relative.headers().get("x-request-uri").unwrap().as_bytes(),
        b"https://api.example.com/v1/users?active=1"
    );
    assert_eq!(
        absolute_path
            .headers()
            .get("x-request-uri")
            .unwrap()
            .as_bytes(),
        b"https://api.example.com/status"
    );
    assert_eq!(
        absolute_uri
            .headers()
            .get("x-request-uri")
            .unwrap()
            .as_bytes(),
        b"https://other.example.com/users"
    );
}

#[test]
fn it_rejects_relative_base_uris() {
    let client = Client::new(RecordingAdapter::new());
    let error = client.with_base_uri("/api").unwrap_err();
    assert_eq!(error.to_string(), "Base URI must be absolute.");
}

#[test]
fn it_propagates_the_active_trace_without_overriding_an_inbound_one() {
    let _lock = span_guard();
    Span::set_storage(None);
    let client = Client::new(RecordingAdapter::new()).with_trace_propagation(true);
    Span::set_storage(Some(Arc::new(Memory::new())));
    let span = Span::init("http.request", None);
    let propagated = client
        .send_request(request("GET", "https://example.com"))
        .unwrap();
    let mut inbound = request("GET", "https://example.com");
    inbound
        .headers_mut()
        .insert("traceparent", "incoming".parse().unwrap());
    let forwarded = client.send_request(inbound).unwrap();
    assert_eq!(
        propagated
            .headers()
            .get("x-request-traceparent")
            .unwrap()
            .to_str()
            .unwrap(),
        span.get_traceparent()
    );
    assert_eq!(
        forwarded
            .headers()
            .get("x-request-traceparent")
            .unwrap()
            .as_bytes(),
        b"incoming"
    );
    span.finish();
    Span::set_storage(None);
}

#[test]
fn it_does_not_propagate_traces_by_default() {
    let _lock = span_guard();
    Span::set_storage(None);
    let client = Client::new(RecordingAdapter::new());
    Span::set_storage(Some(Arc::new(Memory::new())));
    let span = Span::init("http.request", None);
    let response = client
        .send_request(request("GET", "https://example.com"))
        .unwrap();
    assert!(response.headers().get("x-request-traceparent").is_none());
    span.finish();
    Span::set_storage(None);
}

#[test]
fn it_leaves_requests_untouched_without_an_active_span() {
    let _lock = span_guard();
    Span::set_storage(None);
    let client = Client::new(RecordingAdapter::new()).with_trace_propagation(true);
    let response = client
        .send_request(request("GET", "https://example.com"))
        .unwrap();
    assert!(response.headers().get("x-request-traceparent").is_none());
}

#[test]
fn it_streams_through_the_adapter_applying_base_uri_and_headers() {
    let client = Client::new(RecordingAdapter::new())
        .with_base_uri("https://api.example.com/v1")
        .unwrap()
        .with_headers([("Accept", "application/json")]);
    let mut received = Vec::new();
    let response = client
        .stream(request("GET", "users"), &mut |chunk| {
            received.extend_from_slice(chunk);
        })
        .unwrap();
    assert_eq!(received, b"chunk");
    assert_eq!(
        response.headers().get("x-request-uri").unwrap().as_bytes(),
        b"https://api.example.com/v1/users"
    );
    assert_eq!(
        response
            .headers()
            .get("x-request-accept")
            .unwrap()
            .as_bytes(),
        b"application/json"
    );
}
