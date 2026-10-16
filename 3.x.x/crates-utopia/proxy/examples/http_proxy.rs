//! HTTP proxy example with a custom host-based resolver. Mirrors
//! `examples/http-proxy.php`.
//!
//! Run with:
//! ```bash
//! cargo run -p utopia-proxy --example http_proxy
//! ```
//!
//! Test:
//! ```bash
//! curl -H 'Host: api.example.com' http://localhost:8080/
//! ```

use std::collections::HashMap;
use std::sync::Arc;

use async_trait::async_trait;
use utopia_proxy::server::http::{HttpConfig, HttpServer};
use utopia_proxy::{Resolver, ResolverError, ResolverResult};

/// Custom resolver - routes each incoming HTTP hostname to a hardcoded backend.
/// Demonstrates implementing the [`Resolver`] trait directly.
struct HostMapResolver {
    backends: HashMap<String, String>,
}

impl HostMapResolver {
    fn new(backends: HashMap<&'static str, &'static str>) -> Self {
        Self {
            backends: backends
                .into_iter()
                .map(|(host, endpoint)| (host.to_string(), endpoint.to_string()))
                .collect(),
        }
    }
}

#[async_trait]
impl Resolver for HostMapResolver {
    async fn resolve(&self, data: &[u8]) -> Result<ResolverResult, ResolverError> {
        let hostname =
            std::str::from_utf8(data).map_err(|_| ResolverError::not_found("non-utf8 hostname"))?;
        match self.backends.get(hostname) {
            Some(endpoint) => Ok(ResolverResult::new(endpoint.clone())),
            None => Err(ResolverError::not_found(format!(
                "No backend configured for hostname: {hostname}"
            ))),
        }
    }
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    // Logging goes through `utopia_console::Console` inside the servers.

    let mut backends = HashMap::new();
    backends.insert("api.example.com", "localhost:3000");
    backends.insert("app.example.com", "localhost:3001");
    backends.insert("admin.example.com", "localhost:3002");

    let resolver: Arc<dyn Resolver> = Arc::new(HostMapResolver::new(backends.clone()));

    let cpus = std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(1);
    let config = HttpConfig::new().with_port(8080).with_workers(cpus * 2);

    println!("HTTP Proxy Server");
    println!("Listening on: http://0.0.0.0:8080");
    println!();
    println!("Configured backends:");
    for (hostname, endpoint) in &backends {
        println!("  {hostname} -> {endpoint}");
    }
    println!();

    let server = HttpServer::new(Some(resolver), config);

    tokio::select! {
        result = server.start() => {
            result?;
        }
        _ = tokio::signal::ctrl_c() => {
            println!("\nshutdown signal received");
        }
    }
    Ok(())
}
