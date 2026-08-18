//! Smoke test - confirms the crate exposes its top-level types.

use utopia_proxy::{Fixed, Protocol, Resolver};

#[tokio::test]
async fn fixed_resolver_returns_endpoint() {
    let resolver: Box<dyn Resolver> = Box::new(Fixed::new("backend:1234"));
    let result = resolver.resolve(b"anything").await.unwrap();
    assert_eq!(result.endpoint, "backend:1234");
}

#[test]
fn protocol_enum_maps_ports() {
    assert_eq!(Protocol::from_port(5432), Protocol::PostgreSQL);
    assert_eq!(Protocol::from_port(9999), Protocol::Tcp);
}
