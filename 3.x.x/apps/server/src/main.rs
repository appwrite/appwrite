//! Minimal Appwrite Rust server stub (Hyper via utopia-http).

use serde_json::json;
use utopia_di::Container;
use utopia_http::prelude::*;

#[tokio::main]
async fn main() -> Result<()> {
    let _platform = appwrite_platform::stub();
    let _ = utopia_platform::Module::new();

    let bind = std::env::var("APPWRITE_BIND").unwrap_or_else(|_| "0.0.0.0:80".into());
    let resources = Container::new();
    let mut http = Http::new(HyperServer::bind(&bind, resources), "UTC");

    http.get("/v1/health")?
        .desc("Health check")
        .inject("response")?
        .action(|ctx| async move {
            ctx.response().json(&json!({ "status": "pass" }))?;
            Ok(())
        });

    http.set_mode(Mode::Development);
    println!("appwrite-server listening on http://{bind}/v1/health");
    http.start().await
}
