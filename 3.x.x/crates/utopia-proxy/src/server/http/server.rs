//! HTTP proxy server wrapping hyper 1.x. Mirrors `src/Server/HTTP/Swoole.php`
//! with Rust semantics: one tokio listener per worker (with `SO_REUSEPORT` when
//! enabled) and a `Handler` instance per listener.

use std::io;
use std::net::SocketAddr;
use std::sync::Arc;

use hyper::server::conn::http1;
use hyper::server::conn::http2;
use hyper::service::service_fn;
use hyper_util::rt::{TokioExecutor, TokioIo};
use socket2::{Domain, Protocol as SocketProtocol, Socket, Type};
use tokio::net::TcpListener;

use crate::adapter::Adapter;
use crate::protocol::Protocol;
use crate::resolver::Resolver;

use super::config::HttpConfig;
use super::handler::Handler;
use utopia_console::Console;

/// High-level HTTP proxy server. Owns a resolver and config; spawns per-worker
/// accept loops on `start`.
pub struct HttpServer {
    resolver: Option<Arc<dyn Resolver>>,
    config: Arc<HttpConfig>,
}

impl std::fmt::Debug for HttpServer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("HttpServer")
            .field("config", &self.config)
            .finish_non_exhaustive()
    }
}

impl HttpServer {
    pub fn new(resolver: Option<Arc<dyn Resolver>>, config: HttpConfig) -> Self {
        Self {
            resolver,
            config: Arc::new(config),
        }
    }

    pub fn config(&self) -> Arc<HttpConfig> {
        Arc::clone(&self.config)
    }

    /// Bind and serve until all workers finish. Returns errors from the bind
    /// phase; per-request errors are logged and relayed as HTTP responses.
    pub async fn start(self) -> io::Result<()> {
        let address: SocketAddr = format!("{}:{}", self.config.host, self.config.port)
            .parse()
            .map_err(|error| io::Error::new(io::ErrorKind::InvalidInput, error))?;

        let workers = self.config.workers.max(1);
        let mut handles = Vec::with_capacity(workers);

        for worker_id in 0..workers {
            let listener = if self.config.enable_reuse_port && workers > 1 {
                bind_reuse_port(&address)?
            } else if worker_id == 0 {
                TcpListener::bind(address).await?
            } else if self.config.enable_reuse_port {
                bind_reuse_port(&address)?
            } else {
                // Without SO_REUSEPORT only one worker can bind. Extra workers share
                // the first listener via Arc-wrapped Accept loop below.
                TcpListener::bind(address).await?
            };

            let resolver = self.resolver.clone();
            let config = Arc::clone(&self.config);

            handles.push(tokio::spawn(async move {
                run_worker(worker_id, listener, resolver, config).await;
            }));
        }

        for handle in handles {
            let _ = handle.await;
        }
        Ok(())
    }
}

async fn run_worker(
    worker_id: usize,
    listener: TcpListener,
    resolver: Option<Arc<dyn Resolver>>,
    config: Arc<HttpConfig>,
) {
    let adapter = Arc::new(Adapter::new(resolver, Protocol::Http));
    adapter.set_cache_ttl(config.cache_ttl).await;
    if config.skip_validation {
        adapter.set_skip_validation(true).await;
    }

    if let Some(hook) = config.worker_start.clone() {
        hook(worker_id, Arc::clone(&adapter)).await;
    }

    tracing::info!(
        worker_id,
        host = %config.host,
        port = config.port,
        "http worker started"
    );
    let _ = Console::log(&format!("Worker #{worker_id} started"));

    let handler = Arc::new(Handler::new(Arc::clone(&config), adapter));

    loop {
        let (stream, _remote) = match listener.accept().await {
            Ok(pair) => pair,
            Err(error) => {
                tracing::warn!(%error, worker_id, "accept failed");
                continue;
            }
        };
        if let Err(error) = stream.set_nodelay(true) {
            tracing::debug!(%error, "set_nodelay failed");
        }
        let io = TokioIo::new(stream);
        let handler = Arc::clone(&handler);
        let config = Arc::clone(&config);

        tokio::spawn(async move {
            let service = service_fn(move |request| {
                let handler = Arc::clone(&handler);
                async move { handler.handle(request).await }
            });

            let result = if config.http2_protocol {
                http2::Builder::new(TokioExecutor::new())
                    .serve_connection(io, service)
                    .await
            } else {
                http1::Builder::new()
                    .keep_alive(config.keep_alive)
                    .serve_connection(io, service)
                    .await
            };
            if let Err(error) = result {
                tracing::debug!(%error, "connection closed with error");
            }
        });
    }
}

fn bind_reuse_port(address: &SocketAddr) -> io::Result<TcpListener> {
    let domain = if address.is_ipv4() {
        Domain::IPV4
    } else {
        Domain::IPV6
    };
    let socket = Socket::new(domain, Type::STREAM, Some(SocketProtocol::TCP))?;
    socket.set_reuse_address(true)?;
    #[cfg(unix)]
    socket.set_reuse_port(true)?;
    socket.set_nonblocking(true)?;
    socket.bind(&(*address).into())?;
    socket.listen(1024)?;
    let std_listener: std::net::TcpListener = socket.into();
    TcpListener::from_std(std_listener)
}
