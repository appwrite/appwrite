//! The HTTP/1.1 server on hyper: serves an application on a TCP socket.
//!
//! It caps the requests in flight server-wide (503 past the cap), drains
//! in-flight requests on shutdown up to a deadline, disables Nagle, leaves
//! compression to the application, sends header names in `Title-Case`, and
//! gives each request a fresh context whose parent is the application's
//! static resources.
//!
//! PHP: replaces the Swoole and FPM adapters, keeping the behaviour the
//! Swoole adapter defines (`max_concurrency`, `max_wait_time`).

mod app;
mod handler;
mod in_flight;
mod mode;
mod server_options;
mod settings;

use std::convert::Infallible;
use std::future::Future;
use std::net::SocketAddr;
use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::Duration;

use http_body_util::{BodyExt, Limited};
use hyper::body::Incoming;
use hyper::server::conn::http1;
use hyper::service::service_fn;
use utopia_async::hyper::{Io, Timer};
use utopia_async::net::TcpListener;
use utopia_async::sync::watch;

use crate::response::Body;
use crate::{Request, Response};

pub use app::App;
pub use handler::Handler;
use in_flight::InFlight;
pub use mode::Mode;
pub use server_options::ServerOptions;
pub use settings::Settings;

/// Serves `handler` on `addr` until `shutdown` resolves.
pub async fn serve<H, S>(addr: SocketAddr, handler: Arc<H>, options: ServerOptions, shutdown: S) -> std::io::Result<()>
where
    H: Handler,
    S: Future<Output = ()> + Send,
{
    let listener = TcpListener::bind(addr).await?;
    tracing::info!(%addr, "http server listening");
    serve_listener(listener, handler, options, shutdown).await
}

/// Serves `handler` on a bound listener until `shutdown` resolves.
pub async fn serve_listener<H, S>(
    listener: TcpListener,
    handler: Arc<H>,
    options: ServerOptions,
    shutdown: S,
) -> std::io::Result<()>
where
    H: Handler,
    S: Future<Output = ()> + Send,
{
    let (stop_tx, stop_rx) = watch::channel(false);
    let options = Arc::new(options);
    let in_flight = Arc::new(AtomicUsize::new(0));
    utopia_async::pin!(shutdown);

    loop {
        let (stream, remote) = utopia_async::select! {
            accepted = listener.accept() => match accepted {
                Ok(pair) => pair,
                Err(err) => {
                    tracing::warn!(%err, "accept failed");
                    utopia_async::sleep(Duration::from_millis(10)).await;
                    continue;
                }
            },
            _ = &mut shutdown => break,
        };
        let _ = stream.set_nodelay(true);
        let handler = handler.clone();
        let options = options.clone();
        let in_flight = in_flight.clone();
        let mut stop = stop_rx.clone();
        utopia_async::spawn(async move {
            let keep_alive = options.keep_alive;
            let header_read_timeout = options.header_read_timeout;
            let service = service_fn(move |req: hyper::Request<Incoming>| {
                let handler = handler.clone();
                let options = options.clone();
                let in_flight = in_flight.clone();
                async move { Ok::<_, Infallible>(dispatch(&handler, req, remote, &options, &in_flight).await) }
            });
            let conn = http1::Builder::new()
                .keep_alive(keep_alive)
                .title_case_headers(true)
                .timer(Timer)
                .header_read_timeout(header_read_timeout)
                .serve_connection(Io::new(stream), service);
            utopia_async::pin!(conn);
            utopia_async::select! {
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
    // Drain: wait for in-flight requests, up to the grace period.
    let deadline = std::time::Instant::now() + options.shutdown_grace;
    while in_flight.load(Ordering::SeqCst) > 0 && std::time::Instant::now() < deadline {
        utopia_async::sleep(Duration::from_millis(20)).await;
    }
    Ok(())
}

async fn dispatch<H: Handler>(
    handler: &Arc<H>,
    req: hyper::Request<Incoming>,
    remote: SocketAddr,
    options: &ServerOptions,
    in_flight: &AtomicUsize,
) -> http::Response<Body> {
    let current = in_flight.fetch_add(1, Ordering::SeqCst);
    let _guard = InFlight(in_flight);
    if current >= options.max_concurrency {
        let mut r = Response::new();
        r.set_status(http::StatusCode::SERVICE_UNAVAILABLE);
        r.force_status();
        return r.into_http();
    }
    let (parts, body) = req.into_parts();
    let body = match Limited::new(body, options.max_body).collect().await {
        Ok(collected) => collected.to_bytes(),
        Err(_) => {
            let mut r = Response::text_bytes(http::StatusCode::PAYLOAD_TOO_LARGE, "Request body too large");
            r.flush();
            return r.into_http();
        }
    };
    let request = Request::from_http(&parts, body, Some(remote)).with_trusted(options.trusted.clone());
    handler.respond(request).await
}
