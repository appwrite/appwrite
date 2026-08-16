//! Appwrite Rust HTTP server (Hyper via `utopia-http`).
//!
//! Boots [`appwrite_platform`]'s shared `api`-group `Init`/`Error`/`Shutdown`
//! hooks (Rust port of `app/controllers/shared/api.php`) plus the `users`
//! module (Rust port of `Appwrite\Platform\Modules\Users`), so Traefik can
//! route `/v1/users*` to this binary while PHP continues serving every other
//! service. See `crates/appwrite-platform/README.md` for the DI resources
//! this wires (`project`, `dbForProject`, `apiKey`, `hooks`,
//! `publisherForDeletes`, `publisherForAudits`, `passwordsDictionary`).

use std::sync::Arc;

use appwrite_platform::AppwriteState;
use serde_json::json;
use utopia_http::prelude::*;

#[tokio::main]
async fn main() -> Result<()> {
    let state = Arc::new(AppwriteState::new());

    // PHP has no equivalent -- this milestone has no real platform database
    // to load `console`'s projects/keys from, so `_APP_RUST_SEED=1` seeds an
    // in-memory dev project with a `standard` key scoped to
    // `users.read`/`users.write` (see `AppwriteState::seed_dev_project`).
    // `_APP_RUST_SEED_PROJECT`/`_APP_RUST_SEED_KEY` override the defaults.
    if std::env::var("_APP_RUST_SEED").as_deref() == Ok("1") {
        let project_id =
            std::env::var("_APP_RUST_SEED_PROJECT").unwrap_or_else(|_| "console".to_string());
        let key_secret =
            std::env::var("_APP_RUST_SEED_KEY").unwrap_or_else(|_| "appwrite-dev-key".to_string());
        state.seed_dev_project(&project_id, &key_secret, &["users.read", "users.write"]);
        println!(
            "appwrite-server: seeded dev project {project_id:?} with a users.read/users.write key"
        );
    }

    let (resources, mut platform) = appwrite_platform::build(state);

    let bind = std::env::var("APPWRITE_BIND").unwrap_or_else(|_| "0.0.0.0:80".into());
    let mut http = Http::new(HyperServer::bind(&bind, resources), "UTC");

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
    println!("appwrite-server listening on http://{bind} (/v1/health, /v1/users*)");
    http.start().await
}
