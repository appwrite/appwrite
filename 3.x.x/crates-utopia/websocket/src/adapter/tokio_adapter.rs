//! Tokio TCP/WebSocket adapter used as the Swoole and Workerman equivalent.

use crate::adapter::{Adapter, NativeHandle};
use crate::error::WebsocketError;
use crate::http::{HttpRequest, HttpResponse};
use crate::protocol::{
    decode_frame, encode_frame, header_value, parse_http_request, server_upgrade_response,
    OPCODE_CLOSE, OPCODE_PING, OPCODE_PONG, OPCODE_TEXT,
};
use parking_lot::Mutex;
use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicU16, AtomicU64, Ordering};
use std::sync::Arc;
use std::time::Duration;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpListener;
use tokio::sync::mpsc;

type StartCb = Arc<dyn Fn() + Send + Sync>;
type WorkerCb = Arc<dyn Fn(i32) + Send + Sync>;
type OpenCb = Arc<dyn Fn(i64, HttpRequest) + Send + Sync>;
type MessageCb = Arc<dyn Fn(i64, String) + Send + Sync>;
type RequestCb = Arc<dyn Fn(HttpRequest, HttpResponse) + Send + Sync>;
type CloseCb = Arc<dyn Fn(i64) + Send + Sync>;

struct Callbacks {
    start: Option<StartCb>,
    worker_start: Option<WorkerCb>,
    worker_stop: Option<WorkerCb>,
    open: Option<OpenCb>,
    message: Option<MessageCb>,
    request: Option<RequestCb>,
    close: Option<CloseCb>,
}

struct Inner {
    host: String,
    port: AtomicU16,
    package_max_length: AtomicU64,
    compression: AtomicBool,
    worker_num: AtomicU64,
    running: AtomicBool,
    stop: AtomicBool,
    next_id: AtomicU64,
    connections: Mutex<HashMap<i64, mpsc::UnboundedSender<Vec<u8>>>>,
    callbacks: Mutex<Callbacks>,
}

/// Tokio-based WebSocket server adapter.
///
/// PHP `Utopia\WebSocket\Adapter\Swoole` and `Workerman` both wrap this type
/// (see type aliases [`Swoole`](crate::Swoole) and [`Workerman`](crate::Workerman)).
#[derive(Clone)]
pub struct TokioAdapter {
    inner: Arc<Inner>,
}

impl std::fmt::Debug for TokioAdapter {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("TokioAdapter")
            .field("host", &self.inner.host)
            .field("port", &self.inner.port.load(Ordering::SeqCst))
            .finish()
    }
}

impl TokioAdapter {
    /// PHP `__construct(string $host = '0.0.0.0', int $port = 80)`.
    #[must_use]
    pub fn new(host: impl Into<String>, port: u16) -> Self {
        Self {
            inner: Arc::new(Inner {
                host: host.into(),
                port: AtomicU16::new(port),
                package_max_length: AtomicU64::new(2 * 1024 * 1024),
                compression: AtomicBool::new(false),
                worker_num: AtomicU64::new(1),
                running: AtomicBool::new(false),
                stop: AtomicBool::new(false),
                next_id: AtomicU64::new(1),
                connections: Mutex::new(HashMap::new()),
                callbacks: Mutex::new(Callbacks {
                    start: None,
                    worker_start: None,
                    worker_stop: None,
                    open: None,
                    message: None,
                    request: None,
                    close: None,
                }),
            }),
        }
    }

    async fn run(&self) -> Result<(), WebsocketError> {
        let addr = format!(
            "{}:{}",
            self.inner.host,
            self.inner.port.load(Ordering::SeqCst)
        );
        let listener = TcpListener::bind(&addr).await?;
        let actual = listener.local_addr()?.port();
        self.inner.port.store(actual, Ordering::SeqCst);
        self.inner.running.store(true, Ordering::SeqCst);

        {
            let cbs = self.inner.callbacks.lock();
            if let Some(cb) = &cbs.start {
                cb();
            }
            let workers = self.inner.worker_num.load(Ordering::SeqCst);
            if let Some(cb) = &cbs.worker_start {
                for id in 0..workers {
                    cb(i32::try_from(id).unwrap_or(0));
                }
            }
        }

        while !self.inner.stop.load(Ordering::SeqCst) {
            if let Ok(Ok((stream, _))) =
                tokio::time::timeout(Duration::from_millis(50), listener.accept()).await
            {
                let adapter = self.clone();
                tokio::spawn(async move {
                    adapter.handle_conn(stream).await;
                });
            }
        }

        {
            let cbs = self.inner.callbacks.lock();
            let workers = self.inner.worker_num.load(Ordering::SeqCst);
            if let Some(cb) = &cbs.worker_stop {
                for id in 0..workers {
                    cb(i32::try_from(id).unwrap_or(0));
                }
            }
        }
        self.inner.running.store(false, Ordering::SeqCst);
        Ok(())
    }

    #[allow(clippy::too_many_lines)]
    async fn handle_conn(&self, mut stream: tokio::net::TcpStream) {
        let mut buf = Vec::new();
        let mut tmp = [0u8; 4096];
        loop {
            match stream.read(&mut tmp).await {
                Ok(n) if n > 0 => buf.extend_from_slice(&tmp[..n]),
                _ => return,
            }
            if let Some((method, path, headers, header_end)) = parse_http_request(&buf) {
                let upgrade = header_value(&headers, "Upgrade")
                    .is_some_and(|v| v.eq_ignore_ascii_case("websocket"));
                if upgrade {
                    let Some(key) = header_value(&headers, "Sec-WebSocket-Key") else {
                        return;
                    };
                    let response = server_upgrade_response(key);
                    if stream.write_all(response.as_bytes()).await.is_err() {
                        return;
                    }
                    let leftover = buf[header_end..].to_vec();
                    self.ws_loop(stream, leftover, path, headers).await;
                    return;
                }
                let id =
                    i64::try_from(self.inner.next_id.fetch_add(1, Ordering::SeqCst)).unwrap_or(1);
                let request = HttpRequest {
                    connection: id,
                    method,
                    path,
                    headers,
                };
                let response = HttpResponse::new();
                if let Some(cb) = self.inner.callbacks.lock().request.clone() {
                    cb(request, response.clone());
                } else {
                    response.status(404).end("Not Found");
                }
                let _ = stream.write_all(&response.to_bytes()).await;
                return;
            }
            if buf.len() > 64 * 1024 {
                return;
            }
        }
    }

    async fn ws_loop(
        &self,
        stream: tokio::net::TcpStream,
        leftover: Vec<u8>,
        path: String,
        headers: Vec<(String, String)>,
    ) {
        let id = i64::try_from(self.inner.next_id.fetch_add(1, Ordering::SeqCst)).unwrap_or(1);
        let (tx, mut rx) = mpsc::unbounded_channel::<Vec<u8>>();
        self.inner.connections.lock().insert(id, tx);
        if let Some(cb) = self.inner.callbacks.lock().open.clone() {
            cb(
                id,
                HttpRequest {
                    connection: id,
                    method: "GET".to_string(),
                    path,
                    headers,
                },
            );
        }

        let (mut reader, mut writer) = stream.into_split();
        let max_len = self.inner.package_max_length.load(Ordering::SeqCst) as usize;

        let write_task = tokio::spawn(async move {
            while let Some(frame) = rx.recv().await {
                if writer.write_all(&frame).await.is_err() {
                    break;
                }
            }
        });

        let inner = Arc::clone(&self.inner);
        let read_task = tokio::spawn(async move {
            let mut buf = leftover;
            let mut tmp = [0u8; 4096];
            loop {
                match decode_frame(&buf, max_len) {
                    Ok(Some((frame, used))) => {
                        buf.drain(..used);
                        match frame.opcode {
                            OPCODE_TEXT => {
                                if let Ok(text) = String::from_utf8(frame.payload) {
                                    if let Some(cb) = inner.callbacks.lock().message.clone() {
                                        cb(id, text);
                                    }
                                }
                            }
                            OPCODE_PING => {
                                if let Some(tx) = inner.connections.lock().get(&id) {
                                    let _ =
                                        tx.send(encode_frame(OPCODE_PONG, &frame.payload, false));
                                }
                            }
                            OPCODE_CLOSE => break,
                            _ => {}
                        }
                    }
                    Ok(None) => match reader.read(&mut tmp).await {
                        Ok(n) if n > 0 => buf.extend_from_slice(&tmp[..n]),
                        _ => break,
                    },
                    Err(_) => break,
                }
            }
        });

        let _ = read_task.await;
        write_task.abort();
        self.inner.connections.lock().remove(&id);
        if let Some(cb) = self.inner.callbacks.lock().close.clone() {
            cb(id);
        }
    }
}

impl Adapter for TokioAdapter {
    fn start(&mut self) -> Result<(), WebsocketError> {
        let rt = tokio::runtime::Builder::new_multi_thread()
            .enable_all()
            .build()
            .map_err(|e| WebsocketError::Io(e.to_string()))?;
        rt.block_on(self.run())
    }

    fn shutdown(&mut self) -> Result<(), WebsocketError> {
        self.inner.stop.store(true, Ordering::SeqCst);
        Ok(())
    }

    fn send(&self, connections: &[i64], message: &str) -> Result<(), WebsocketError> {
        let frame = encode_frame(OPCODE_TEXT, message.as_bytes(), false);
        let map = self.inner.connections.lock();
        for id in connections {
            if let Some(tx) = map.get(id) {
                let _ = tx.send(frame.clone());
            }
        }
        Ok(())
    }

    fn close(&self, connection: i64, _code: i32) -> Result<(), WebsocketError> {
        let frame = encode_frame(OPCODE_CLOSE, &[], false);
        if let Some(tx) = self.inner.connections.lock().get(&connection) {
            let _ = tx.send(frame);
        }
        Ok(())
    }

    fn on_start(&mut self, callback: Box<dyn Fn() + Send + Sync>) -> &mut Self {
        self.inner.callbacks.lock().start = Some(Arc::from(callback));
        self
    }

    fn on_worker_start(&mut self, callback: Box<dyn Fn(i32) + Send + Sync>) -> &mut Self {
        self.inner.callbacks.lock().worker_start = Some(Arc::from(callback));
        self
    }

    fn on_worker_stop(&mut self, callback: Box<dyn Fn(i32) + Send + Sync>) -> &mut Self {
        self.inner.callbacks.lock().worker_stop = Some(Arc::from(callback));
        self
    }

    fn on_open(&mut self, callback: Box<dyn Fn(i64, HttpRequest) + Send + Sync>) -> &mut Self {
        self.inner.callbacks.lock().open = Some(Arc::from(callback));
        self
    }

    fn on_message(&mut self, callback: Box<dyn Fn(i64, String) + Send + Sync>) -> &mut Self {
        self.inner.callbacks.lock().message = Some(Arc::from(callback));
        self
    }

    fn on_request(
        &mut self,
        callback: Box<dyn Fn(HttpRequest, HttpResponse) + Send + Sync>,
    ) -> &mut Self {
        self.inner.callbacks.lock().request = Some(Arc::from(callback));
        self
    }

    fn on_close(&mut self, callback: Box<dyn Fn(i64) + Send + Sync>) -> &mut Self {
        self.inner.callbacks.lock().close = Some(Arc::from(callback));
        self
    }

    fn set_package_max_length(&mut self, bytes: i32) -> &mut Self {
        self.inner
            .package_max_length
            .store(u64::try_from(bytes.max(0)).unwrap_or(0), Ordering::SeqCst);
        self
    }

    fn set_compression_enabled(&mut self, enabled: bool) -> &mut Self {
        self.inner.compression.store(enabled, Ordering::SeqCst);
        self
    }

    fn set_worker_number(&mut self, num: i32) -> &mut Self {
        self.inner
            .worker_num
            .store(u64::try_from(num.max(1)).unwrap_or(1), Ordering::SeqCst);
        self
    }

    fn get_native(&self) -> NativeHandle {
        NativeHandle {
            host: self.inner.host.clone(),
            port: self.inner.port.load(Ordering::SeqCst),
        }
    }

    fn get_connections(&self) -> Vec<i64> {
        self.inner.connections.lock().keys().copied().collect()
    }
}

/// PHP `Utopia\WebSocket\Adapter\Swoole` - Tokio-backed.
pub type Swoole = TokioAdapter;
/// PHP `Utopia\WebSocket\Adapter\Workerman` - Tokio-backed.
pub type Workerman = TokioAdapter;
