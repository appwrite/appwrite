//! Ports `tests/Unit/ParseEndpointTest.php`.

use utopia_proxy::adapter::Adapter;

#[test]
fn with_port() {
    let (host, port) = Adapter::parse_endpoint("example.com:8080", 80);
    assert_eq!(host, "example.com");
    assert_eq!(port, 8080);
}

#[test]
fn without_port() {
    let (host, port) = Adapter::parse_endpoint("example.com", 3306);
    assert_eq!(host, "example.com");
    assert_eq!(port, 3306);
}

#[test]
fn with_empty_port() {
    let (host, port) = Adapter::parse_endpoint("example.com:", 5432);
    assert_eq!(host, "example.com");
    assert_eq!(port, 5432);
}

#[test]
fn with_ip_and_port() {
    let (host, port) = Adapter::parse_endpoint("10.0.0.1:9200", 80);
    assert_eq!(host, "10.0.0.1");
    assert_eq!(port, 9200);
}

#[test]
fn with_ip_without_port() {
    let (host, port) = Adapter::parse_endpoint("10.0.0.1", 27017);
    assert_eq!(host, "10.0.0.1");
    assert_eq!(port, 27017);
}

#[test]
fn default_port_zero() {
    let (host, port) = Adapter::parse_endpoint("host.local", 0);
    assert_eq!(host, "host.local");
    assert_eq!(port, 0);
}

#[test]
fn port_one_overrides_default() {
    let (host, port) = Adapter::parse_endpoint("host.local:1", 8080);
    assert_eq!(host, "host.local");
    assert_eq!(port, 1);
}

#[test]
fn port_zero_explicit() {
    let (host, port) = Adapter::parse_endpoint("host.local:0", 8080);
    assert_eq!(host, "host.local");
    assert_eq!(port, 0);
}

#[test]
fn port_65535() {
    let (host, port) = Adapter::parse_endpoint("host.local:65535", 80);
    assert_eq!(host, "host.local");
    assert_eq!(port, 65535);
}

#[test]
fn large_default_port() {
    let (host, port) = Adapter::parse_endpoint("backend", 50051);
    assert_eq!(host, "backend");
    assert_eq!(port, 50051);
}

#[test]
fn localhost_with_port() {
    let (host, port) = Adapter::parse_endpoint("localhost:3000", 80);
    assert_eq!(host, "localhost");
    assert_eq!(port, 3000);
}

#[test]
fn non_numeric_port_falls_back_to_default() {
    // PHP casts "abc" to int 0. Rust's parse_endpoint returns the default.
    let (host, port) = Adapter::parse_endpoint("host:abc", 80);
    assert_eq!(host, "host");
    assert_eq!(port, 80);
}
