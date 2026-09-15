//! Ports `tests/Unit/ResolverTest.php` + `ResolverExtendedTest.php`.

mod common;

use std::collections::HashMap;
use std::time::Duration;

use common::MockResolver;
use utopia_proxy::{Resolver, ResolverError, ResolverResult};

#[test]
fn resolver_result_stores_values() {
    let mut metadata = HashMap::new();
    metadata.insert("cached".to_string(), "false".to_string());
    metadata.insert("type".to_string(), "http".to_string());

    let result = ResolverResult::new("127.0.0.1:8080")
        .with_metadata(metadata.clone())
        .with_timeout(Duration::from_secs(30));

    assert_eq!(result.endpoint, "127.0.0.1:8080");
    assert_eq!(result.metadata, metadata);
    assert_eq!(result.timeout, Some(Duration::from_secs(30)));
}

#[test]
fn resolver_result_default_values() {
    let result = ResolverResult::new("127.0.0.1:8080");

    assert_eq!(result.endpoint, "127.0.0.1:8080");
    assert!(result.metadata.is_empty());
    assert!(result.timeout.is_none());
}

#[test]
fn resolver_exception_with_context() {
    let mut context = HashMap::new();
    context.insert("resourceId".to_string(), "abc123".to_string());
    context.insert("type".to_string(), "database".to_string());

    let exception = ResolverError::not_found("Resource not found").with_context(context.clone());

    assert_eq!(exception.to_string(), "not found: Resource not found");
    assert_eq!(exception.code(), 404);
    assert_eq!(exception.context(), &context);
}

#[test]
fn resolver_exception_error_codes() {
    assert_eq!(ResolverError::NOT_FOUND, 404);
    assert_eq!(ResolverError::UNAVAILABLE, 503);
    assert_eq!(ResolverError::TIMEOUT, 504);
    assert_eq!(ResolverError::FORBIDDEN, 403);
    assert_eq!(ResolverError::INTERNAL, 500);
}

#[test]
fn resolver_exception_default_context_is_empty() {
    let exception = ResolverError::internal("Internal error");
    assert_eq!(exception.code(), 500);
    assert!(exception.context().is_empty());
}

#[test]
fn result_with_empty_endpoint() {
    let result = ResolverResult::new("");
    assert_eq!(result.endpoint, "");
}

#[test]
fn result_with_large_metadata() {
    let mut metadata = HashMap::new();
    for i in 0..100 {
        metadata.insert(format!("key_{i}"), format!("value_{i}"));
    }

    let result = ResolverResult::new("host:80").with_metadata(metadata);
    assert_eq!(result.metadata.len(), 100);
    assert_eq!(result.metadata.get("key_50").unwrap(), "value_50");
}

#[test]
fn result_with_zero_timeout() {
    let result = ResolverResult::new("host:80").with_timeout(Duration::ZERO);
    assert_eq!(result.timeout, Some(Duration::ZERO));
}

#[test]
fn exception_not_found() {
    let e = ResolverError::not_found("Not found");
    assert_eq!(e.code(), 404);
}

#[test]
fn exception_unavailable() {
    let e = ResolverError::unavailable("Down");
    assert_eq!(e.code(), 503);
}

#[test]
fn exception_timeout() {
    let e = ResolverError::timeout("Slow");
    assert_eq!(e.code(), 504);
}

#[test]
fn exception_forbidden() {
    let e = ResolverError::forbidden("Denied");
    assert_eq!(e.code(), 403);
}

#[test]
fn exception_internal() {
    let e = ResolverError::internal("Crash");
    assert_eq!(e.code(), 500);
}

#[test]
fn exception_with_empty_context() {
    let e = ResolverError::internal("test");
    assert!(e.context().is_empty());
}

#[test]
fn exception_with_rich_context() {
    let mut context = HashMap::new();
    context.insert("resourceId".to_string(), "db-123".to_string());
    context.insert("attempt".to_string(), "3".to_string());
    context.insert("lastError".to_string(), "connection refused".to_string());

    let e = ResolverError::unavailable("Failed after retries").with_context(context.clone());

    assert_eq!(e.context().get("resourceId").unwrap(), "db-123");
    assert_eq!(e.context().get("attempt").unwrap(), "3");
    assert_eq!(e.context().get("lastError").unwrap(), "connection refused");
}

#[tokio::test]
async fn mock_resolver_resolves_endpoint() {
    let resolver = MockResolver::new();
    resolver.set_endpoint("backend.db:5432");

    let result = resolver.resolve(b"test-resource").await.unwrap();

    assert_eq!(result.endpoint, "backend.db:5432");
    assert_eq!(result.metadata.get("data").unwrap(), "test-resource");
}

#[tokio::test]
async fn mock_resolver_errors_when_no_endpoint() {
    let resolver = MockResolver::new();
    let err = resolver.resolve(b"test-resource").await.unwrap_err();
    assert_eq!(err.code(), 404);
}

#[tokio::test]
async fn mock_resolver_returns_configured_exception() {
    let resolver = MockResolver::new();
    resolver.set_exception(ResolverError::timeout("custom error"));

    let err = resolver.resolve(b"test-resource").await.unwrap_err();
    assert_eq!(err.code(), 504);
    assert_eq!(err.to_string(), "timeout: custom error");
}
