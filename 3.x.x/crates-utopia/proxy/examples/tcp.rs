//! TCP proxy example (`PostgreSQL` + `MySQL`). Mirrors `examples/tcp.php`.
//!
//! Run with:
//! ```bash
//! cargo run -p utopia-proxy --example tcp
//! ```
//!
//! Environment variables (matches the PHP example):
//! - `TCP_BACKEND_ENDPOINT` (default `tcp-backend:15432`)
//! - `TCP_POSTGRES_PORT` (default 5432, 0 disables)
//! - `TCP_MYSQL_PORT` (default 3306, 0 disables)
//! - `TCP_WORKERS` (default cpus)
//! - `TCP_SKIP_VALIDATION` (bool)
//! - `TCP_SOCKMAP_ENABLED` / `TCP_SOCKMAP_BPF_OBJECT`
//! - `PROXY_TLS_ENABLED` / `PROXY_TLS_CERT` / `PROXY_TLS_KEY` / `PROXY_TLS_CA` / `PROXY_TLS_REQUIRE_CLIENT_CERT`

use std::env;
use std::path::PathBuf;
use std::sync::Arc;

use utopia_proxy::server::tcp::{TcpConfig, TcpServer};
use utopia_proxy::tls::Tls;
use utopia_proxy::Fixed;

fn env_bool(key: &str, default: bool) -> bool {
    match env::var(key) {
        Ok(value) => matches!(
            value.trim().to_ascii_lowercase().as_str(),
            "1" | "true" | "yes" | "on"
        ),
        Err(_) => default,
    }
}

fn env_u16(key: &str, default: u16) -> u16 {
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
        env::var("TCP_BACKEND_ENDPOINT").unwrap_or_else(|_| "tcp-backend:15432".to_string());
    let resolver = Arc::new(Fixed::new(backend_endpoint.clone()));

    let postgres_port = env_u16("TCP_POSTGRES_PORT", 5432);
    let mysql_port = env_u16("TCP_MYSQL_PORT", 3306);
    let mut ports: Vec<u16> = [postgres_port, mysql_port]
        .into_iter()
        .filter(|port| *port > 0)
        .collect();
    if ports.is_empty() {
        ports = vec![5432, 3306];
    }

    let workers = env_usize("TCP_WORKERS", cpus());
    let skip_validation = env_bool("TCP_SKIP_VALIDATION", false);
    let sockmap_enabled = env_bool("TCP_SOCKMAP_ENABLED", false);
    let sockmap_path = env::var("TCP_SOCKMAP_BPF_OBJECT").unwrap_or_default();

    let tls = if env_bool("PROXY_TLS_ENABLED", false) {
        let certificate = env::var("PROXY_TLS_CERT").unwrap_or_default();
        let key = env::var("PROXY_TLS_KEY").unwrap_or_default();
        if certificate.is_empty() || key.is_empty() {
            return Err(
                "PROXY_TLS_ENABLED=true but PROXY_TLS_CERT and PROXY_TLS_KEY are required".into(),
            );
        }
        let mut tls = Tls::new(certificate, key);
        let ca = env::var("PROXY_TLS_CA").unwrap_or_default();
        if !ca.is_empty() {
            tls = tls.with_ca(ca);
        }
        tls = tls.with_client_cert_required(env_bool("PROXY_TLS_REQUIRE_CLIENT_CERT", false));
        Some(Arc::new(tls))
    } else {
        None
    };

    let mut config = TcpConfig::new(ports.clone())
        .with_workers(workers)
        .with_skip_validation(skip_validation);
    if sockmap_enabled && !sockmap_path.is_empty() {
        config = config.with_sockmap(PathBuf::from(&sockmap_path));
    }
    if let Some(tls) = tls.clone() {
        config = config.with_tls(tls);
    }

    println!("Starting TCP Proxy Server...");
    println!("Host: {}", config.host);
    println!(
        "Ports: {}",
        ports
            .iter()
            .map(u16::to_string)
            .collect::<Vec<_>>()
            .join(", ")
    );
    println!("Workers: {}", config.workers);
    println!("Max connections: {}", config.max_connections);
    println!("Backend: {backend_endpoint}");
    if let Some(tls) = tls.as_deref() {
        println!("TLS: enabled (certificate: {})", tls.certificate);
        if tls.is_mutual() {
            println!("mTLS: enabled (ca: {})", tls.ca);
        }
    }
    println!();

    let server = TcpServer::new(resolver, config);

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
