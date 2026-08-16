//! Typed HTTP server configuration. Mirrors `src/Server/HTTP/Config.php`.

use std::future::Future;
use std::pin::Pin;
use std::sync::Arc;
use std::time::Duration;

use bytes::Bytes;
use http_body_util::Full;
use hyper::body::Incoming;
use hyper::{Request, Response};

use crate::adapter::Adapter;
use crate::error::ProxyError;

/// Boxed future type used by user-supplied async callbacks.
pub type BoxFuture<'a, T> = Pin<Box<dyn Future<Output = T> + Send + 'a>>;

/// Custom request handler signature. Given the incoming request and the shared
/// adapter, returns an HTTP response. Matches `Config::$requestHandler`.
pub type RequestHandler = Arc<
    dyn Fn(
            Request<Incoming>,
            Arc<Adapter>,
        ) -> BoxFuture<'static, Result<Response<Full<Bytes>>, ProxyError>>
        + Send
        + Sync,
>;

/// Worker-start hook. Invoked once per accept-loop before serving. Matches
/// `Config::$workerStart`.
pub type WorkerStart = Arc<dyn Fn(usize, Arc<Adapter>) -> BoxFuture<'static, ()> + Send + Sync>;

/// HTTP server configuration. All fields have sensible defaults via [`Default`];
/// override specific fields with the builder-style `with_*` methods.
#[derive(Clone)]
pub struct HttpConfig {
    pub host: String,
    pub port: u16,
    pub workers: usize,
    pub max_connections: usize,
    pub max_coroutine: usize,
    pub socket_buffer_size: usize,
    pub buffer_output_size: usize,
    pub enable_coroutine: bool,
    pub max_wait_time: u64,
    pub reactor_num: usize,
    pub dispatch_mode: u8,
    pub enable_reuse_port: bool,
    pub backlog: i32,
    pub parse_post: bool,
    pub parse_cookie: bool,
    pub parse_files: bool,
    pub compression: bool,
    pub timeout: Duration,
    pub connect_timeout: Duration,
    pub keep_alive: bool,
    pub pool_size: usize,
    pub pool_timeout: Duration,
    pub fast_path: bool,
    pub fast_path_assume_ok: bool,
    pub fixed_backend: Option<String>,
    pub direct_response: Option<String>,
    pub direct_response_status: u16,
    pub keepalive_timeout: u64,
    pub http_protocol: bool,
    pub http2_protocol: bool,
    pub max_request: u64,
    pub raw_backend: bool,
    pub raw_backend_assume_ok: bool,
    pub skip_validation: bool,
    pub cache_ttl: u64,
    pub request_handler: Option<RequestHandler>,
    pub worker_start: Option<WorkerStart>,
}

impl std::fmt::Debug for HttpConfig {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("HttpConfig")
            .field("host", &self.host)
            .field("port", &self.port)
            .field("workers", &self.workers)
            .finish_non_exhaustive()
    }
}

impl Default for HttpConfig {
    fn default() -> Self {
        let cpus = std::thread::available_parallelism()
            .map(|n| n.get())
            .unwrap_or(1);
        Self {
            host: "0.0.0.0".to_string(),
            port: 80,
            workers: 16,
            max_connections: 100_000,
            max_coroutine: 100_000,
            socket_buffer_size: 2 * 1024 * 1024,
            buffer_output_size: 2 * 1024 * 1024,
            enable_coroutine: true,
            max_wait_time: 60,
            reactor_num: cpus * 2,
            dispatch_mode: 2,
            enable_reuse_port: true,
            backlog: 65_535,
            parse_post: false,
            parse_cookie: false,
            parse_files: false,
            compression: false,
            timeout: Duration::from_secs(30),
            connect_timeout: Duration::from_secs(5),
            keep_alive: true,
            pool_size: 1024,
            pool_timeout: Duration::from_millis(1),
            fast_path: false,
            fast_path_assume_ok: false,
            fixed_backend: None,
            direct_response: None,
            direct_response_status: 200,
            keepalive_timeout: 60,
            http_protocol: true,
            http2_protocol: false,
            max_request: 0,
            raw_backend: false,
            raw_backend_assume_ok: false,
            skip_validation: false,
            cache_ttl: 60,
            request_handler: None,
            worker_start: None,
        }
    }
}

impl HttpConfig {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn with_host(mut self, host: impl Into<String>) -> Self {
        self.host = host.into();
        self
    }

    pub fn with_port(mut self, port: u16) -> Self {
        self.port = port;
        self
    }

    pub fn with_workers(mut self, workers: usize) -> Self {
        self.workers = workers;
        self
    }

    pub fn with_pool_size(mut self, size: usize) -> Self {
        self.pool_size = size;
        self
    }

    pub fn with_pool_timeout(mut self, timeout: Duration) -> Self {
        self.pool_timeout = timeout;
        self
    }

    pub fn with_timeout(mut self, timeout: Duration) -> Self {
        self.timeout = timeout;
        self
    }

    pub fn with_connect_timeout(mut self, timeout: Duration) -> Self {
        self.connect_timeout = timeout;
        self
    }

    pub fn with_fixed_backend(mut self, endpoint: impl Into<String>) -> Self {
        self.fixed_backend = Some(endpoint.into());
        self
    }

    pub fn with_direct_response(mut self, body: impl Into<String>, status: u16) -> Self {
        self.direct_response = Some(body.into());
        self.direct_response_status = status;
        self
    }

    pub fn with_raw_backend(mut self, raw: bool) -> Self {
        self.raw_backend = raw;
        self
    }

    pub fn with_skip_validation(mut self, skip: bool) -> Self {
        self.skip_validation = skip;
        self
    }

    pub fn with_cache_ttl(mut self, seconds: u64) -> Self {
        self.cache_ttl = seconds;
        self
    }

    pub fn with_enable_reuse_port(mut self, enable: bool) -> Self {
        self.enable_reuse_port = enable;
        self
    }

    pub fn with_http2(mut self, enable: bool) -> Self {
        self.http2_protocol = enable;
        self
    }

    pub fn with_request_handler(mut self, handler: RequestHandler) -> Self {
        self.request_handler = Some(handler);
        self
    }

    pub fn with_worker_start(mut self, hook: WorkerStart) -> Self {
        self.worker_start = Some(hook);
        self
    }
}
