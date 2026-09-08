//! Integration tests for the HTTP proxy server.
//!
//! Each test spins up a tiny hyper backend and an [`HttpServer`] in front of it,
//! then exercises the proxy via raw TCP (`hyper::client::conn`) so we can observe
//! both the bytes on the wire and the pooling behaviour.

use std::convert::Infallible;
use std::net::SocketAddr;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::Duration;

use async_trait::async_trait;
use bytes::Bytes;
use http_body_util::{BodyExt, Empty, Full};
use hyper::body::Incoming;
use hyper::client::conn::http1 as client_http1;
use hyper::server::conn::http1 as server_http1;
use hyper::service::service_fn;
use hyper::{Request, Response, StatusCode};
use hyper_util::rt::TokioIo;
use tokio::net::{TcpListener, TcpStream};

use utopia_proxy::resolver::{Resolver, ResolverError, ResolverResult};
use utopia_proxy::server::http::config::HttpConfig;
use utopia_proxy::server::http::server::HttpServer;
use utopia_proxy::Fixed;

/// Spawn a hyper backend that echoes the request path in the body and tracks
/// how many fresh TCP accepts it has seen.
async fn spawn_backend() -> (SocketAddr, Arc<AtomicUsize>) {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let address = listener.local_addr().unwrap();
    let counter = Arc::new(AtomicUsize::new(0));
    let counter_clone = Arc::clone(&counter);

    tokio::spawn(async move {
        loop {
            let (stream, _) = match listener.accept().await {
                Ok(pair) => pair,
                Err(_) => continue,
            };
            counter_clone.fetch_add(1, Ordering::SeqCst);
            let io = TokioIo::new(stream);
            tokio::spawn(async move {
                let _ = server_http1::Builder::new()
                    .keep_alive(true)
                    .serve_connection(
                        io,
                        service_fn(|request: Request<Incoming>| async move {
                            let path = request.uri().path().to_string();
                            let response = Response::builder()
                                .status(StatusCode::OK)
                                .header("content-type", "text/plain")
                                .body(Full::new(Bytes::from(path)))
                                .unwrap();
                            Ok::<_, Infallible>(response)
                        }),
                    )
                    .await;
            });
        }
    });

    (address, counter)
}

async fn spawn_proxy(config: HttpConfig, resolver: Option<Arc<dyn Resolver>>) -> SocketAddr {
    let listener = TcpListener::bind(format!("{}:0", config.host))
        .await
        .unwrap();
    let address = listener.local_addr().unwrap();
    drop(listener);

    let config = HttpConfig {
        port: address.port(),
        workers: 1,
        enable_reuse_port: false,
        cache_ttl: 0,
        ..config
    };
    let server = HttpServer::new(resolver, config);
    tokio::spawn(async move {
        let _ = server.start().await;
    });

    // Wait briefly for the listener to come up.
    for _ in 0..50 {
        if TcpStream::connect(address).await.is_ok() {
            return address;
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    panic!("proxy never came up on {address}");
}

struct PanicResolver;

#[async_trait]
impl Resolver for PanicResolver {
    async fn resolve(&self, _data: &[u8]) -> Result<ResolverResult, ResolverError> {
        panic!("resolver must not be called");
    }
}

async fn send_request(
    address: SocketAddr,
    method: &str,
    path: &str,
    host: &str,
) -> (StatusCode, Bytes) {
    let stream = TcpStream::connect(address).await.unwrap();
    let io = TokioIo::new(stream);
    let (mut sender, connection) = client_http1::handshake::<_, Empty<Bytes>>(io)
        .await
        .unwrap();

    tokio::spawn(async move {
        let _ = connection.await;
    });

    let request = Request::builder()
        .method(method)
        .uri(path)
        .header("host", host)
        .body(Empty::<Bytes>::new())
        .unwrap();

    let response = sender.send_request(request).await.unwrap();
    let status = response.status();
    let body = response.into_body().collect().await.unwrap().to_bytes();
    (status, body)
}

#[tokio::test]
async fn proxies_get_to_backend() {
    let (backend_address, _counter) = spawn_backend().await;
    let resolver: Arc<dyn Resolver> = Arc::new(Fixed::new(backend_address.to_string()));

    let config = HttpConfig::default()
        .with_host("127.0.0.1")
        .with_skip_validation(true);
    let proxy_address = spawn_proxy(config, Some(resolver)).await;

    let (status, body) = send_request(proxy_address, "GET", "/foo", "proxy.local").await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(&body[..], b"/foo");
}

#[tokio::test]
async fn direct_response_short_circuits() {
    let config = HttpConfig::default()
        .with_host("127.0.0.1")
        .with_direct_response("hello", 201);

    // Resolver should never be touched when direct_response is set.
    let resolver: Arc<dyn Resolver> = Arc::new(PanicResolver);
    let proxy_address = spawn_proxy(config, Some(resolver)).await;

    let (status, body) = send_request(proxy_address, "GET", "/anything", "proxy.local").await;
    assert_eq!(status.as_u16(), 201);
    assert_eq!(&body[..], b"hello");
}

#[tokio::test]
async fn fixed_backend_skips_resolver() {
    let (backend_address, _counter) = spawn_backend().await;

    let config = HttpConfig::default()
        .with_host("127.0.0.1")
        .with_fixed_backend(backend_address.to_string())
        .with_skip_validation(true);
    let resolver: Arc<dyn Resolver> = Arc::new(PanicResolver);
    let proxy_address = spawn_proxy(config, Some(resolver)).await;

    let (status, body) = send_request(proxy_address, "GET", "/fixed", "proxy.local").await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(&body[..], b"/fixed");
}

#[tokio::test]
async fn pool_reuses_backend_connections() {
    let (backend_address, counter) = spawn_backend().await;
    let resolver: Arc<dyn Resolver> = Arc::new(Fixed::new(backend_address.to_string()));

    let config = HttpConfig::default()
        .with_host("127.0.0.1")
        .with_skip_validation(true)
        .with_pool_size(4)
        .with_pool_timeout(Duration::from_millis(50));
    let proxy_address = spawn_proxy(config, Some(resolver)).await;

    for index in 0..10 {
        let path = format!("/n{index}");
        let (status, body) = send_request(proxy_address, "GET", &path, "proxy.local").await;
        assert_eq!(status, StatusCode::OK);
        assert_eq!(&body[..], path.as_bytes());
    }

    let opens = counter.load(Ordering::SeqCst);
    assert!(
        opens <= 4,
        "expected backend opens to stay within pool capacity, saw {opens}"
    );
    assert!(opens >= 1, "expected at least one backend connection");
}

#[tokio::test]
async fn missing_host_header_returns_400() {
    let config = HttpConfig::default().with_host("127.0.0.1");
    let resolver: Arc<dyn Resolver> = Arc::new(Fixed::new("127.0.0.1:1"));
    let proxy_address = spawn_proxy(config, Some(resolver)).await;

    let stream = TcpStream::connect(proxy_address).await.unwrap();
    let io = TokioIo::new(stream);
    let (mut sender, connection) = client_http1::handshake::<_, Empty<Bytes>>(io)
        .await
        .unwrap();
    tokio::spawn(async move {
        let _ = connection.await;
    });
    // Hyper always sets a Host header for us, so send an empty one instead.
    let request = Request::builder()
        .method("GET")
        .uri("/")
        .header("host", "")
        .body(Empty::<Bytes>::new())
        .unwrap();
    let response = sender.send_request(request).await.unwrap();
    assert_eq!(response.status(), StatusCode::BAD_REQUEST);
}
