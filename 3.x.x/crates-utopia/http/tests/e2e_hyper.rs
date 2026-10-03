use serde_json::json;
use std::net::SocketAddr;
use std::time::Duration;
use utopia_http::prelude::*;

#[tokio::test]
async fn hyper_hello_world() {
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let addr = listener.local_addr().unwrap();
    drop(listener);

    let resources = Container::new();
    let http = Http::new(HyperServer::bind(addr.to_string(), resources), "UTC");
    http.get("/hello")
        .unwrap()
        .param("name", json!("World"), Text::new(64), "name", true)
        .action(|ctx| async move {
            let name = ctx.param_str("name")?;
            ctx.response().json(&json!({ "Hello": name }))?;
            Ok(())
        });

    tokio::spawn(async move {
        let _ = http.start().await;
    });

    // Wait briefly for bind.
    tokio::time::sleep(Duration::from_millis(100)).await;
    let url = format!("http://{addr}/hello?name=Rust");
    let body = reqwest::get(&url).await.unwrap().text().await.unwrap();
    assert!(body.contains("Rust"), "body={body}");
}

#[tokio::test]
async fn hyper_404() {
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let addr: SocketAddr = listener.local_addr().unwrap();
    drop(listener);

    let resources = Container::new();
    let mut http = Http::new(HyperServer::bind(addr.to_string(), resources), "UTC");
    http.on_error(|ctx| async move {
        ctx.response().set_status(404).unwrap();
        ctx.response().text("nf")?;
        Ok(())
    });
    http.get("/ok").unwrap().action(|ctx| async move {
        ctx.response().text("ok")?;
        Ok(())
    });

    tokio::spawn(async move {
        let _ = http.start().await;
    });
    tokio::time::sleep(Duration::from_millis(100)).await;
    let status = reqwest::get(format!("http://{addr}/missing"))
        .await
        .unwrap()
        .status();
    assert_eq!(status.as_u16(), 404);
}
