//! SMTP proxy example. Mirrors `examples/smtp.php`.
//!
//! Run with:
//! ```bash
//! cargo run -p utopia-proxy --example smtp
//! ```
//!
//! Environment variables (matches the PHP example):
//! - `SMTP_BACKEND_ENDPOINT` (default `smtp-backend:1025`)
//! - `SMTP_PORT` (default 25)
//! - `SMTP_WORKERS` (default cpus * 2)
//! - `SMTP_SKIP_VALIDATION` (bool)

use std::env;
use std::sync::Arc;

use utopia_proxy::server::smtp::{SmtpConfig, SmtpServer};
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
        env::var("SMTP_BACKEND_ENDPOINT").unwrap_or_else(|_| "smtp-backend:1025".to_string());
    let resolver = Arc::new(Fixed::new(backend_endpoint.clone()));

    let port = env_u16("SMTP_PORT", 25);
    let workers = env_usize("SMTP_WORKERS", cpus() * 2);
    let skip_validation = env_bool("SMTP_SKIP_VALIDATION", false);

    let config = SmtpConfig {
        port,
        workers,
        skip_validation,
        ..SmtpConfig::default()
    };

    println!("Starting SMTP Proxy Server...");
    println!("Port: {}", config.port);
    println!("Workers: {}", config.workers);
    println!("Backend: {backend_endpoint}");
    println!();

    let server = SmtpServer::new(resolver, config);

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
