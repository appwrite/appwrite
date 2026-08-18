//! Ports `tests/Unit/OnResolveCallbackTest.php`.

mod common;

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};

use common::MockResolver;
use utopia_proxy::adapter::Adapter;
use utopia_proxy::{Protocol, Resolver, ResolverError, ResolverResult};

#[tokio::test]
async fn route_uses_callback_when_set() {
    let mock = Arc::new(MockResolver::new());
    mock.set_endpoint("should-not-be-used.example.com:8080");
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter
        .set_on_resolve(|_data| Some(Ok(ResolverResult::new("callback-host.example.com:9090"))))
        .await;

    let result = adapter.route(b"test-resource").await.unwrap();
    assert_eq!(result.endpoint(), "callback-host.example.com:9090");
}

#[tokio::test]
async fn route_falls_back_to_resolver_when_no_callback() {
    let mock = Arc::new(MockResolver::new());
    mock.set_endpoint("resolver-host.example.com:8080");
    let resolver: Arc<dyn Resolver> = mock;
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;

    let result = adapter.route(b"test-resource").await.unwrap();
    assert_eq!(result.endpoint(), "resolver-host.example.com:8080");
}

#[tokio::test]
async fn callback_returns_string_endpoint() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter
        .set_on_resolve(|_data| Some(Ok(ResolverResult::new("string-endpoint.example.com:5432"))))
        .await;

    let result = adapter.route(b"my-db").await.unwrap();
    assert_eq!(result.endpoint(), "string-endpoint.example.com:5432");
    assert_eq!(result.metadata().get("cached").unwrap(), "false");
}

#[tokio::test]
async fn callback_returns_result_object_with_metadata() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter
        .set_on_resolve(|data| {
            let mut metadata = HashMap::new();
            metadata.insert("custom".to_string(), "metadata".to_string());
            metadata.insert(
                "input".to_string(),
                String::from_utf8_lossy(data).to_string(),
            );
            Some(Ok(
                ResolverResult::new("result-endpoint.example.com:3306").with_metadata(metadata)
            ))
        })
        .await;

    let result = adapter.route(b"my-db").await.unwrap();
    assert_eq!(result.endpoint(), "result-endpoint.example.com:3306");
    assert_eq!(result.metadata().get("custom").unwrap(), "metadata");
    assert_eq!(result.metadata().get("input").unwrap(), "my-db");
    assert_eq!(result.metadata().get("cached").unwrap(), "false");
}

#[tokio::test]
async fn callback_receives_resource_id() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let received: Arc<Mutex<Vec<String>>> = Arc::new(Mutex::new(Vec::new()));
    let received_clone = received.clone();

    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter
        .set_on_resolve(move |data| {
            received_clone
                .lock()
                .unwrap()
                .push(String::from_utf8_lossy(data).to_string());
            Some(Ok(ResolverResult::new("host.example.com:8080")))
        })
        .await;

    adapter.route(b"resource-alpha").await.unwrap();
    adapter.route(b"resource-beta").await.unwrap();

    let seen = received.lock().unwrap();
    assert!(seen.iter().any(|s| s == "resource-alpha"));
    assert!(seen.iter().any(|s| s == "resource-beta"));
}

#[tokio::test]
async fn route_errors_when_no_callback_or_resolver() {
    let adapter = Adapter::new(None, Protocol::Http);
    adapter.set_skip_validation(true).await;

    let err = adapter.route(b"test-resource").await.unwrap_err();
    assert!(matches!(err, ResolverError::NotFound { .. }));
    assert!(err
        .to_string()
        .contains("No resolver or resolve callback configured"));
}

#[tokio::test]
async fn callback_takes_priority_over_resolver() {
    let mock = Arc::new(MockResolver::new());
    mock.set_endpoint("resolver.example.com:8080");
    let was_called = Arc::new(AtomicBool::new(false));

    // Wrap with a resolver that flips the flag when called.
    struct FlagResolver {
        flag: Arc<AtomicBool>,
        inner: Arc<MockResolver>,
    }
    #[async_trait::async_trait]
    impl Resolver for FlagResolver {
        async fn resolve(&self, data: &[u8]) -> Result<ResolverResult, ResolverError> {
            self.flag.store(true, Ordering::Relaxed);
            self.inner.resolve(data).await
        }
    }

    let resolver: Arc<dyn Resolver> = Arc::new(FlagResolver {
        flag: was_called.clone(),
        inner: mock,
    });
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter
        .set_on_resolve(|_data| Some(Ok(ResolverResult::new("callback.example.com:8080"))))
        .await;

    let result = adapter.route(b"test-resource").await.unwrap();
    assert_eq!(result.endpoint(), "callback.example.com:8080");
    assert!(!was_called.load(Ordering::Relaxed));
}

#[tokio::test]
async fn string_callback_result_has_default_metadata() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter
        .set_on_resolve(|_data| Some(Ok(ResolverResult::new("host.example.com:8080"))))
        .await;

    let result = adapter.route(b"test-resource").await.unwrap();
    assert!(result.metadata().contains_key("cached"));
    assert_eq!(result.metadata().get("cached").unwrap(), "false");
}

#[tokio::test]
async fn result_object_metadata_is_merged() {
    let resolver: Arc<dyn Resolver> = Arc::new(MockResolver::new());
    let adapter = Adapter::new(Some(resolver), Protocol::Http);
    adapter.set_skip_validation(true).await;
    adapter
        .set_on_resolve(|_data| {
            let mut metadata = HashMap::new();
            metadata.insert("region".to_string(), "us-east-1".to_string());
            metadata.insert("tier".to_string(), "premium".to_string());
            Some(Ok(
                ResolverResult::new("host.example.com:8080").with_metadata(metadata)
            ))
        })
        .await;

    let result = adapter.route(b"test-resource").await.unwrap();
    assert_eq!(result.metadata().get("region").unwrap(), "us-east-1");
    assert_eq!(result.metadata().get("tier").unwrap(), "premium");
    assert_eq!(result.metadata().get("cached").unwrap(), "false");
}

// Quiet unused-import lint on AtomicUsize (kept for possible future use).
#[allow(dead_code)]
fn _unused() -> AtomicUsize {
    AtomicUsize::new(0)
}
