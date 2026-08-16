use std::fmt;
use std::net::SocketAddr;
use std::sync::Arc;

use async_trait::async_trait;
use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use hyper::body::Incoming;
use hyper::server::conn::http1;
use hyper::service::service_fn;
use hyper::{Method, Request, Response, StatusCode};
use hyper_util::rt::TokioIo;
use parking_lot::Mutex;
use tokio::net::TcpListener;
use tokio::sync::watch;

use crate::adapter::native::{
    Native, Tcp as NativeTcp, Transport as NativeTransport, Udp as NativeUdp,
};
use crate::adapter::{Adapter, PacketHandler, WorkerStartHandler};
use crate::error::{Error, Result};
use crate::protocol::Protocol;

/// Swoole adapter implemented on Tokio (PHP Swoole runtime → Tokio).
///
/// PHP `Utopia\DNS\Adapter\Swoole`.
pub struct Swoole {
    native: Option<Native>,
    http: Mutex<Vec<HttpRuntime>>,
    on_packet: Mutex<Option<PacketHandler>>,
    on_worker_start: Mutex<Vec<WorkerStartHandler>>,
    shutdown: Mutex<Option<watch::Sender<bool>>>,
    workers: i64,
}

impl fmt::Debug for Swoole {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Swoole").finish_non_exhaustive()
    }
}

struct HttpRuntime {
    config: Http,
    listener: Mutex<Option<Arc<TcpListener>>>,
}

/// PHP `Utopia\DNS\Adapter\Swoole\Udp`.
#[derive(Debug, Clone)]
pub struct Udp {
    pub host: String,
    pub port: u16,
}

impl Udp {
    #[must_use]
    pub fn new(host: impl Into<String>, port: u16) -> Self {
        Self {
            host: host.into(),
            port,
        }
    }
}

/// PHP `Utopia\DNS\Adapter\Swoole\Tcp`.
#[derive(Debug, Clone)]
pub struct Tcp {
    pub host: String,
    pub port: u16,
    pub proxy_protocol: bool,
}

impl Tcp {
    #[must_use]
    pub fn new(host: impl Into<String>, port: u16) -> Self {
        Self {
            host: host.into(),
            port,
            proxy_protocol: false,
        }
    }

    #[must_use]
    pub fn proxy_protocol(mut self, enabled: bool) -> Self {
        self.proxy_protocol = enabled;
        self
    }
}

/// PHP `Utopia\DNS\Adapter\Swoole\Http` - DNS-over-HTTPS (RFC 8484) over Tokio/Hyper.
#[derive(Debug, Clone)]
pub struct Http {
    pub host: String,
    pub port: u16,
    pub cert_path: Option<String>,
    pub key_path: Option<String>,
    pub trust_proxy: bool,
}

impl Http {
    pub const CONTENT_TYPE: &'static str = "application/dns-message";

    pub fn new(host: impl Into<String>, port: u16) -> Result<Self> {
        Ok(Self {
            host: host.into(),
            port,
            cert_path: None,
            key_path: None,
            trust_proxy: false,
        })
    }

    pub fn tls(
        mut self,
        cert_path: impl Into<String>,
        key_path: impl Into<String>,
    ) -> Result<Self> {
        self.cert_path = Some(cert_path.into());
        self.key_path = Some(key_path.into());
        Ok(self)
    }

    #[must_use]
    pub fn trust_proxy(mut self, trust: bool) -> Self {
        self.trust_proxy = trust;
        self
    }
}

#[derive(Debug)]
pub enum Transport {
    Udp(Udp),
    Tcp(Tcp),
    Http(Http),
}

impl From<Udp> for Transport {
    fn from(value: Udp) -> Self {
        Self::Udp(value)
    }
}
impl From<Tcp> for Transport {
    fn from(value: Tcp) -> Self {
        Self::Tcp(value)
    }
}
impl From<Http> for Transport {
    fn from(value: Http) -> Self {
        Self::Http(value)
    }
}

impl Swoole {
    /// PHP `Swoole::__construct`.
    pub fn new(transports: Vec<Transport>, workers: i64, idle_timeout: u64) -> Result<Self> {
        if transports.is_empty() {
            return Err(Error::other("At least one transport is required."));
        }
        let mut native_t = Vec::new();
        let mut http = Vec::new();
        for t in transports {
            match t {
                Transport::Udp(u) => {
                    native_t.push(NativeTransport::Udp(NativeUdp::new(u.host, u.port)));
                }
                Transport::Tcp(t) => {
                    let mut tcp = NativeTcp::new(t.host, t.port).idle_timeout(idle_timeout);
                    tcp.proxy_protocol = t.proxy_protocol;
                    native_t.push(NativeTransport::Tcp(tcp));
                }
                Transport::Http(h) => {
                    if h.cert_path.is_some() != h.key_path.is_some() {
                        return Err(Error::other(
                            "TLS requires both a certificate and a key path.",
                        ));
                    }
                    http.push(HttpRuntime {
                        config: h,
                        listener: Mutex::new(None),
                    });
                }
            }
        }
        let native = if native_t.is_empty() {
            None
        } else {
            Some(Native::new(native_t)?)
        };
        Ok(Self {
            native,
            http: Mutex::new(http),
            on_packet: Mutex::new(None),
            on_worker_start: Mutex::new(Vec::new()),
            shutdown: Mutex::new(None),
            workers,
        })
    }

    pub fn stop(&self) {
        if let Some(n) = &self.native {
            n.stop();
        }
        if let Some(tx) = self.shutdown.lock().as_ref() {
            let _ = tx.send(true);
        }
    }
}

#[async_trait]
impl Adapter for Swoole {
    fn on_worker_start(&self, callback: WorkerStartHandler) {
        self.on_worker_start.lock().push(Arc::clone(&callback));
        if let Some(n) = &self.native {
            n.on_worker_start(callback);
        }
    }

    fn on_packet(&self, callback: PacketHandler) {
        *self.on_packet.lock() = Some(Arc::clone(&callback));
        if let Some(n) = &self.native {
            n.on_packet(callback);
        }
    }

    fn start(&self) -> Result<()> {
        let rt = tokio::runtime::Builder::new_multi_thread()
            .enable_all()
            .build()
            .map_err(|e| Error::other(e.to_string()))?;
        rt.block_on(self.start_async())
    }

    async fn start_async(&self) -> Result<()> {
        let (tx, mut rx) = watch::channel(false);
        *self.shutdown.lock() = Some(tx);

        let packet = self
            .on_packet
            .lock()
            .clone()
            .unwrap_or_else(|| Arc::new(|_, _, _, _| Vec::new()));

        if self.native.is_none() {
            for cb in self.on_worker_start.lock().iter() {
                cb(0);
            }
        }

        let mut http_tasks = Vec::new();
        let http_binds: Vec<(String, bool)> = {
            let https = self.http.lock();
            https
                .iter()
                .map(|h| {
                    (
                        format!("{}:{}", h.config.host, h.config.port),
                        h.config.trust_proxy,
                    )
                })
                .collect()
        };
        let mut bound = Vec::new();
        for (addr, trust) in http_binds {
            let listener = TcpListener::bind(&addr)
                .await
                .map_err(|e| Error::other(format!("Could not listen on {addr}. {e}")))?;
            bound.push((Arc::new(listener), trust));
        }
        {
            let https = self.http.lock();
            for (i, h) in https.iter().enumerate() {
                *h.listener.lock() = Some(Arc::clone(&bound[i].0));
            }
        }
        for (listener, trust) in bound {
            let packet = Arc::clone(&packet);
            let mut rx = rx.clone();
            http_tasks.push(tokio::spawn(async move {
                loop {
                    tokio::select! {
                        _ = rx.changed() => { if *rx.borrow() { break; } }
                        accept = listener.accept() => {
                            let Ok((stream, peer)) = accept else { continue; };
                            let packet = Arc::clone(&packet);
                            tokio::spawn(async move {
                                let io = TokioIo::new(stream);
                                let svc = service_fn(move |req| {
                                    let packet = Arc::clone(&packet);
                                    async move { handle_doh(req, packet, peer, trust).await }
                                });
                                let _ = http1::Builder::new().serve_connection(io, svc).await;
                            });
                        }
                    }
                }
            }));
        }

        let native_fut = async {
            if let Some(n) = &self.native {
                n.start_async().await
            } else {
                let _ = rx.changed().await;
                Ok(())
            }
        };

        let native_result = native_fut.await;
        for t in http_tasks {
            t.abort();
        }
        let _ = self.workers;
        native_result
    }

    fn stop(&self) {
        Swoole::stop(self);
    }

    fn udp_addr(&self) -> Option<SocketAddr> {
        self.native.as_ref().and_then(Native::udp_addr)
    }

    fn tcp_addr(&self) -> Option<SocketAddr> {
        self.native.as_ref().and_then(Native::tcp_addr)
    }

    fn http_addr(&self) -> Option<SocketAddr> {
        for h in self.http.lock().iter() {
            if let Some(l) = h.listener.lock().as_ref() {
                return l.local_addr().ok();
            }
        }
        None
    }
}

fn empty_status(status: StatusCode) -> Response<Full<Bytes>> {
    Response::builder()
        .status(status)
        .body(Full::new(Bytes::new()))
        .unwrap_or_else(|_| Response::new(Full::new(Bytes::new())))
}

async fn handle_doh(
    req: Request<Incoming>,
    packet: PacketHandler,
    peer: SocketAddr,
    trust_proxy: bool,
) -> std::result::Result<Response<Full<Bytes>>, hyper::Error> {
    let mut ip = peer.ip().to_string();
    let port = peer.port();
    if trust_proxy {
        if let Some(fwd) = req
            .headers()
            .get("x-forwarded-for")
            .and_then(|v| v.to_str().ok())
        {
            if let Some(last) = fwd.split(',').next_back().map(str::trim) {
                if !last.is_empty() {
                    ip = last.to_string();
                }
            }
        }
    }
    let Some(query) = read_query(req).await else {
        return Ok(empty_status(StatusCode::BAD_REQUEST));
    };
    let answer = packet(&query, &ip, port, Protocol::Https);
    if answer.is_empty() {
        return Ok(empty_status(StatusCode::BAD_REQUEST));
    }
    Ok(Response::builder()
        .status(StatusCode::OK)
        .header("Content-Type", Http::CONTENT_TYPE)
        .body(Full::new(Bytes::from(answer)))
        .unwrap_or_else(|_| Response::new(Full::new(Bytes::new()))))
}

async fn read_query(req: Request<Incoming>) -> Option<Vec<u8>> {
    match *req.method() {
        Method::GET => {
            let q = req.uri().query()?;
            let dns = q.split('&').find_map(|pair| {
                let (k, v) = pair.split_once('=')?;
                (k == "dns").then_some(v)
            })?;
            if dns.is_empty() {
                return None;
            }
            decode_base64url(dns)
        }
        Method::POST => {
            let ct = req
                .headers()
                .get(hyper::header::CONTENT_TYPE)
                .and_then(|v| v.to_str().ok())
                .unwrap_or("");
            let mime = ct
                .split(';')
                .next()
                .unwrap_or("")
                .trim()
                .to_ascii_lowercase();
            if mime != Http::CONTENT_TYPE {
                return None;
            }
            let collected = req.into_body().collect().await.ok()?;
            let bytes = collected.to_bytes();
            if bytes.is_empty() {
                None
            } else {
                Some(bytes.to_vec())
            }
        }
        _ => None,
    }
}

fn decode_base64url(input: &str) -> Option<Vec<u8>> {
    use base64::Engine;
    let mut s = input.replace('-', "+").replace('_', "/");
    while s.len() % 4 != 0 {
        s.push('=');
    }
    base64::engine::general_purpose::STANDARD
        .decode(s)
        .ok()
        .filter(|d| !d.is_empty())
}
