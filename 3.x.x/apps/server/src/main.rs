//! Appwrite Rust HTTP server (Hyper via `utopia-http`).
//!
//! Boots [`appwrite_platform`]'s shared `api`-group `Init`/`Error`/`Shutdown`
//! hooks (Rust port of `app/controllers/shared/api.php`) plus the `users`
//! module (Rust port of `Appwrite\Platform\Modules\Users`), so Traefik can
//! route `/v1/users*` to this binary while PHP continues serving every other
//! service. See `crates-appwrite/platform/README.md` for the DI resources
//! this wires (`project`, `dbForProject`, `apiKey`, `hooks`,
//! `publisherForDeletes`, `publisherForAudits`, `passwordsDictionary`).

use std::sync::Arc;

use appwrite_platform::AppwriteState;
use serde_json::json;
use utopia_console::Console;
use utopia_http::prelude::*;

/// `_APP_VERBOSE`: per-request access logs via [`Console`]. Off unless
/// set to `enabled` / `1` / `true` / `yes` / `on`.
fn verbose_from_env() -> bool {
    matches!(
        std::env::var("_APP_VERBOSE")
            .unwrap_or_default()
            .to_ascii_lowercase()
            .as_str(),
        "1" | "true" | "yes" | "on" | "enabled"
    )
}

fn main() -> Result<()> {
    // Connect before entering the Tokio runtime. The sync `postgres` client
    // (and other sync engines) call `Handle::block_on` internally; doing that
    // under `#[tokio::main]` panics with nested-runtime errors. Request-path
    // sync SQL client calls still use `block_in_place` inside utopia-database.
    let (state, adapter) = AppwriteState::connect_from_env();
    let verbose = verbose_from_env();

    // Sync SQL runs under `block_in_place`, which occupies a Tokio *worker*
    // for the query's duration. Default worker count (= CPU count, often 4)
    // would re-serialize concurrent checkouts below the connection pool size.
    // Size workers to cover the pool (and a little headroom for accepts /
    // middleware) so pooled connections can actually run in parallel.
    let pool_size = appwrite_platform::db::pool_size_from_env();
    let worker_threads = pool_size
        .saturating_mul(2)
        .max(
            std::thread::available_parallelism()
                .map(|n| n.get())
                .unwrap_or(4),
        )
        .clamp(8, 64);

    let state = Arc::new(state);

    tokio::runtime::Builder::new_multi_thread()
        .enable_all()
        .worker_threads(worker_threads)
        // Blocking pool covers Argon2 / rare `spawn_blocking` offloads; pin
        // explicitly so a future Tokio default change cannot shrink it.
        .max_blocking_threads(512)
        .build()
        .expect("tokio runtime")
        .block_on(async_main(
            state,
            adapter,
            verbose,
            pool_size,
            worker_threads,
        ))
}

async fn async_main(
    state: Arc<AppwriteState>,
    adapter: &str,
    verbose: bool,
    pool_size: usize,
    worker_threads: usize,
) -> Result<()> {
    // PHP has no equivalent -- this seeds an in-memory dev project with a
    // `standard` key scoped to `users.read`/`users.write` so `apps/server`
    // (or a test) can exercise `/v1/users*` without a real platform
    // database. Only takes effect when the Memory path above is active
    // (see `AppwriteState::seed_dev_project`); live adapters resolve
    // projects/keys from the platform connection instead.
    // `_APP_RUST_SEED_PROJECT`/`_APP_RUST_SEED_KEY` override the defaults.
    let seeded = if adapter == "memory" && std::env::var("_APP_RUST_SEED").as_deref() == Ok("1") {
        let project_id =
            std::env::var("_APP_RUST_SEED_PROJECT").unwrap_or_else(|_| "console".to_string());
        let key_secret =
            std::env::var("_APP_RUST_SEED_KEY").unwrap_or_else(|_| "appwrite-dev-key".to_string());
        state.seed_dev_project(&project_id, &key_secret, &["users.read", "users.write"]);
        Some(project_id)
    } else {
        None
    };

    let (resources, mut platform) = appwrite_platform::build(state);

    let bind = std::env::var("APPWRITE_BIND").unwrap_or_else(|_| "0.0.0.0:80".into());
    let server = HyperServer::bind(&bind, resources);
    let acceptors = server.acceptor_count();
    let mut http = Http::new(server, "UTC");
    http.set_verbose(verbose);

    http.get("/v1/health")?
        .desc("Health check")
        .inject("response")?
        .action(|ctx| async move {
            ctx.response().json(&json!({ "status": "pass" }))?;
            Ok(())
        });
    http.get("/_health")?
        .desc("Internal health check")
        .inject("response")?
        .action(|ctx| async move {
            ctx.response().json(&json!({ "status": "pass" }))?;
            Ok(())
        });

    platform
        .init_http(&mut http)
        .map_err(|err| HttpError::Other(err.to_string()))?;

    http.set_mode(Mode::Development);

    let _ = Console::success("Appwrite server started");
    if adapter == "memory" {
        let _ = Console::info(&format!("  └── Database: {adapter}"));
    } else {
        let _ = Console::info(&format!(
            "  └── Database: {adapter} (pool {pool_size}, workers {worker_threads})"
        ));
    }
    if let Some(project_id) = seeded {
        let _ = Console::info(&format!(
            "  └── Seeded project {project_id:?} (users.read/users.write key)"
        ));
    }
    if verbose {
        let _ = Console::info("  └── Verbose request logging enabled");
        let _ = Console::info(&format!("  └── Acceptors: {acceptors} (SO_REUSEPORT)"));
    }
    let _ = Console::success(&format!(
        "Listening on http://{bind} (/v1/health, /v1/users*)"
    ));

    http.start().await
}
