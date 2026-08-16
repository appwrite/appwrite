use super::{Adapter, BoxedFuture, RequestHandler};
use crate::error::{HttpError, Result};
use crate::request::Request;
use crate::response::Response;
use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use hyper::body::Incoming;
use hyper::server::conn::http1;
use hyper::service::service_fn;
use hyper::{Request as HyperRequest, Response as HyperResponse};
use hyper_util::rt::TokioIo;
use parking_lot::Mutex;
use socket2::{Domain, Protocol, Socket, Type};
use std::convert::Infallible;
use std::fmt;
use std::net::SocketAddr;
use std::sync::Arc;
use tokio::net::TcpListener;
use utopia_di::Container;

/// Tokio + Hyper HTTP/1.1 server adapter (Swoole-equivalent keep-alive).
pub struct HyperServer {
    addr: String,
    resources: Container,
    handler: Mutex<Option<Arc<RequestHandler>>>,
    /// Number of parallel accept loops (`SO_REUSEPORT`). Defaults to CPU count.
    acceptors: usize,
}

impl fmt::Debug for HyperServer {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("HyperServer")
            .field("addr", &self.addr)
            .field("resources", &self.resources)
            .field("acceptors", &self.acceptors)
            .finish_non_exhaustive()
    }
}

impl HyperServer {
    pub fn bind(addr: impl Into<String>, resources: Container) -> Self {
        let acceptors = std::thread::available_parallelism()
            .map(|n| n.get())
            .unwrap_or(1)
            .max(1);
        Self {
            addr: addr.into(),
            resources,
            handler: Mutex::new(None),
            acceptors,
        }
    }

    /// Override parallel accept-loop count (each binds with `SO_REUSEPORT`).
    pub fn acceptors(mut self, n: usize) -> Self {
        self.acceptors = n.max(1);
        self
    }
}

impl Adapter for HyperServer {
    fn resources(&self) -> &Container {
        &self.resources
    }

    fn address(&self) -> Option<&str> {
        Some(&self.addr)
    }

    fn on_request(&self, handler: RequestHandler) -> BoxedFuture<'_, ()> {
        *self.handler.lock() = Some(Arc::new(handler));
        Box::pin(async {})
    }

    fn start(&self) -> BoxedFuture<'_, Result<()>> {
        let addr = self.addr.clone();
        let acceptors = self.acceptors;
        Box::pin(async move {
            let handler = self
                .handler
                .lock()
                .clone()
                .ok_or_else(|| HttpError::Other("no request handler".into()))?;
            let socket: SocketAddr = addr
                .parse()
                .map_err(|e| HttpError::Other(format!("invalid bind address {addr}: {e}")))?;

            eprintln!("hyper acceptors={acceptors} (SO_REUSEPORT) on {addr}");

            let mut joins = Vec::with_capacity(acceptors);
            for _ in 0..acceptors {
                let listener = bind_reuseport(socket)?;
                let handler = Arc::clone(&handler);
                joins.push(tokio::spawn(
                    async move { accept_loop(listener, handler).await },
                ));
            }

            // Run until the first acceptor fails (or forever on success).
            for j in joins {
                j.await
                    .map_err(|e| HttpError::Other(format!("acceptor join: {e}")))??;
            }
            Ok(())
        })
    }
}

fn bind_reuseport(addr: SocketAddr) -> Result<TcpListener> {
    let domain = if addr.is_ipv4() {
        Domain::IPV4
    } else {
        Domain::IPV6
    };
    let socket = Socket::new(domain, Type::STREAM, Some(Protocol::TCP))
        .map_err(|e| HttpError::Other(format!("socket: {e}")))?;
    socket
        .set_reuse_address(true)
        .map_err(|e| HttpError::Other(format!("SO_REUSEADDR: {e}")))?;
    #[cfg(unix)]
    socket
        .set_reuse_port(true)
        .map_err(|e| HttpError::Other(format!("SO_REUSEPORT: {e}")))?;
    socket
        .set_nonblocking(true)
        .map_err(|e| HttpError::Other(format!("nonblocking: {e}")))?;
    socket
        .bind(&addr.into())
        .map_err(|e| HttpError::Other(format!("bind: {e}")))?;
    // Large backlog under load (Swoole-style).
    socket
        .listen(1024)
        .map_err(|e| HttpError::Other(format!("listen: {e}")))?;
    let std_listener: std::net::TcpListener = socket.into();
    TcpListener::from_std(std_listener)
        .map_err(|e| HttpError::Other(format!("tokio listener: {e}")))
}

async fn accept_loop(listener: TcpListener, handler: Arc<RequestHandler>) -> Result<()> {
    loop {
        let (stream, _) = listener
            .accept()
            .await
            .map_err(|e| HttpError::Other(format!("accept: {e}")))?;
        let _ = stream.set_nodelay(true);
        let io = TokioIo::new(stream);
        let handler = Arc::clone(&handler);
        tokio::spawn(async move {
            let svc = service_fn(move |req: HyperRequest<Incoming>| {
                let handler = Arc::clone(&handler);
                async move {
                    match hyper_to_utopia(req).await {
                        Ok(request) => {
                            let response = Response::new();
                            let out = response.clone();
                            handler(request, response).await;
                            Ok::<_, Infallible>(utopia_to_hyper(out))
                        }
                        Err(err) => Ok(error_response(400, err.to_string())),
                    }
                }
            });
            let mut conn = http1::Builder::new();
            conn.keep_alive(true);
            conn.timer(hyper_util::rt::TokioTimer::new());
            let _ = conn.serve_connection(io, svc).await;
        });
    }
}

async fn hyper_to_utopia(req: HyperRequest<Incoming>) -> Result<Request> {
    let (parts, body) = req.into_parts();
    let method = parts.method.as_str();
    let uri = parts.uri.to_string();

    // Skip body pooling for typical GET/HEAD with no payload.
    let raw = if matches!(method, "GET" | "HEAD" | "OPTIONS" | "DELETE")
        && parts
            .headers
            .get(hyper::header::CONTENT_LENGTH)
            .and_then(|v| v.to_str().ok())
            .and_then(|v| v.parse::<u64>().ok())
            .unwrap_or(0)
            == 0
        && parts
            .headers
            .get(hyper::header::TRANSFER_ENCODING)
            .is_none()
    {
        Vec::new()
    } else {
        body.collect()
            .await
            .map_err(|e| HttpError::Other(format!("read body: {e}")))?
            .to_bytes()
            .to_vec()
    };

    let mut request = Request::new(method, uri);
    for (name, value) in &parts.headers {
        if let Ok(v) = value.to_str() {
            request.set_header(name.as_str(), v);
        }
    }
    if !raw.is_empty() {
        request.set_raw_payload(raw);
    }
    request.parse_query_from_uri();
    request.parse_payload_from_raw();
    Ok(request)
}

fn utopia_to_hyper(res: Response) -> HyperResponse<Full<Bytes>> {
    let (status, headers, body) = res.into_http_parts();
    let status = hyper::StatusCode::from_u16(status).unwrap_or(hyper::StatusCode::OK);
    let mut builder = HyperResponse::builder().status(status);
    for (name, value) in headers {
        builder = builder.header(name, value);
    }
    builder
        .body(Full::new(Bytes::from(body)))
        .unwrap_or_else(|_| error_response(500, "failed to build response".into()))
}

fn error_response(status: u16, msg: String) -> HyperResponse<Full<Bytes>> {
    HyperResponse::builder()
        .status(status)
        .header("content-type", "text/plain")
        .body(Full::new(Bytes::from(msg)))
        .expect("response")
}
