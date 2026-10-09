//! Appwrite Rust API.
//!
//! Serves the services that have been migrated to Rust. A reverse proxy
//! (Traefik) routes those paths here and everything else to the PHP API;
//! both share PostgreSQL, Redis, queues and caches.
//!
//! Usage:
//!   appwrite-rust            start the server
//!   appwrite-rust health     exit 0 when the local server answers (container healthcheck)
//!   appwrite-rust routes     print the routes served by this binary

mod health;

use std::net::SocketAddr;
use std::sync::Arc;

use appwrite_core::config::Config;
use appwrite_core::platform::{DefaultHooks, Platform, State};
use utopia_http::ServerOptions;

#[global_allocator]
static GLOBAL: mimalloc::MiMalloc = mimalloc::MiMalloc;

fn main() {
    let command = std::env::args().nth(1).unwrap_or_default();
    match command.as_str() {
        "health" => std::process::exit(health::probe()),
        "routes" => {
            for (method, path) in routes() {
                println!("{method} {path}");
            }
        }
        _ => serve(),
    }
}

/// Routes served by this binary (static list, no I/O).
fn routes() -> Vec<(String, &'static str)> {
    use appwrite_core::platform::Module;
    let mut out: Vec<(String, &'static str)> =
        appwrite_users::Users.routes().into_iter().map(|r| (r.method.to_string(), r.path)).collect();
    out.extend(health::Health.routes().into_iter().map(|r| (r.method.to_string(), r.path)));
    out
}

fn serve() {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::try_from_env("_APP_RUST_LOG").unwrap_or_else(|_| "info".into()))
        .with_target(false)
        .init();

    let workers = appwrite_core::config::env_int("_APP_RUST_WORKERS", appwrite_core::config::cpus() as i64).max(1) as usize;
    let runtime = tokio::runtime::Builder::new_multi_thread()
        .worker_threads(workers)
        .max_blocking_threads((workers * 4).max(8))
        .enable_all()
        .build()
        .expect("tokio runtime");

    runtime.block_on(async move {
        let config = Config::from_env();
        let port = config.port;
        let state = match State::new(config, Arc::new(DefaultHooks)).await {
            Ok(s) => Arc::new(s),
            Err(e) => {
                tracing::error!(error = %e, "startup failed");
                std::process::exit(1);
            }
        };
        let platform = Platform::new(state).module(&appwrite_users::Users).module(&health::Health);
        let addr = SocketAddr::from(([0, 0, 0, 0], port));
        let options = ServerOptions::default();
        tracing::info!(workers, "appwrite rust api starting");
        if let Err(e) = utopia_http::serve(addr, Arc::new(platform), options, shutdown_signal()).await {
            tracing::error!(error = %e, "server error");
            std::process::exit(1);
        }
        tracing::info!("shutdown complete");
    });
}

async fn shutdown_signal() {
    use tokio::signal::unix::{SignalKind, signal};
    let mut term = signal(SignalKind::terminate()).expect("SIGTERM handler");
    tokio::select! {
        _ = term.recv() => {}
        _ = tokio::signal::ctrl_c() => {}
    }
    tracing::info!("shutdown signal received");
}
