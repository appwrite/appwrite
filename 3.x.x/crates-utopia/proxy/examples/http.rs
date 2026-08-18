//! HTTP proxy example. Mirrors `examples/http.php`.
//!
//! Run with:
//! ```bash
//! cargo run -p utopia-proxy --example http
//! ```
//!
//! Environment variables (matches the PHP example):
//! - `HTTP_BACKEND_ENDPOINT` (default `http-backend:5678`)
//! - `HTTP_PORT` (default 8080)
//! - `HTTP_WORKERS` (default cpus * 2)
//! - `HTTP_BACKEND_POOL_SIZE` (default 2048)
//! - `HTTP_KEEPALIVE_TIMEOUT` (default 60)
//! - `HTTP_OPEN_HTTP2` (bool)
//! - `HTTP_SKIP_VALIDATION` (bool)
//! - `HTTP_FAST_PATH` (bool, default true)
//! - `HTTP_RAW_BACKEND` (bool)

use std::env;
use std::sync::Arc;

use utopia_proxy::server::http::{HttpConfig, HttpServer};
use utopia_proxy::Fixed;

fn env_bool(key: &str, default: bool) -> bool {
    match env::var(key) {
        Ok(value) => match value.trim().to_ascii_lowercase().as_str() {
            "1" | "true" | "yes" | "on" => true,
            "0" | "false" | "no" | "off" | "" => false,
            _ => default,
        },
        Err(_) => default,
    }
}

fn env_u16(key: &str, default: u16) -> u16 {
    env::var(key)
        .ok()
        .and_then(|value| value.trim().parse().ok())
        .unwrap_or(default)
}

fn env_u64(key: &str, default: u64) -> u64 {
    env::var(key)
        .ok()
        .and_then(|value| value.trim().parse().ok())
        .unwrap_or(default)
}

fn env_usize(key: &str, default: usize) -> usize {
    env::var(key)
        .ok()
        .and_then(|value| value.trim().parse().ok())
        .unwrap_or(default)
}

fn cpus() -> usize {
    std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(1)
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    // Logging goes through `utopia_console::Console` inside the servers.

    let backend_endpoint =
        env::var("HTTP_BACKEND_ENDPOINT").unwrap_or_else(|_| "http-backend:5678".to_string());
    let resolver = Arc::new(Fixed::new(backend_endpoint.clone()));

    let port = env_u16("HTTP_PORT", 8080);
    let workers = env_usize("HTTP_WORKERS", cpus() * 2);
    let pool_size = env_usize("HTTP_BACKEND_POOL_SIZE", 2048);
    let keepalive_timeout = env_u64("HTTP_KEEPALIVE_TIMEOUT", 60);
    let http2_enabled = env_bool("HTTP_OPEN_HTTP2", false);
    let skip_validation = env_bool("HTTP_SKIP_VALIDATION", false);
    let fast_path = env_bool("HTTP_FAST_PATH", true);
    let raw_backend = env_bool("HTTP_RAW_BACKEND", false);

    let mut config = HttpConfig::new()
        .with_port(port)
        .with_workers(workers)
        .with_pool_size(pool_size)
        .with_http2(http2_enabled)
        .with_skip_validation(skip_validation)
        .with_raw_backend(raw_backend);
    config.keepalive_timeout = keepalive_timeout;
    config.fast_path = fast_path;

    println!("Starting HTTP Proxy Server...");
    println!("Port: {}", config.port);
    println!("Workers: {}", config.workers);
    println!("Backend: {backend_endpoint}");
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
