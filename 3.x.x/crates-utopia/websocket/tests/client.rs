//! Port of `tests/unit/ClientTest.php` plus extra error-path coverage.

use std::collections::HashMap;

use utopia_websocket::{Client, WebsocketError};

/// `ClientTest::testConstructorWithValidUrl`
#[test]
fn test_constructor_with_valid_url() {
    let client = Client::from_url("ws://localhost:8080").unwrap();
    assert!(!client.is_connected());
}

/// `ClientTest::testConstructorWithInvalidUrl`
#[test]
fn test_constructor_with_invalid_url() {
    let err = Client::from_url("invalid-url").unwrap_err();
    assert!(matches!(
        err,
        WebsocketError::MissingHost | WebsocketError::InvalidUrl
    ));
}

/// `ClientTest::testConstructorWithCustomOptions`
#[test]
fn test_constructor_with_custom_options() {
    let mut headers = HashMap::new();
    headers.insert("Authorization".to_string(), "Bearer token".to_string());
    let client = Client::new("ws://localhost:8080", headers, 60.0).unwrap();
    assert!(!client.is_connected());
}

/// `ClientTest::testEventHandlers`
#[test]
fn test_event_handlers() {
    let mut client = Client::from_url("ws://localhost:8080").unwrap();
    client
        .on_message(|_| {})
        .on_close(|| {})
        .on_error(|_| {})
        .on_open(|| {})
        .on_ping(|_| {})
        .on_pong(|_| {});
    assert!(!client.is_connected());
}

/// `ClientTest::testIsConnected`
#[test]
fn test_is_connected() {
    let client = Client::from_url("ws://localhost:8080").unwrap();
    assert!(!client.is_connected());
}

/// `ClientTest::testSendWithoutConnection`
#[test]
fn test_send_without_connection() {
    let mut client = Client::from_url("ws://localhost:8080").unwrap();
    let err = client.send("test message").unwrap_err();
    assert_eq!(err.to_string(), "Not connected to WebSocket server");
}

/// `ClientTest::testReceiveWithoutConnection`
#[test]
fn test_receive_without_connection() {
    let mut client = Client::from_url("ws://localhost:8080").unwrap();
    let err = client.receive().unwrap_err();
    assert_eq!(err.to_string(), "Not connected to WebSocket server");
}

/// Extra: URL with path and query.
#[test]
fn test_constructor_with_path_and_query() {
    let client = Client::from_url("ws://example.com:9001/chat?room=1").unwrap();
    assert!(!client.is_connected());
}
