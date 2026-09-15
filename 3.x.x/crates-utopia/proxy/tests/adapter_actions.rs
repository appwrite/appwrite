//! Ports `tests/Unit/AdapterActionsTest.php`.

mod common;

use std::sync::Arc;

use common::MockResolver;
use utopia_proxy::adapter::Adapter;
use utopia_proxy::{Protocol, Resolver, ResolverError, TcpAdapter};

#[tokio::test]
async fn resolver_is_assigned_to_adapters() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let http = Adapter::new(Some(resolver.clone()), Protocol::Http);
    let tcp = TcpAdapter::new(5432, Some(resolver.clone()));
    let smtp = Adapter::new(Some(resolver.clone()), Protocol::Smtp);

    assert!(http.resolver().is_some());
    assert!(tcp.base().resolver().is_some());
    assert!(smtp.resolver().is_some());
}

#[tokio::test]
async fn resolve_routes_and_returns_endpoint() {
    let mock = Arc::new(MockResolver::new());
    mock.set_endpoint("127.0.0.1:8080");
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;

    let result = adapter.route(b"api.example.com").await.unwrap();

    assert_eq!(result.endpoint(), "127.0.0.1:8080");
    assert_eq!(result.protocol(), Protocol::Http);
}

#[tokio::test]
async fn routing_error_propagates_exception() {
    let mock = Arc::new(MockResolver::new());
    mock.set_exception(ResolverError::not_found("No backend found"));
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = Adapter::new(Some(resolver), Protocol::Http);

    let err = adapter.route(b"api.example.com").await.unwrap_err();
    assert!(err.to_string().contains("No backend found"));
}

#[tokio::test]
async fn empty_endpoint_throws_exception() {
    let mock = Arc::new(MockResolver::new());
    mock.set_endpoint("");
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = Adapter::new(Some(resolver), Protocol::Http);

    let err = adapter.route(b"api.example.com").await.unwrap_err();
    assert!(err.to_string().contains("Resolver returned empty endpoint"));
}

#[tokio::test]
async fn skip_validation_allows_private_ips() {
    let mock = Arc::new(MockResolver::new());
    mock.set_endpoint("10.0.0.1:8080");
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;

    let result = adapter.route(b"api.example.com").await.unwrap();
    assert_eq!(result.endpoint(), "10.0.0.1:8080");
}

#[tokio::test]
async fn get_protocol_returns_constructed_protocol() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let http = Adapter::new(Some(resolver.clone()), Protocol::Http);
    let smtp = Adapter::new(Some(resolver.clone()), Protocol::Smtp);
    let tcp = Adapter::new(Some(resolver.clone()), Protocol::Tcp);

    assert_eq!(http.protocol(), Protocol::Http);
    assert_eq!(smtp.protocol(), Protocol::Smtp);
    assert_eq!(tcp.protocol(), Protocol::Tcp);
}

#[tokio::test]
async fn route_callback_returning_string_endpoint() {
    let mock = Arc::new(MockResolver::new());
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter
        .set_on_resolve(|_data| Some(Ok(utopia_proxy::ResolverResult::new("10.0.0.1:8080"))))
        .await;

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.endpoint(), "10.0.0.1:8080");
    assert_eq!(result.protocol(), Protocol::Http);
    assert_eq!(result.metadata().get("cached").unwrap(), "false");
}

#[tokio::test]
async fn route_throws_when_no_resolver_and_no_callback() {
    let adapter = Adapter::new(None, Protocol::Http);

    let err = adapter.route(b"test").await.unwrap_err();
    assert!(err
        .to_string()
        .contains("No resolver or resolve callback configured"));
}

#[tokio::test]
async fn route_with_null_resolver_but_valid_callback() {
    let adapter = Adapter::new(None, Protocol::Tcp);
    adapter.set_skip_validation(true).await;
    adapter
        .set_on_resolve(|_data| Some(Ok(utopia_proxy::ResolverResult::new("10.0.0.1:5432"))))
        .await;

    let result = adapter.route(b"my-resource").await.unwrap();
    assert_eq!(result.endpoint(), "10.0.0.1:5432");
}

#[tokio::test]
async fn route_protocol_is_preserved_in_result() {
    let mock = Arc::new(MockResolver::new());
    mock.set_endpoint("8.8.8.8:80");
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = Adapter::new(Some(resolver), Protocol::Smtp);

    let result = adapter.route(b"test").await.unwrap();
    assert_eq!(result.protocol(), Protocol::Smtp);
}

#[tokio::test]
async fn route_metadata_from_resolver_is_merged() {
    let mock = Arc::new(MockResolver::new());
    mock.set_endpoint("8.8.8.8:80");
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = Adapter::new(Some(resolver), Protocol::Http);

    let result = adapter.route(b"my-input").await.unwrap();
    assert_eq!(result.metadata().get("data").unwrap(), "my-input");
    assert_eq!(result.metadata().get("cached").unwrap(), "false");
}

#[tokio::test]
async fn tcp_adapter_route_uses_protocol_from_port() {
    let mock = Arc::new(MockResolver::new());
    mock.set_endpoint("8.8.8.8:5432");
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = TcpAdapter::new(5432, Some(resolver));
    adapter.base().set_skip_validation(true).await;

    let result = adapter.route(b"data").await.unwrap();
    assert_eq!(result.protocol(), Protocol::PostgreSQL);
}
