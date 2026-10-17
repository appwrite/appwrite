//! The hyper server end to end: an [`Http`] application on a socket.

use std::sync::Arc;
use std::time::Duration;

use utopia_async::io::{AsyncReadExt, AsyncWriteExt};
use utopia_async::net::{TcpListener, TcpStream};
use utopia_http::{App, Cookie, Http, Param, ServerOptions, serve_listener};

fn app() -> Http {
    let mut http = Http::new("UTC");
    let _ = http.get("/").unwrap().inject("response").action(async |s| {
        s.response.add_cookie(Cookie { name: "Session".into(), value: Some("a b".into()), ..Default::default() });
        s.response.text("Hello World!");
    });
    let _ = http
        .get("/value/:value")
        .unwrap()
        .param("value", Param::new(serde_json::Value::from(""), utopia_validators::Text::new(64), ""))
        .inject("response")
        .action(async |s| {
            let v = s.param("value").and_then(|v| v.as_str()).unwrap_or("").to_owned();
            s.response.send(v.as_bytes());
        });
    let _ = http.get("/chunked").unwrap().inject("response").action(async |s| {
        let _ = s.response.chunk(b"Hello ", false);
        utopia_async::sleep(Duration::from_millis(50)).await;
        let _ = s.response.chunk(b"World!", true);
    });
    let _ = http.get("/aborted").unwrap().inject("response").action(async |s| {
        let _ = s.response.chunk(b"prefix", false);
        s.response.abort();
        let _ = s.response.chunk(b"ignored", true);
    });
    let _ = http.get("/empty-final").unwrap().inject("response").action(async |s| {
        let _ = s.response.chunk(b"prefix", false);
        let _ = s.response.chunk(b"", true);
    });
    let _ = http.get("/slow").unwrap().inject("response").action(async |s| {
        utopia_async::sleep(Duration::from_millis(300)).await;
        s.response.send(b"slow");
    });
    let _ = http.get("/silent").unwrap();
    http
}

async fn request(port: u16, raw: impl AsRef<str>) -> String {
    let raw = raw.as_ref();
    let mut stream = TcpStream::connect(("127.0.0.1", port)).await.unwrap();
    stream.write_all(raw.as_bytes()).await.unwrap();
    let mut out = Vec::new();
    // An aborted response may end in a reset: what arrived before it counts.
    let mut buffer = [0u8; 4096];
    while let Ok(read) = stream.read(&mut buffer).await {
        if read == 0 {
            break;
        }
        out.extend_from_slice(&buffer[..read]);
    }
    String::from_utf8_lossy(&out).into_owned()
}

#[utopia_async::test(threads = 2)]
async fn serves_an_application() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let options = ServerOptions { max_concurrency: 2, ..ServerOptions::default() };
    let (stop, stopped) = utopia_async::sync::oneshot::channel::<()>();
    let server = utopia_async::spawn(serve_listener(listener, Arc::new(App(Arc::new(app()))), options, async {
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

    let aborted = request(port, &get("/aborted")).await;
    assert!(aborted.contains("6\r\nprefix\r\n"), "{aborted}");
    assert!(!aborted.contains("0\r\n\r\n") && !aborted.contains("ignored"), "no terminal chunk: {aborted}");

    let empty_final = request(port, &get("/empty-final")).await;
    assert!(empty_final.contains("6\r\nprefix\r\n0\r\n\r\n"), "{empty_final}");

    let head = request(port, "HEAD / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n").await;
    assert!(head.starts_with("HTTP/1.1 200 OK\r\n") && !head.contains("Hello"), "{head}");

    let missing = request(port, &get("/missing")).await;
    assert!(missing.starts_with("HTTP/1.1 200 OK\r\n"), "no error hook: Swoole ends the response: {missing}");

    let silent = request(port, &get("/silent")).await;
    assert!(silent.starts_with("HTTP/1.1 200 OK\r\n"), "{silent}");

    // Past max_concurrency, requests get a 503.
    let slow = utopia_async::spawn(request(port, get("/slow")));
    let slow2 = utopia_async::spawn(request(port, get("/slow")));
    utopia_async::sleep(Duration::from_millis(100)).await;
    let busy = request(port, &get("/")).await;
    assert!(busy.starts_with("HTTP/1.1 503 Service Unavailable\r\n"), "{busy}");
    assert!(slow.await.unwrap().ends_with("slow"));
    assert!(slow2.await.unwrap().ends_with("slow"));

    let _ = stop.send(());
    server.await.unwrap().unwrap();
}
