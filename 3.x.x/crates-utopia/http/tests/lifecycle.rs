use serde_json::json;
use std::sync::Arc;
use utopia_http::prelude::*;

#[tokio::test]
async fn execute_hello_json() {
    let resources = Container::new();
    let adapter = MemoryAdapter::new(resources);
    let http = Http::new(adapter, "UTC");
    http.get("/hello")
        .unwrap()
        .param("name", json!("World"), Text::new(64), "name", true)
        .action(|ctx| async move {
            let name = ctx.param_str("name")?;
            ctx.response().json(&json!({ "Hello": name }))?;
            Ok(())
        });

    let mut req = Request::new("GET", "/hello?name=Ada");
    req.parse_query_from_uri();
    let res = Response::new();
    http.execute(req, res.clone()).await.unwrap();
    assert!(res.is_sent());
    assert!(res.body_string().contains("Ada"));
}

#[tokio::test]
async fn not_found_sets_error_hook() {
    let resources = Container::new();
    let adapter = MemoryAdapter::new(resources);
    let mut http = Http::new(adapter, "UTC");
    http.on_error(|ctx| async move {
        let status = ctx.error().map_or(500, |e| e.status());
        ctx.response().set_status(status).unwrap();
        ctx.response().text("missing")?;
        Ok(())
    });

    let req = Request::new("GET", "/nope");
    let res = Response::new();
    http.execute(req, res.clone()).await.unwrap();
    assert_eq!(res.status_code(), 404);
    assert_eq!(res.body_string(), "missing");
}

#[tokio::test]
async fn init_and_shutdown_hooks_run() {
    let resources = Container::new();
    let adapter = MemoryAdapter::new(resources);
    let mut http = Http::new(adapter, "UTC");
    let flag = Arc::new(std::sync::atomic::AtomicUsize::new(0));
    let a = flag.clone();
    http.on_init(move |_ctx| {
        let a = a.clone();
        async move {
            a.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
            Ok(())
        }
    });
    let b = flag.clone();
    http.on_shutdown(move |_ctx| {
        let b = b.clone();
        async move {
            b.fetch_add(10, std::sync::atomic::Ordering::SeqCst);
            Ok(())
        }
    });
    http.get("/ok").unwrap().action(|ctx| async move {
        ctx.response().text("ok")?;
        Ok(())
    });

    let res = Response::new();
    http.execute(Request::new("GET", "/ok"), res.clone())
        .await
        .unwrap();
    assert_eq!(flag.load(std::sync::atomic::Ordering::SeqCst), 11);
    assert_eq!(res.body_string(), "ok");
}

#[tokio::test]
async fn run_serves_static_file() {
    let dir = tempfile::tempdir().unwrap();
    std::fs::write(dir.path().join("hi.txt"), b"static").unwrap();
    let resources = Container::new();
    let adapter = MemoryAdapter::new(resources);
    let mut http = Http::new(adapter, "UTC");
    http.load_files(dir.path()).unwrap();
    let res = Response::new();
    http.run(Request::new("GET", "/hi.txt"), res.clone())
        .await
        .unwrap();
    assert_eq!(res.body_string(), "static");
}
