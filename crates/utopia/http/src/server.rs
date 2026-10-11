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

use std::convert::Infallible;
use std::future::Future;
use std::net::SocketAddr;
use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Limited};
use hyper::body::Incoming;
use hyper::server::conn::http1;
use hyper::service::service_fn;
use hyper_util::rt::{TokioIo, TokioTimer};
use tokio::net::TcpListener;
use tokio::sync::watch;

use crate::http::Http;
use crate::response::{Body, Stream, head_response};
use crate::trusted::TrustedHeaders;
use crate::{Request, Response};

/// What the server runs for each request: turns a request into a response.
pub trait Handler: Send + Sync + 'static {
    /// The response to `request`.
    fn handle(&self, request: Request) -> impl Future<Output = Response> + Send;

    /// The hyper response for `request`. By default the handler's response,
    /// buffered; [`App`] streams.
    fn respond(self: &Arc<Self>, request: Request) -> impl Future<Output = http::Response<Body>> + Send {
        let this = self.clone();
        async move { this.handle(request).await.into_http() }
    }
}

/// Serves an [`Http`] application: each request runs [`Http::run`] in a
/// fresh context.
pub struct App(
    /// The application.
    pub Arc<Http>,
);

impl Handler for App {
    /// Runs the request through the application in a fresh context; a
    /// failure gives an empty 500.
    fn handle(&self, request: Request) -> impl Future<Output = Response> + Send {
        let http = self.0.clone();
        async move {
            let context = http.context();
            let mut response = Response::new();
            if let Err(error) = http.run(&request, &mut response, &context).await {
                tracing::error!(%error, class = error.php_class(), "request failed");
                let mut failed = Response::new();
                failed.set_status(http::StatusCode::INTERNAL_SERVER_ERROR);
                failed.force_status();
                return failed;
            }
            response
        }
    }

    /// Runs the request in its own task and answers as soon as the head is
    /// sent: chunks written with [`Response::chunk`] reach the client as
    /// they are written.
    fn respond(self: &Arc<Self>, request: Request) -> impl Future<Output = http::Response<Body>> + Send {
        let http = self.0.clone();
        async move {
            let (head_tx, head_rx) = tokio::sync::oneshot::channel();
            let (body_tx, mut body_rx) = tokio::sync::mpsc::unbounded_channel::<Bytes>();
            tokio::spawn(async move {
                let context = http.context();
                let mut response = Response::new();
                response.attach(Stream { head: Some(head_tx), body: Some(body_tx) });
                if let Err(error) = http.run(&request, &mut response, &context).await {
                    tracing::error!(%error, class = error.php_class(), "request failed");
                    if !response.wire().ended && response.wire().status.is_none() {
                        response.set_status(http::StatusCode::INTERNAL_SERVER_ERROR);
                        response.force_status();
                    }
                }
                response.finish();
            });
            match head_rx.await {
                Ok(head) if head.body.is_some() => {
                    let body = http_body_util::BodyExt::boxed(http_body_util::Full::new(head.body.unwrap_or_default()));
                    head_response(head.status, &head.headers, &head.cookies, body)
                }
                Ok(head) => {
                    let frames = futures_util::stream::unfold(body_rx, |mut rx| async move {
                        rx.recv().await.map(|chunk| (Ok::<_, Infallible>(hyper::body::Frame::data(chunk)), rx))
                    });
                    let body = http_body_util::BodyExt::boxed(http_body_util::StreamBody::new(frames));
                    head_response(head.status, &head.headers, &head.cookies, body)
                }
                Err(_) => {
                    body_rx.close();
                    let mut failed = Response::new();
                    failed.set_status(http::StatusCode::INTERNAL_SERVER_ERROR);
                    failed.force_status();
                    failed.into_http()
                }
            }
        }
    }
}

/// The two server shapes: many single-request workers, or a few concurrent
/// ones.
///
/// PHP: `Utopia\Http\Adapter\Swoole\Mode`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Mode {
    /// One request at a time per worker, many workers.
    ///
    /// PHP: `HYPERLOOP_A`.
    HyperloopA,
    /// Concurrent requests per worker, one worker per core.
    ///
    /// PHP: `HYPERLOOP_B`.
    HyperloopB,
}

/// The server settings of a [`Mode`], with the CPU count limited by the
/// cgroup quota. [`ServerOptions`] can be built from them.
///
/// PHP: `Mode::settings()`, the Swoole server settings.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Settings {
    /// Server-side compression (off: the application compresses).
    pub compression: bool,
    /// Whether Nagle's algorithm is disabled on connections.
    pub tcp_nodelay: bool,
    /// Whether TCP Fast Open is enabled.
    pub tcp_fastopen: bool,
    /// Seconds `accept()` waits for the first bytes.
    pub tcp_defer_accept: u32,
    /// Whether several processes may bind the same port.
    pub reuse_port: bool,
    /// Seconds in-flight requests get to finish on shutdown.
    pub max_wait_time: u64,
    /// Requests in flight before the server answers 503.
    pub max_concurrency: usize,
    /// Whether workers reload one at a time, finishing their requests first.
    pub reload_async: bool,
    /// Event-loop threads: one per CPU.
    pub reactors: usize,
    /// Worker processes.
    pub workers: usize,
    /// Whether a worker serves requests concurrently.
    pub coroutine: bool,
    /// How connections reach workers: 3 to an idle worker, 2 by connection.
    pub dispatch_mode: u8,
    /// Whether blocking I/O yields to other requests.
    ///
    /// PHP: `hook_flags = SWOOLE_HOOK_ALL`.
    pub hook_all: Option<bool>,
    /// Whether a send waits for buffer space instead of failing.
    ///
    /// PHP: Swoole's `send_yield`.
    pub send_yield: Option<bool>,
    /// Requests before a worker is recycled.
    pub max_request: Option<u64>,
    /// Threads for asynchronous file I/O.
    ///
    /// PHP: Swoole's `aio_worker_num`.
    pub aio_workers: Option<usize>,
    /// Asynchronous I/O threads kept alive when idle.
    ///
    /// PHP: Swoole's `aio_core_worker_num`.
    pub aio_core_workers: Option<usize>,
}

impl Mode {
    /// The server settings of this mode, sized by the available CPUs.
    ///
    /// PHP: `$mode->settings()`.
    pub fn settings(self) -> Settings {
        // `(int) max(1, ceil(System::getCPU() * n))`: the cgroup-aware CPU
        // count, multiplied before rounding up.
        let cpu = utopia_system::System::new().cpu().unwrap_or(1.0);
        let scaled = |n: f64| (cpu * n).ceil().max(1.0) as usize;
        let cpus = scaled(1.0);
        let base = Settings {
            compression: false,
            tcp_nodelay: true,
            tcp_fastopen: true,
            tcp_defer_accept: 1,
            reuse_port: true,
            max_wait_time: 30,
            max_concurrency: 1_000,
            reload_async: true,
            reactors: cpus,
            workers: cpus,
            coroutine: true,
            dispatch_mode: 2,
            hook_all: None,
            send_yield: None,
            max_request: None,
            aio_workers: None,
            aio_core_workers: None,
        };
        match self {
            Mode::HyperloopA => Settings { coroutine: false, dispatch_mode: 3, workers: scaled(6.0), ..base },
            Mode::HyperloopB => Settings {
                coroutine: true,
                hook_all: Some(true),
                dispatch_mode: 2,
                send_yield: Some(true),
                workers: cpus,
                max_request: Some(10_000),
                aio_workers: Some(scaled(8.0)),
                aio_core_workers: Some(cpus),
                ..base
            },
        }
    }
}

/// Server tuning for [`serve`].
#[derive(Debug, Clone)]
pub struct ServerOptions {
    /// Maximum accepted request body size in bytes.
    pub max_body: usize,
    /// Keep-alive connections.
    pub keep_alive: bool,
    /// Header read timeout (slowloris protection).
    pub header_read_timeout: Duration,
    /// Time allowed for in-flight connections after a shutdown signal (`max_wait_time`).
    pub shutdown_grace: Duration,
    /// Requests in flight before new ones get a 503 (`max_concurrency`).
    pub max_concurrency: usize,
    /// Which forwarded headers requests believe.
    pub trusted: Arc<TrustedHeaders>,
}

impl Default for ServerOptions {
    /// 32 MiB bodies, keep-alive, 30 seconds to read headers and to drain on
    /// shutdown, 1000 requests in flight, `x-forwarded-proto` trusted.
    fn default() -> Self {
        Self {
            max_body: 32 * 1024 * 1024,
            keep_alive: true,
            header_read_timeout: Duration::from_secs(30),
            shutdown_grace: Duration::from_secs(30),
            max_concurrency: 1_000,
            trusted: Arc::new(TrustedHeaders::default()),
        }
    }
}

impl From<&Settings> for ServerOptions {
    /// The default options with the drain deadline and the concurrency cap
    /// of `settings`.
    fn from(settings: &Settings) -> Self {
        Self {
            shutdown_grace: Duration::from_secs(settings.max_wait_time),
            max_concurrency: settings.max_concurrency,
            ..Self::default()
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
        let in_flight = in_flight.clone();
        let mut stop = stop_rx.clone();
        tokio::spawn(async move {
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
    // Drain: wait for in-flight requests, up to the grace period.
    let deadline = tokio::time::Instant::now() + options.shutdown_grace;
    while in_flight.load(Ordering::SeqCst) > 0 && tokio::time::Instant::now() < deadline {
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    Ok(())
}

/// Decrements the in-flight counter when a request ends.
struct InFlight<'a>(&'a AtomicUsize);

impl Drop for InFlight<'_> {
    /// Decrements the counter.
    fn drop(&mut self) {
        self.0.fetch_sub(1, Ordering::SeqCst);
    }
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
