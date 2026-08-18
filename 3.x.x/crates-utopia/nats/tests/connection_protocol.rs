//! Port of `tests/Unit/ConnectionProtocolTest.php`.

mod common;

use common::connect_fake;
use serde_json::json;
use utopia_nats::error::NatsError;
use utopia_nats::transport::FakeTransport;
use utopia_nats::Headers;

#[test]
fn test_connect_negotiates_headers_when_supported() {
    let fake = FakeTransport::new(json!({"headers": true}));
    let conn = connect_fake(fake.clone(), |_| {});
    let payload = fake.connect_payload().unwrap();
    assert_eq!(payload["headers"], true);
    assert_eq!(payload["no_responders"], true);
    conn.close();
}

#[test]
fn test_connect_disables_headers_when_not_supported() {
    let fake = FakeTransport::new(json!({"headers": false}));
    let conn = connect_fake(fake.clone(), |_| {});
    let payload = fake.connect_payload().unwrap();
    assert_eq!(payload["headers"], false);
    assert_eq!(payload["no_responders"], false);
    conn.close();
}

#[test]
fn test_publish_with_headers_uses_hpub() {
    let fake = FakeTransport::new(json!({"headers": true}));
    let conn = connect_fake(fake.clone(), |_| {});
    let mut headers = Headers::new();
    headers.set("X-Key", "value");
    conn.publish("subj", b"hello", None, Some(&headers))
        .unwrap();
    assert!(fake.written().contains("HPUB subj"));
    conn.close();
}

#[test]
fn test_publish_with_headers_rejected_when_server_lacks_support() {
    let fake = FakeTransport::new(json!({"headers": false}));
    let conn = connect_fake(fake.clone(), |_| {});
    let mut headers = Headers::new();
    headers.set("X-Key", "value");
    let err = conn
        .publish("subj", b"hello", None, Some(&headers))
        .unwrap_err();
    conn.close();
    assert!(matches!(err, NatsError::Protocol(_)));
}

#[test]
fn test_max_payload_includes_header_bytes() {
    let fake = FakeTransport::new(json!({"headers": true, "max_payload": 40}));
    let conn = connect_fake(fake.clone(), |_| {});
    let mut headers = Headers::new();
    headers.set("X-Long-Header", "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");
    assert!(headers.to_wire().len() + 5 > 40);
    let err = conn
        .publish("subj", b"hello", None, Some(&headers))
        .unwrap_err();
    conn.close();
    assert!(matches!(err, NatsError::MaxPayload(_)));
}

#[test]
fn test_tls_available_is_parsed() {
    let fake = FakeTransport::new(json!({"tls_available": true, "tls_required": false}));
    let conn = connect_fake(fake, |_| {});
    assert!(conn.get_server_info().tls_available);
    assert!(!conn.get_server_info().tls_required);
    conn.close();
}
