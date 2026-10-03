//! Ports `tests/Unit/ConnectionResultTest.php` + `ConnectionResultExtendedTest.php`.

use std::collections::HashMap;

use utopia_proxy::{ConnectionResult, Protocol};

#[test]
fn connection_result_stores_values() {
    let mut metadata = HashMap::new();
    metadata.insert("cached".to_string(), "false".to_string());

    let result = ConnectionResult::new("127.0.0.1:8080", Protocol::Http, metadata.clone());

    assert_eq!(result.endpoint(), "127.0.0.1:8080");
    assert_eq!(result.protocol(), Protocol::Http);
    assert_eq!(result.metadata(), &metadata);
}

#[test]
fn all_protocol_types() {
    let protocols = [
        Protocol::Http,
        Protocol::Smtp,
        Protocol::Tcp,
        Protocol::PostgreSQL,
        Protocol::MySQL,
        Protocol::MongoDB,
    ];

    for protocol in protocols {
        let result = ConnectionResult::new("127.0.0.1:8080", protocol, HashMap::new());
        assert_eq!(result.protocol(), protocol);
    }
}

#[test]
fn default_empty_metadata() {
    let result = ConnectionResult::new("127.0.0.1:8080", Protocol::Http, HashMap::new());
    assert!(result.metadata().is_empty());
}

#[test]
fn metadata_with_multiple_entries() {
    let mut metadata = HashMap::new();
    metadata.insert("cached".to_string(), "true".to_string());
    metadata.insert("latency".to_string(), "1.5".to_string());
    metadata.insert("count".to_string(), "42".to_string());

    let result = ConnectionResult::new("127.0.0.1:8080", Protocol::Http, metadata);

    assert_eq!(result.metadata().get("cached").unwrap(), "true");
    assert_eq!(result.metadata().get("latency").unwrap(), "1.5");
    assert_eq!(result.metadata().get("count").unwrap(), "42");
}

#[test]
fn endpoint_with_host_only() {
    let result = ConnectionResult::new("db.example.com", Protocol::PostgreSQL, HashMap::new());
    assert_eq!(result.endpoint(), "db.example.com");
}

#[test]
fn endpoint_with_host_and_port() {
    let result = ConnectionResult::new("db.example.com:5432", Protocol::PostgreSQL, HashMap::new());
    assert_eq!(result.endpoint(), "db.example.com:5432");
}

#[test]
fn endpoint_with_ip_address() {
    let result = ConnectionResult::new("192.168.1.100:3306", Protocol::MySQL, HashMap::new());
    assert_eq!(result.endpoint(), "192.168.1.100:3306");
}
