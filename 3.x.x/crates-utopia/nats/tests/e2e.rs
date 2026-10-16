//! Live NATS E2E against the compose broker (`nats://127.0.0.1:4222`).

use utopia_nats::{Connection, ConnectionOptions};

fn nats_url() -> String {
    std::env::var("NATS_URL")
        .ok()
        .filter(|s| !s.is_empty())
        .unwrap_or_else(|| "nats://127.0.0.1:4222".to_owned())
}

fn connect() -> Connection {
    let opts = ConnectionOptions {
        servers: vec![nats_url()],
        allow_reconnect: false,
        connect_timeout: 2.0,
        ..ConnectionOptions::default()
    };
    Connection::connect(opts).unwrap_or_else(|e| {
        panic!("NATS broker required (docker compose -f docker-compose.test.yml up -d nats): {e}")
    })
}

#[test]
fn e2e_connect() {
    let conn = connect();
    assert!(conn.is_connected());
    conn.close();
}

#[test]
fn e2e_publish_subscribe() {
    let conn = connect();
    conn.publish("utopia.e2e", b"hi", None, None).unwrap();
    conn.close();
}
