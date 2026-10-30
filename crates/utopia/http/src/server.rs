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

use bytes::Bytes;
use http_body_util::BodyExt;
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
    let buffered = Arc::new(AtomicUsize::new(0));
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
        let buffered = buffered.clone();
        let mut stop = stop_rx.clone();
        utopia_async::spawn(async move {
            let keep_alive = options.keep_alive;
            let header_read_timeout = options.header_read_timeout;
            let service = service_fn(move |req: hyper::Request<Incoming>| {
                let handler = handler.clone();
                let options = options.clone();
                let in_flight = in_flight.clone();
                let buffered = buffered.clone();
                async move { Ok::<_, Infallible>(dispatch(&handler, req, remote, &options, &in_flight, &buffered).await) }
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
    buffered: &AtomicUsize,
) -> http::Response<Body> {
    let current = in_flight.fetch_add(1, Ordering::SeqCst);
    let _guard = InFlight(in_flight);
    let (parts, mut body) = req.into_parts();
    if current >= options.max_concurrency {
        // Read the body before answering, within bounds. Closing here makes
        // the client see a reset while it is still writing the upload, the
        // same failure a 413 used to cause in front of Traefik.
        drain(&mut body, 0, options).await;
        return busy();
    }
    let mut held = Held { buffered, bytes: 0 };
    let body = match collect_body(&mut body, options, &mut held).await {
        Collected::Body(collected) => collected,
        Collected::TooLarge(read) => {
            drop(held);
            drain(&mut body, read, options).await;
            // Read the body first. Closing early makes Traefik answer 502
            // instead of forwarding this 413, which is what Swoole returns.
            let mut r = Response::text_bytes(http::StatusCode::PAYLOAD_TOO_LARGE, "Request body too large");
            r.add_header("connection", "close");
            r.flush();
            return r.into_http();
        }
        Collected::Busy(read) => {
            drop(held);
            drain(&mut body, read, options).await;
            return busy();
        }
        Collected::Failed => return closed(),
    };
    let request = Request::from_http(&parts, body, Some(remote)).with_trusted(options.trusted.clone());
    handler.respond(request).await
}

/// The 503 of a busy server.
fn busy() -> http::Response<Body> {
    let mut r = Response::new();
    r.set_status(http::StatusCode::SERVICE_UNAVAILABLE);
    r.add_header("connection", "close");
    r.force_status();
    r.flush();
    r.into_http()
}

/// An answer to a client that sent its body too slowly or broke it off: the
/// connection closes.
fn closed() -> http::Response<Body> {
    let mut r = Response::new();
    r.set_status(http::StatusCode::REQUEST_TIMEOUT);
    r.add_header("connection", "close");
    r.force_status();
    r.flush();
    r.into_http()
}

/// What reading a request body gave.
enum Collected {
    /// The whole body, within every limit.
    Body(Bytes),
    /// Larger than `max_body`, after this many bytes.
    TooLarge(usize),
    /// Past the server-wide body memory, after this many bytes.
    Busy(usize),
    /// Broken off, or not sent in time.
    Failed,
}

/// Bytes of one body counted in the server-wide total until the request ends.
struct Held<'a> {
    buffered: &'a AtomicUsize,
    bytes: usize,
}

impl Held<'_> {
    /// Counts `bytes` more, unless that takes the total past `max`.
    fn reserve(&mut self, bytes: usize, max: usize) -> bool {
        let reserved = self.buffered.fetch_update(Ordering::SeqCst, Ordering::SeqCst, |total| {
            total.checked_add(bytes).filter(|&next| next <= max)
        });
        if reserved.is_ok() {
            self.bytes += bytes;
        }
        reserved.is_ok()
    }
}

impl Drop for Held<'_> {
    fn drop(&mut self) {
        self.buffered.fetch_sub(self.bytes, Ordering::SeqCst);
    }
}

/// The body, read within `max_body`, the server-wide body memory and
/// `body_read_timeout`.
async fn collect_body(body: &mut Incoming, options: &ServerOptions, held: &mut Held<'_>) -> Collected {
    let mut collected = Vec::new();
    let read = async {
        loop {
            match body.frame().await {
                Some(Ok(frame)) => {
                    let Ok(data) = frame.into_data() else { continue };
                    let read = collected.len().saturating_add(data.len());
                    if read > options.max_body {
                        return Collected::TooLarge(read);
                    }
                    if !held.reserve(data.len(), options.max_body_memory) {
                        return Collected::Busy(read);
                    }
                    collected.extend_from_slice(&data);
                }
                Some(Err(_)) => return Collected::Failed,
                None => return Collected::Body(Bytes::new()),
            }
        }
    };
    match utopia_async::timeout(options.body_read_timeout, read).await {
        Ok(Collected::Body(_)) => Collected::Body(Bytes::from(collected)),
        Ok(other) => other,
        Err(_) => Collected::Failed,
    }
}

/// Reads and drops what is left of a rejected body, `read` bytes in, until
/// it ends, `drain_limit` bytes were read or `drain_timeout` passed. Past a
/// bound the connection closes once the answer is written.
async fn drain(body: &mut Incoming, mut read: usize, options: &ServerOptions) {
    let rest = async {
        while read <= options.drain_limit {
            match body.frame().await {
                Some(Ok(frame)) => read = read.saturating_add(frame.data_ref().map_or(0, Bytes::len)),
                Some(Err(_)) | None => return,
            }
        }
    };
    let _ = utopia_async::timeout(options.drain_timeout, rest).await;
}
