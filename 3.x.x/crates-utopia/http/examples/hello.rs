use serde_json::json;
use utopia_http::prelude::*;

#[tokio::main]
async fn main() -> Result<()> {
    let resources = Container::new();
    let mut http = Http::new(HyperServer::bind("0.0.0.0:8080", resources), "UTC");

    http.get("/hello-world")?
        .desc("Hello World")
        .param(
            "name",
            json!("World"),
            Text::new(256),
            "Name to greet",
            true,
        )
        .inject("response")?
        .action(|ctx| async move {
            let name = ctx.param_str("name")?;
            ctx.response().json(&json!({ "Hello": name }))?;
            Ok(())
        });

    http.set_mode(Mode::Production);
    println!("Listening on http://0.0.0.0:8080/hello-world");
    http.start().await
}
