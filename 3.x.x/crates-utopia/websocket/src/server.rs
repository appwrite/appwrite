use crate::adapter::Adapter;
use crate::error::WebsocketError;
use crate::http::{HttpRequest, HttpResponse};

type ErrorCallback = Box<dyn Fn(&WebsocketError, &str) + Send + Sync>;

/// PHP `Utopia\WebSocket\Server`.
pub struct Server<A: Adapter> {
    adapter: A,
    error_callbacks: Vec<ErrorCallback>,
}

impl<A: Adapter + std::fmt::Debug> std::fmt::Debug for Server<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Server")
            .field("adapter", &self.adapter)
            .finish_non_exhaustive()
    }
}

impl<A: Adapter> Server<A> {
    /// PHP `__construct(Adapter $adapter)`.
    pub fn new(adapter: A) -> Self {
        Self {
            adapter,
            error_callbacks: Vec::new(),
        }
    }

    fn catch(&self, op: &str, result: Result<(), WebsocketError>) {
        if let Err(error) = result {
            for cb in &self.error_callbacks {
                cb(&error, op);
            }
        }
    }

    /// PHP `start()`.
    pub fn start(&mut self) {
        let result = self.adapter.start();
        self.catch("start", result);
    }

    /// PHP `shutdown()`.
    pub fn shutdown(&mut self) {
        let result = self.adapter.shutdown();
        self.catch("shutdown", result);
    }

    /// PHP `send(array $connections, string $message)`.
    pub fn send(&self, connections: &[i64], message: &str) {
        self.catch("send", self.adapter.send(connections, message));
    }

    /// PHP `close(int $connection, int $code)`.
    pub fn close(&self, connection: i64, code: i32) {
        self.catch("close", self.adapter.close(connection, code));
    }

    /// PHP `onStart`.
    pub fn on_start(&mut self, callback: Box<dyn Fn() + Send + Sync>) -> &mut Self {
        let _ = self.adapter.on_start(callback);
        self
    }

    /// PHP `onWorkerStart`.
    pub fn on_worker_start(&mut self, callback: Box<dyn Fn(i32) + Send + Sync>) -> &mut Self {
        let _ = self.adapter.on_worker_start(callback);
        self
    }

    /// PHP `onWorkerStop`.
    pub fn on_worker_stop(&mut self, callback: Box<dyn Fn(i32) + Send + Sync>) -> &mut Self {
        let _ = self.adapter.on_worker_stop(callback);
        self
    }

    /// PHP `onOpen`.
    pub fn on_open(&mut self, callback: Box<dyn Fn(i64, HttpRequest) + Send + Sync>) -> &mut Self {
        let _ = self.adapter.on_open(callback);
        self
    }

    /// PHP `onMessage`.
    pub fn on_message(&mut self, callback: Box<dyn Fn(i64, String) + Send + Sync>) -> &mut Self {
        let _ = self.adapter.on_message(callback);
        self
    }

    /// PHP `onClose`.
    pub fn on_close(&mut self, callback: Box<dyn Fn(i64) + Send + Sync>) -> &mut Self {
        let _ = self.adapter.on_close(callback);
        self
    }

    /// PHP `onRequest`.
    pub fn on_request(
        &mut self,
        callback: Box<dyn Fn(HttpRequest, HttpResponse) + Send + Sync>,
    ) -> &mut Self {
        let _ = self.adapter.on_request(callback);
        self
    }

    /// PHP `getConnections()`.
    #[must_use]
    pub fn get_connections(&self) -> Vec<i64> {
        self.adapter.get_connections()
    }

    /// PHP `error(callable $callback)`.
    pub fn error(
        &mut self,
        callback: impl Fn(&WebsocketError, &str) + Send + Sync + 'static,
    ) -> &mut Self {
        self.error_callbacks.push(Box::new(callback));
        self
    }

    /// Access the underlying adapter (Rust helper).
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// Mutable adapter access (Rust helper for configuration before `start`).
    pub fn adapter_mut(&mut self) -> &mut A {
        &mut self.adapter
    }
}
