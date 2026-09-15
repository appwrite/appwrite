//! Ports `tests/Unit/FixedResolverTest.php`.

use utopia_proxy::{Fixed, Resolver};

#[tokio::test]
async fn resolve_returns_configured_endpoint() {
    let resolver = Fixed::new("backend.db:5432");
    let result = resolver.resolve(b"any-input").await.unwrap();
    assert_eq!(result.endpoint, "backend.db:5432");
}

#[tokio::test]
async fn resolve_ignores_input() {
    let resolver = Fixed::new("static-host:8080");

    let first = resolver.resolve(b"input-one").await.unwrap();
    let second = resolver.resolve(b"input-two").await.unwrap();
    let third = resolver.resolve(b"").await.unwrap();

    assert_eq!(first.endpoint, "static-host:8080");
    assert_eq!(second.endpoint, "static-host:8080");
    assert_eq!(third.endpoint, "static-host:8080");
}

#[tokio::test]
async fn resolve_returns_empty_metadata() {
    let resolver = Fixed::new("host:80");
    let result = resolver.resolve(b"data").await.unwrap();
    assert!(result.metadata.is_empty());
}

#[tokio::test]
async fn resolve_returns_none_timeout() {
    let resolver = Fixed::new("host:80");
    let result = resolver.resolve(b"data").await.unwrap();
    assert!(result.timeout.is_none());
}

#[tokio::test]
async fn resolve_with_empty_endpoint() {
    let resolver = Fixed::new("");
    let result = resolver.resolve(b"data").await.unwrap();
    assert_eq!(result.endpoint, "");
}

#[tokio::test]
async fn resolve_with_host_only() {
    let resolver = Fixed::new("my-backend");
    let result = resolver.resolve(b"data").await.unwrap();
    assert_eq!(result.endpoint, "my-backend");
}

#[tokio::test]
async fn resolve_with_ip_address() {
    let resolver = Fixed::new("10.0.0.1:3306");
    let result = resolver.resolve(b"data").await.unwrap();
    assert_eq!(result.endpoint, "10.0.0.1:3306");
}

#[tokio::test]
async fn resolve_returns_consistent_endpoint_each_call() {
    let resolver = Fixed::new("host:80");
    let first = resolver.resolve(b"a").await.unwrap();
    let second = resolver.resolve(b"b").await.unwrap();
    assert_eq!(first.endpoint, second.endpoint);
}
