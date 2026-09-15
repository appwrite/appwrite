//! Port of `tests/Unit/ConnectionTlsAndAuthTest.php`.

mod common;

use common::connect_fake;
use serde_json::json;
use std::collections::HashMap;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use utopia_nats::connection::ConnectionOptions;
use utopia_nats::transport::{FakeTransport, TlsTransport};

#[test]
fn test_tls_option_defaults() {
    let options = ConnectionOptions::default();
    assert!(options.tls_verify);
    assert!(options.tls_server_name.is_none());
}

#[test]
fn test_connection_tls_options_mapping() {
    let fake = FakeTransport::new(json!({}));
    let conn = connect_fake(fake, |opts| {
        opts.tls_verify = false;
        opts.tls_server_name = Some("example.com".into());
    });
    let tls = conn.tls_options();
    assert_eq!(tls["verify_peer"], false);
    assert_eq!(tls["verify_peer_name"], false);
    assert_eq!(tls["peer_name"], "example.com");
    conn.close();
}

#[test]
fn test_tls_transport_applies_verify_and_sni() {
    let mut options = HashMap::new();
    options.insert("verify_peer".into(), json!(false));
    options.insert("verify_peer_name".into(), json!(false));
    options.insert("peer_name".into(), json!("example.com"));
    let transport = TlsTransport::new(options);
    let ssl = transport.build_ssl_options();
    assert_eq!(ssl["verify_peer"], false);
    assert_eq!(ssl["verify_peer_name"], false);
    assert_eq!(ssl["peer_name"], "example.com");
}

#[test]
fn test_token_provider_resolved_at_connect() {
    let calls = Arc::new(AtomicUsize::new(0));
    let calls2 = Arc::clone(&calls);
    let fake = FakeTransport::new(json!({}));
    let conn = connect_fake(fake.clone(), |opts| {
        opts.token_provider = Some(Arc::new(move || {
            let n = calls2.fetch_add(1, Ordering::SeqCst) + 1;
            format!("token-{n}")
        }));
    });
    assert_eq!(
        calls.load(Ordering::SeqCst),
        1,
        "token provider invoked once at connect"
    );
    assert_eq!(fake.connect_payload().unwrap()["auth_token"], "token-1");
    conn.close();
}

#[test]
fn test_jwt_provider_resolved_at_connect() {
    let fake = FakeTransport::new(json!({}));
    let conn = connect_fake(fake.clone(), |opts| {
        opts.jwt_provider = Some(Arc::new(|| "my.jwt.token".into()));
    });
    assert_eq!(fake.connect_payload().unwrap()["jwt"], "my.jwt.token");
    conn.close();
}
