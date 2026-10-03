//! Ports `tests/Unit/EndpointValidationTest.php`.

mod common;

use std::sync::Arc;

use common::MockResolver;
use utopia_proxy::adapter::Adapter;
use utopia_proxy::{Protocol, Resolver, ResolverError};

fn create_adapter(resolver: Arc<MockResolver>) -> Adapter {
    let resolver: Arc<dyn Resolver> = resolver;
    Adapter::new(Some(resolver), Protocol::Http)
}

#[tokio::test]
async fn rejects_endpoint_with_multiple_colons() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("host:port:extra");
    let adapter = create_adapter(resolver);

    let err = adapter.route(b"test").await.unwrap_err();
    assert!(matches!(err, ResolverError::Internal { .. }));
    assert!(err.to_string().contains("Invalid endpoint format"));
}

#[tokio::test]
async fn rejects_port_above_65535() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("example.com:70000");
    let adapter = create_adapter(resolver);

    let err = adapter.route(b"test").await.unwrap_err();
    assert!(err.to_string().contains("Invalid port number"));
}

#[tokio::test]
async fn rejects_port_way_above_limit() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("example.com:999999");
    let adapter = create_adapter(resolver);

    let err = adapter.route(b"test").await.unwrap_err();
    assert!(err.to_string().contains("Invalid port number"));
}

async fn assert_private_rejected(endpoint: &str) {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint(endpoint);
    let adapter = create_adapter(resolver);

    let err = adapter.route(b"test").await.unwrap_err();
    assert!(
        err.to_string().contains("private/reserved IP"),
        "expected SSRF block for {endpoint}, got {err}"
    );
}

#[tokio::test]
async fn rejects_10_network() {
    assert_private_rejected("10.0.0.1:80").await;
}

#[tokio::test]
async fn rejects_10_network_high_end() {
    assert_private_rejected("10.255.255.255:80").await;
}

#[tokio::test]
async fn rejects_172_network() {
    assert_private_rejected("172.16.0.1:80").await;
}

#[tokio::test]
async fn rejects_172_network_high_end() {
    assert_private_rejected("172.31.255.255:80").await;
}

#[tokio::test]
async fn rejects_192168_network() {
    assert_private_rejected("192.168.1.1:80").await;
}

#[tokio::test]
async fn rejects_loopback_ip() {
    assert_private_rejected("127.0.0.1:80").await;
}

#[tokio::test]
async fn rejects_loopback_high_end() {
    assert_private_rejected("127.255.255.255:80").await;
}

#[tokio::test]
async fn rejects_link_local() {
    assert_private_rejected("169.254.1.1:80").await;
}

#[tokio::test]
async fn rejects_multicast() {
    assert_private_rejected("224.0.0.1:80").await;
}

#[tokio::test]
async fn rejects_multicast_high_end() {
    assert_private_rejected("239.255.255.255:80").await;
}

#[tokio::test]
async fn rejects_reserved_range_240() {
    assert_private_rejected("240.0.0.1:80").await;
}

#[tokio::test]
async fn rejects_zero_network() {
    assert_private_rejected("0.0.0.0:80").await;
}

#[tokio::test]
async fn accepts_public_ip() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:80");
    let adapter = create_adapter(resolver);

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "8.8.8.8:80");
}

#[tokio::test]
async fn accepts_public_ip_without_port() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8");
    let adapter = create_adapter(resolver);

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "8.8.8.8");
}

#[tokio::test]
async fn skip_validation_allows_private_ips() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("10.0.0.1:80");
    let adapter = create_adapter(resolver);
    adapter.set_skip_validation(true).await;

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "10.0.0.1:80");
}

#[tokio::test]
async fn skip_validation_allows_loopback() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("127.0.0.1:80");
    let adapter = create_adapter(resolver);
    adapter.set_skip_validation(true).await;

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "127.0.0.1:80");
}

#[tokio::test]
async fn accepts_port_65535() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:65535");
    let adapter = create_adapter(resolver);

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "8.8.8.8:65535");
}

#[tokio::test]
async fn accepts_port_zero_implicit() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8");
    let adapter = create_adapter(resolver);

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "8.8.8.8");
}

#[tokio::test]
async fn ipv6_with_colons_rejected_as_invalid_format() {
    // Raw IPv6 contains colons which the endpoint parser treats as
    // host:port separator. Use skip_validation for IPv6.
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("::1");
    let adapter = create_adapter(resolver);

    let err = adapter.route(b"test").await.unwrap_err();
    assert!(err.to_string().contains("Invalid endpoint format"));
}

#[tokio::test]
async fn skip_validation_allows_ipv6_loopback() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("::1");
    let adapter = create_adapter(resolver);
    adapter.set_skip_validation(true).await;

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "::1");
}

#[tokio::test]
async fn skip_validation_allows_ipv6_link_local() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("fe80::1");
    let adapter = create_adapter(resolver);
    adapter.set_skip_validation(true).await;

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "fe80::1");
}

#[tokio::test]
async fn rejects_negative_port() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:-1");
    let adapter = create_adapter(resolver);

    let err = adapter.route(b"test").await.unwrap_err();
    assert!(err.to_string().contains("Invalid port number"));
}

#[tokio::test]
async fn rejects_non_numeric_port() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:abc");
    let adapter = create_adapter(resolver);

    let err = adapter.route(b"test").await.unwrap_err();
    assert!(err.to_string().contains("Invalid port number"));
}

#[tokio::test]
async fn accepts_port_one() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:1");
    let adapter = create_adapter(resolver);

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "8.8.8.8:1");
}
