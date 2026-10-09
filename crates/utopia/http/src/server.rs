use std::future::Future;
use std::net::SocketAddr;
use std::sync::Arc;
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Full, Limited};
use hyper::body::Incoming;
use hyper::server::conn::http1;
use hyper::service::service_fn;
use hyper_util::rt::{TokioIo, TokioTimer};
use tokio::net::TcpListener;
use tokio::sync::watch;

use crate::{Request, Response};

/// Application entry point.
pub trait Handler: Send + Sync + 'static {
    fn handle(&self, request: Request) -> impl Future<Output = Response> + Send;
}

/// Server tuning.
#[derive(Debug, Clone)]
pub struct ServerOptions {
    /// Maximum accepted request body size in bytes.
    pub max_body: usize,
    /// Keep-alive connections.
    pub keep_alive: bool,
    /// Header read timeout (slowloris protection).
    pub header_read_timeout: Duration,
    /// Time allowed for in-flight connections after a shutdown signal.
    pub shutdown_grace: Duration,
}

impl Default for ServerOptions {
    fn default() -> Self {
        Self {
            max_body: 32 * 1024 * 1024,
            keep_alive: true,
            header_read_timeout: Duration::from_secs(30),
            shutdown_grace: Duration::from_secs(10),
        }
    }
}

/// Serves `handler` on `addr` until `shutdown` resolves.
pub async fn serve<H, S>(addr: SocketAddr, handler: Arc<H>, options: ServerOptions, shutdown: S) -> std::io::Result<()>
where
    H: Handler,
    S: Future<Output = ()> + Send,
{
    let listener = TcpListener::bind(addr).await?;
    tracing::info!(%addr, "http server listening");
    let (stop_tx, stop_rx) = watch::channel(false);
    let options = Arc::new(options);
    tokio::pin!(shutdown);

    loop {
        let (stream, remote) = tokio::select! {
            accepted = listener.accept() => match accepted {
                Ok(pair) => pair,
                Err(err) => {
                    tracing::warn!(%err, "accept failed");
                    tokio::time::sleep(Duration::from_millis(10)).await;
                    continue;
                }
            },
            _ = &mut shutdown => break,
        };
        let _ = stream.set_nodelay(true);
        let handler = handler.clone();
        let options = options.clone();
        let mut stop = stop_rx.clone();
        tokio::spawn(async move {
            let keep_alive = options.keep_alive;
            let header_read_timeout = options.header_read_timeout;
            let service = service_fn(move |req: hyper::Request<Incoming>| {
                let handler = handler.clone();
                let options = options.clone();
                async move { Ok::<_, std::convert::Infallible>(dispatch(&*handler, req, remote, &options).await) }
            });
            let conn = http1::Builder::new()
                .keep_alive(keep_alive)
                .timer(TokioTimer::new())
                .header_read_timeout(header_read_timeout)
                .serve_connection(TokioIo::new(stream), service);
            tokio::pin!(conn);
            tokio::select! {
                result = conn.as_mut() => {
                    if let Err(err) = result {
                        tracing::debug!(%err, "connection closed with error");
                    }
                }
                _ = stop.changed() => {
                    conn.as_mut().graceful_shutdown();
                    let _ = conn.await;
                }
            }
        });
    }

    let _ = stop_tx.send(true);
    tokio::time::sleep(options.shutdown_grace.min(Duration::from_millis(500))).await;
    Ok(())
}

async fn dispatch<H: Handler>(
    handler: &H,
    req: hyper::Request<Incoming>,
    remote: SocketAddr,
    options: &ServerOptions,
) -> http::Response<Full<Bytes>> {
    let (parts, body) = req.into_parts();
    let body = match Limited::new(body, options.max_body).collect().await {
        Ok(collected) => collected.to_bytes(),
        Err(_) => {
            return Response::text(http::StatusCode::PAYLOAD_TOO_LARGE, "Request body too large").into_http();
        }
    };
    let is_head = parts.method == http::Method::HEAD;
    let request = Request::new(
        parts.method,
        parts.uri.path().to_owned(),
        parts.uri.query().map(str::to_owned),
        parts.version,
        parts.headers,
        body,
        remote.ip(),
    );
    let mut response = handler.handle(request).await;
    if is_head {
        response.head = true;
    }
    response.into_http()
}
