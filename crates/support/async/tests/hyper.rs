//! hyper served and fetched on the engine, through `utopia_async::hyper`.
#![cfg(feature = "hyper")]

use std::convert::Infallible;
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Empty, Full};
use hyper_util::client::legacy::Client;
use tower_service::Service;
use utopia_async::hyper::{Connector, Executor, Io, Timer};
use utopia_async::net::TcpListener;

#[utopia_async::test]
async fn serves_and_fetches() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let addr = listener.local_addr().unwrap();
    utopia_async::spawn(async move {
        loop {
            let (stream, _) = listener.accept().await.unwrap();
            let service = hyper::service::service_fn(|request: hyper::Request<hyper::body::Incoming>| async move {
                let path = request.uri().path().to_owned();
                Ok::<_, Infallible>(hyper::Response::new(Full::new(Bytes::from(format!("hello {path}")))))
            });
            utopia_async::spawn(
                hyper::server::conn::http1::Builder::new()
                    .timer(Timer)
                    .header_read_timeout(Duration::from_secs(5))
                    .serve_connection(Io::new(stream), service),
            );
        }
    });

    let mut connector = Connector::new();
    connector.set_nodelay(true);
    connector.set_connect_timeout(Some(Duration::from_secs(1)));
    connector.set_keepalive(Some(Duration::from_secs(60)));
    let client = Client::builder(Executor)
        .timer(Timer)
        .pool_timer(Timer)
        .pool_idle_timeout(Duration::from_millis(50))
        .build::<_, Empty<Bytes>>(connector);
    for path in ["/one", "/two"] {
        let response = client.get(format!("http://{addr}{path}").parse().unwrap()).await.unwrap();
        let body = response.into_body().collect().await.unwrap().to_bytes();
        assert_eq!(body, format!("hello {path}"));
    }
    // Idle pooled connections expire on the engine's timers.
    utopia_async::sleep(Duration::from_millis(100)).await;
}

#[utopia_async::test]
async fn connectors_check_the_uri() {
    let mut connector = Connector::new();
    let error = connector.call("https://example.com/".parse().unwrap()).await.unwrap_err();
    assert_eq!(error.to_string(), "invalid URL, scheme is not http");
    let error = connector.call("/relative".parse().unwrap()).await.unwrap_err();
    assert_eq!(error.to_string(), "invalid URL, scheme is missing");
}

#[utopia_async::test]
async fn connect_timeouts_apply() {
    let mut connector = Connector::new();
    // A non-routable address: the connection hangs until the timeout.
    connector.set_connect_timeout(Some(Duration::from_millis(20)));
    let error = connector.call("http://10.255.255.1/".parse().unwrap()).await.unwrap_err();
    assert!(
        matches!(
            error.kind(),
            std::io::ErrorKind::TimedOut | std::io::ErrorKind::NetworkUnreachable | std::io::ErrorKind::HostUnreachable
        ),
        "{error}"
    );
}
