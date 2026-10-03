//! Ports `tests/Integration/EdgeIntegrationTest.php`.
//!
//! Simulates the Edge-style resolver pattern: resource ID in -> backend
//! endpoint + credentials out. Verifies the full route -> cache -> replay flow.

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_proxy::{Protocol, Resolver, ResolverError, ResolverResult, TcpAdapter};

fn dyn_resolver<R: Resolver + 'static>(resolver: Arc<R>) -> Arc<dyn Resolver> {
    resolver
}

#[derive(Clone, Debug)]
struct DatabaseConfig {
    host: String,
    port: u16,
    username: String,
    // Password held but not returned in metadata (PHP test parity).
    #[allow(dead_code)]
    password: String,
}

#[derive(Debug)]
struct EdgeMockResolver {
    databases: Mutex<HashMap<String, DatabaseConfig>>,
    resolve_count: AtomicUsize,
    unavailable: AtomicBool,
}

impl EdgeMockResolver {
    fn new() -> Self {
        Self {
            databases: Mutex::new(HashMap::new()),
            resolve_count: AtomicUsize::new(0),
            unavailable: AtomicBool::new(false),
        }
    }

    fn register(&self, resource_id: &str, cfg: DatabaseConfig) {
        self.databases
            .lock()
            .unwrap()
            .insert(resource_id.to_string(), cfg);
    }

    fn set_unavailable(&self, v: bool) {
        self.unavailable.store(v, Ordering::Relaxed);
    }

    fn resolve_count(&self) -> usize {
        self.resolve_count.load(Ordering::Relaxed)
    }
}

#[async_trait]
impl Resolver for EdgeMockResolver {
    async fn resolve(&self, data: &[u8]) -> Result<ResolverResult, ResolverError> {
        let key = String::from_utf8_lossy(data).to_string();

        if self.unavailable.load(Ordering::Relaxed) {
            let mut ctx = HashMap::new();
            ctx.insert("resourceId".to_string(), key.clone());
            return Err(ResolverError::unavailable("Edge service unavailable").with_context(ctx));
        }

        let dbs = self.databases.lock().unwrap();
        let Some(cfg) = dbs.get(&key) else {
            let mut ctx = HashMap::new();
            ctx.insert("resourceId".to_string(), key.clone());
            return Err(
                ResolverError::not_found(format!("Database not found: {key}")).with_context(ctx),
            );
        };

        self.resolve_count.fetch_add(1, Ordering::Relaxed);
        let endpoint = format!("{}:{}", cfg.host, cfg.port);
        let mut metadata = HashMap::new();
        metadata.insert("resourceId".to_string(), key);
        metadata.insert("username".to_string(), cfg.username.clone());
        Ok(ResolverResult::new(endpoint).with_metadata(metadata))
    }
}

#[derive(Debug)]
struct FailoverResolver {
    primary: Arc<EdgeMockResolver>,
    secondary: Arc<EdgeMockResolver>,
    failed_over: AtomicBool,
}

impl FailoverResolver {
    fn new(primary: Arc<EdgeMockResolver>, secondary: Arc<EdgeMockResolver>) -> Self {
        Self {
            primary,
            secondary,
            failed_over: AtomicBool::new(false),
        }
    }

    fn did_failover(&self) -> bool {
        self.failed_over.load(Ordering::Relaxed)
    }
}

#[async_trait]
impl Resolver for FailoverResolver {
    async fn resolve(&self, data: &[u8]) -> Result<ResolverResult, ResolverError> {
        self.failed_over.store(false, Ordering::Relaxed);
        if let Ok(r) = self.primary.resolve(data).await {
            Ok(r)
        } else {
            self.failed_over.store(true, Ordering::Relaxed);
            self.secondary.resolve(data).await
        }
    }
}

fn cfg(host: &str, port: u16, user: &str, pw: &str) -> DatabaseConfig {
    DatabaseConfig {
        host: host.into(),
        port,
        username: user.into(),
        password: pw.into(),
    }
}

#[tokio::test]
async fn edge_resolver_resolves_database_id_to_endpoint() {
    let resolver = Arc::new(EdgeMockResolver::new());
    resolver.register(
        "abc123",
        cfg("10.0.1.50", 5432, "appwrite_user", "secret_password"),
    );

    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(resolver.clone())));
    adapter.base().set_skip_validation(true).await;

    let result = adapter.route(b"abc123").await.unwrap();

    assert_eq!(result.endpoint(), "10.0.1.50:5432");
    assert_eq!(result.protocol(), Protocol::PostgreSQL);
    assert_eq!(result.metadata().get("resourceId").unwrap(), "abc123");
    assert_eq!(result.metadata().get("username").unwrap(), "appwrite_user");
    assert_eq!(result.metadata().get("cached").unwrap(), "false");
}

#[tokio::test]
async fn edge_resolver_returns_not_found_for_unknown_database() {
    let resolver = Arc::new(EdgeMockResolver::new());
    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(resolver)));
    adapter.base().set_skip_validation(true).await;

    let err = adapter.route(b"nonexistent").await.unwrap_err();
    assert_eq!(err.code(), ResolverError::NOT_FOUND);
}

#[tokio::test]
async fn resolver_receives_raw_data_for_routing() {
    let resolver = Arc::new(EdgeMockResolver::new());
    resolver.register("raw-packet-data", cfg("10.0.1.50", 5432, "user1", "pass1"));

    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(resolver.clone())));
    adapter.base().set_skip_validation(true).await;

    let result = adapter.route(b"raw-packet-data").await.unwrap();
    assert_eq!(result.endpoint(), "10.0.1.50:5432");
}

#[tokio::test]
async fn failover_resolver_uses_secondary_on_primary_failure() {
    let primary = Arc::new(EdgeMockResolver::new());
    let secondary = Arc::new(EdgeMockResolver::new());
    secondary.register(
        "faildb",
        cfg("10.0.2.50", 5432, "failover_user", "failover_pass"),
    );

    let failover = Arc::new(FailoverResolver::new(primary, secondary));
    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(failover.clone())));
    adapter.base().set_skip_validation(true).await;

    let result = adapter.route(b"faildb").await.unwrap();

    assert_eq!(result.endpoint(), "10.0.2.50:5432");
    assert!(failover.did_failover());
}

#[tokio::test]
async fn failover_resolver_uses_primary_when_available() {
    let primary = Arc::new(EdgeMockResolver::new());
    primary.register(
        "okdb",
        cfg("10.0.1.10", 5432, "primary_user", "primary_pass"),
    );
    let secondary = Arc::new(EdgeMockResolver::new());
    secondary.register(
        "okdb",
        cfg("10.0.2.50", 5432, "secondary_user", "secondary_pass"),
    );

    let failover = Arc::new(FailoverResolver::new(primary, secondary));
    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(failover.clone())));
    adapter.base().set_skip_validation(true).await;

    let result = adapter.route(b"okdb").await.unwrap();

    assert_eq!(result.endpoint(), "10.0.1.10:5432");
    assert!(!failover.did_failover());
}

#[tokio::test]
async fn failover_resolver_propagates_error_when_both_fail() {
    let primary = Arc::new(EdgeMockResolver::new());
    let secondary = Arc::new(EdgeMockResolver::new());

    let failover = Arc::new(FailoverResolver::new(primary, secondary));
    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(failover)));
    adapter.base().set_skip_validation(true).await;

    let err = adapter.route(b"nowhere").await.unwrap_err();
    assert_eq!(err.code(), ResolverError::NOT_FOUND);
}

#[tokio::test]
async fn failover_resolver_handles_unavailable_primary() {
    let primary = Arc::new(EdgeMockResolver::new());
    primary.set_unavailable(true);

    let secondary = Arc::new(EdgeMockResolver::new());
    secondary.register(
        "unavaildb",
        cfg("10.0.3.10", 5432, "backup_user", "backup_pass"),
    );

    let failover = Arc::new(FailoverResolver::new(primary, secondary));
    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(failover.clone())));
    adapter.base().set_skip_validation(true).await;

    let result = adapter.route(b"unavaildb").await.unwrap();
    assert_eq!(result.endpoint(), "10.0.3.10:5432");
    assert!(failover.did_failover());
}

#[tokio::test]
async fn routing_cache_returns_cached_result_on_repeat() {
    let resolver = Arc::new(EdgeMockResolver::new());
    resolver.register(
        "cachedb",
        cfg("10.0.4.10", 5432, "cached_user", "cached_pass"),
    );

    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(resolver.clone())));
    adapter.base().set_skip_validation(true).await;
    adapter.base().set_cache_ttl(60).await;

    let first = adapter.route(b"cachedb").await.unwrap();
    assert_eq!(first.metadata().get("cached").unwrap(), "false");

    let second = adapter.route(b"cachedb").await.unwrap();
    assert_eq!(second.metadata().get("cached").unwrap(), "true");

    assert_eq!(first.endpoint(), second.endpoint());
    assert_eq!(resolver.resolve_count(), 1);
}

#[tokio::test]
async fn cache_invalidation_forces_re_resolve() {
    let resolver = Arc::new(EdgeMockResolver::new());
    resolver.register("invaldb", cfg("10.0.4.20", 5432, "user", "pass"));

    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(resolver.clone())));
    adapter.base().set_skip_validation(true).await;
    adapter.base().set_cache_ttl(1).await;

    let first = adapter.route(b"invaldb").await.unwrap();
    assert_eq!(first.metadata().get("cached").unwrap(), "false");

    tokio::time::sleep(std::time::Duration::from_millis(1100)).await;

    let second = adapter.route(b"invaldb").await.unwrap();
    assert_eq!(second.metadata().get("cached").unwrap(), "false");

    assert_eq!(resolver.resolve_count(), 2);
}

#[tokio::test]
async fn different_databases_resolve_independently() {
    let resolver = Arc::new(EdgeMockResolver::new());
    resolver.register("db1", cfg("10.0.5.1", 5432, "user1", "pass1"));
    resolver.register("db2", cfg("10.0.5.2", 5432, "user2", "pass2"));

    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(resolver)));
    adapter.base().set_skip_validation(true).await;

    let result1 = adapter.route(b"db1").await.unwrap();
    let result2 = adapter.route(b"db2").await.unwrap();

    assert_eq!(result1.endpoint(), "10.0.5.1:5432");
    assert_eq!(result2.endpoint(), "10.0.5.2:5432");
}

#[tokio::test]
async fn concurrent_resolution_of_multiple_databases() {
    let resolver = Arc::new(EdgeMockResolver::new());
    let count = 20;
    for i in 1..=count {
        resolver.register(
            &format!("concurrent{i}"),
            cfg(&format!("10.0.10.{i}"), 5432, &format!("user_{i}"), "p"),
        );
    }

    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(resolver)));
    adapter.base().set_skip_validation(true).await;
    adapter.base().set_cache_ttl(60).await;

    for i in 1..=count {
        let key = format!("concurrent{i}");
        let r = adapter.route(key.as_bytes()).await.unwrap();
        assert_eq!(r.endpoint(), format!("10.0.10.{i}:5432"));
        assert_eq!(r.protocol(), Protocol::PostgreSQL);
    }
}

#[tokio::test]
async fn concurrent_resolution_with_mixed_success_and_failure() {
    let resolver = Arc::new(EdgeMockResolver::new());
    resolver.register("gooddb1", cfg("10.0.11.1", 5432, "u", "p"));
    resolver.register("gooddb2", cfg("10.0.11.2", 5432, "u", "p"));

    let adapter = TcpAdapter::new(5432, Some(dyn_resolver(resolver)));
    adapter.base().set_skip_validation(true).await;

    let r1 = adapter.route(b"gooddb1").await.unwrap();
    assert_eq!(r1.endpoint(), "10.0.11.1:5432");
    let r2 = adapter.route(b"gooddb2").await.unwrap();
    assert_eq!(r2.endpoint(), "10.0.11.2:5432");

    let err = adapter.route(b"baddb").await.unwrap_err();
    assert_eq!(err.code(), ResolverError::NOT_FOUND);
}
