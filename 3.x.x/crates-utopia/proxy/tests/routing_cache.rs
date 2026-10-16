//! Ports `tests/Unit/RoutingCacheTest.php`.

mod common;

use std::sync::Arc;

use common::MockResolver;
use utopia_proxy::adapter::Adapter;
use utopia_proxy::{Protocol, Resolver};

fn make(resolver: Arc<MockResolver>, protocol: Protocol) -> Adapter {
    let resolver: Arc<dyn Resolver> = resolver;
    Adapter::new(Some(resolver), protocol)
}

#[tokio::test]
async fn first_call_is_cache_miss() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:80");
    let adapter = make(resolver, Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter.set_cache_ttl(60).await;

    let result = adapter.route(b"resource-1").await.unwrap();
    assert_eq!(result.metadata().get("cached").unwrap(), "false");
}

#[tokio::test]
async fn second_call_within_ttl_is_cache_hit() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:80");
    let adapter = make(resolver, Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter.set_cache_ttl(60).await;

    let first = adapter.route(b"resource-1").await.unwrap();
    let second = adapter.route(b"resource-1").await.unwrap();

    assert_eq!(first.metadata().get("cached").unwrap(), "false");
    assert_eq!(second.metadata().get("cached").unwrap(), "true");
}

#[tokio::test]
async fn cache_expires_after_ttl() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:80");
    let adapter = make(resolver, Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter.set_cache_ttl(1).await;

    adapter.route(b"resource-1").await.unwrap();

    tokio::time::sleep(std::time::Duration::from_millis(1100)).await;

    let result = adapter.route(b"resource-1").await.unwrap();
    assert_eq!(result.metadata().get("cached").unwrap(), "false");
}

#[tokio::test]
async fn multiple_resources_cached_independently() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:80");
    let adapter = make(resolver, Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter.set_cache_ttl(60).await;

    let result1 = adapter.route(b"resource-1").await.unwrap();
    let result2 = adapter.route(b"resource-2").await.unwrap();

    assert_eq!(result1.metadata().get("cached").unwrap(), "false");
    assert_eq!(result2.metadata().get("cached").unwrap(), "false");

    let cached1 = adapter.route(b"resource-1").await.unwrap();
    let cached2 = adapter.route(b"resource-2").await.unwrap();

    assert_eq!(cached1.metadata().get("cached").unwrap(), "true");
    assert_eq!(cached2.metadata().get("cached").unwrap(), "true");
}

#[tokio::test]
async fn cache_hit_preserves_protocol() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:80");
    let adapter = make(resolver, Protocol::Smtp);
    adapter.set_skip_validation(true).await;
    adapter.set_cache_ttl(60).await;

    adapter.route(b"resource-1").await.unwrap();
    let cached = adapter.route(b"resource-1").await.unwrap();
    assert_eq!(cached.protocol(), Protocol::Smtp);
}

#[tokio::test]
async fn cache_hit_preserves_endpoint() {
    let resolver = Arc::new(MockResolver::new());
    resolver.set_endpoint("8.8.8.8:80");
    let adapter = make(resolver, Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter.set_cache_ttl(60).await;

    adapter.route(b"resource-1").await.unwrap();
    let cached = adapter.route(b"resource-1").await.unwrap();
    assert_eq!(cached.endpoint(), "8.8.8.8:80");
}
