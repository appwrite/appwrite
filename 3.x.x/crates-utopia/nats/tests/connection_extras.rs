//! Port of `tests/Unit/ConnectionExtrasTest.php`.

mod common;

use common::connect_fake;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use utopia_nats::error::NatsError;
use utopia_nats::transport::FakeTransport;
use utopia_nats::Connection;

#[test]
fn test_authorization_violation_maps_to_authentication_exception() {
    assert!(matches!(
        Connection::map_server_error("Authorization Violation"),
        NatsError::Authentication(_)
    ));
}

#[test]
fn test_user_authentication_expired_maps_to_authentication_exception() {
    assert!(matches!(
        Connection::map_server_error("User Authentication Expired"),
        NatsError::Authentication(_)
    ));
}

#[test]
fn test_maximum_payload_maps_to_max_payload_exception() {
    assert!(matches!(
        Connection::map_server_error("Maximum Payload Exceeded"),
        NatsError::MaxPayload(_)
    ));
}

#[test]
fn test_permissions_violation_for_subscription_maps_to_permission_exception() {
    assert!(matches!(
        Connection::map_server_error("Permissions Violation for Subscription to 'foo.bar'"),
        NatsError::Permission(_)
    ));
}

#[test]
fn test_permissions_violation_for_publish_maps_to_permission_exception() {
    assert!(matches!(
        Connection::map_server_error("Permissions Violation for Publish to 'foo.bar'"),
        NatsError::Permission(_)
    ));
}

#[test]
fn test_unknown_error_maps_to_protocol_exception() {
    assert!(matches!(
        Connection::map_server_error("some unexpected error"),
        NatsError::Protocol(_)
    ));
}

#[test]
fn test_lame_duck_info_invokes_callback() {
    let fired = Arc::new(AtomicBool::new(false));
    let fired2 = Arc::clone(&fired);
    let fake = FakeTransport::new(serde_json::json!({}));
    let conn = connect_fake(fake.clone(), |opts| {
        opts.on_lame_duck = Some(Arc::new(move || {
            fired2.store(true, Ordering::SeqCst);
        }));
    });
    fake.push_inbound("INFO {\"server_id\":\"FAKE\",\"ldm\":true}\r\n");
    conn.process_message(Some(1.0)).unwrap();
    assert!(fired.load(Ordering::SeqCst));
    assert!(!conn.is_reconnecting());
    conn.close();
}

#[test]
fn test_non_lame_duck_info_does_not_invoke_callback() {
    let fired = Arc::new(AtomicBool::new(false));
    let fired2 = Arc::clone(&fired);
    let fake = FakeTransport::new(serde_json::json!({}));
    let conn = connect_fake(fake.clone(), |opts| {
        opts.on_lame_duck = Some(Arc::new(move || {
            fired2.store(true, Ordering::SeqCst);
        }));
    });
    fake.push_inbound("INFO {\"server_id\":\"FAKE\",\"ldm\":false}\r\n");
    conn.process_message(Some(1.0)).unwrap();
    assert!(!fired.load(Ordering::SeqCst));
    conn.close();
}
