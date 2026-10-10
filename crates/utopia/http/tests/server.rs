//! The hyper server end to end: an [`Http`] application on a socket.

use std::sync::Arc;
use std::time::Duration;

use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use utopia_http::{App, Cookie, Http, Param, ServerOptions, serve_listener};

fn app() -> Http {
    let mut http = Http::new("UTC");
    let _ = http.get("/").unwrap().inject("response").action(|s| {
        Box::pin(async move {
            s.response.add_cookie(Cookie { name: "Session".into(), value: Some("a b".into()), ..Default::default() });
            s.response.text("Hello World!");
            Ok(())
        })
    });
    let _ = http
        .get("/value/:value")
        .unwrap()
        .param("value", Param::new(serde_json::Value::from(""), utopia_validators::Text::new(64), ""))
        .inject("response")
        .action(|s| {
            Box::pin(async move {
                let v = s.param("value").and_then(|v| v.as_str()).unwrap_or("").to_owned();
                s.response.send(v.as_bytes());
                Ok(())
            })
        });
    let _ = http.get("/chunked").unwrap().inject("response").action(|s| {
        Box::pin(async move {
            s.response.chunk(b"Hello ", false);
            tokio::time::sleep(Duration::from_millis(50)).await;
            s.response.chunk(b"World!", true);
            Ok(())
        })
    });
    let _ = http.get("/slow").unwrap().inject("response").action(|s| {
        Box::pin(async move {
            tokio::time::sleep(Duration::from_millis(300)).await;
            s.response.send(b"slow");
            Ok(())
        })
    });
    let _ = http.get("/silent").unwrap();
    http
}

async fn request(port: u16, raw: impl AsRef<str>) -> String {
    let raw = raw.as_ref();
    let mut stream = TcpStream::connect(("127.0.0.1", port)).await.unwrap();
    stream.write_all(raw.as_bytes()).await.unwrap();
    let mut out = Vec::new();
    stream.read_to_end(&mut out).await.unwrap();
    String::from_utf8_lossy(&out).into_owned()
}

#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn serves_an_application() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let options = ServerOptions { max_concurrency: 2, ..ServerOptions::default() };
    let (stop, stopped) = tokio::sync::oneshot::channel::<()>();
    let server = tokio::spawn(serve_listener(listener, Arc::new(App(Arc::new(app()))), options, async {
        let _ = stopped.await;
    }));

    let get = |path: &str| format!("GET {path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

    let hello = request(port, &get("/")).await;
    assert!(hello.starts_with("HTTP/1.1 200 OK\r\n"), "{hello}");
    assert!(hello.contains("Content-Type: text/plain; charset=UTF-8\r\n"), "title-case names: {hello}");
    assert!(hello.contains("Set-Cookie: session=a+b\r\n"), "{hello}");
    assert!(hello.contains("X-Debug-Speed: "), "{hello}");
    assert!(hello.ends_with("Hello World!"), "{hello}");

    let value = request(port, &get("/value/123")).await;
    assert!(value.ends_with("\r\n\r\n123"), "{value}");

    let chunked = request(port, &get("/chunked")).await;
    assert!(chunked.contains("Transfer-Encoding: chunked\r\n"), "{chunked}");
    assert!(chunked.contains("6\r\nHello \r\n6\r\nWorld!\r\n0\r\n\r\n"), "{chunked}");

    let head = request(port, "HEAD / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n").await;
    assert!(head.starts_with("HTTP/1.1 200 OK\r\n") && !head.contains("Hello"), "{head}");

    let missing = request(port, &get("/missing")).await;
    assert!(missing.starts_with("HTTP/1.1 200 OK\r\n"), "no error hook: Swoole ends the response: {missing}");

    let silent = request(port, &get("/silent")).await;
    assert!(silent.starts_with("HTTP/1.1 200 OK\r\n"), "{silent}");

    // Past max_concurrency, requests get a 503.
    let slow = tokio::spawn(request(port, get("/slow")));
    let slow2 = tokio::spawn(request(port, get("/slow")));
    tokio::time::sleep(Duration::from_millis(100)).await;
    let busy = request(port, &get("/")).await;
    assert!(busy.starts_with("HTTP/1.1 503 Service Unavailable\r\n"), "{busy}");
    assert!(slow.await.unwrap().ends_with("slow"));
    assert!(slow2.await.unwrap().ends_with("slow"));

    let _ = stop.send(());
    server.await.unwrap().unwrap();
}
