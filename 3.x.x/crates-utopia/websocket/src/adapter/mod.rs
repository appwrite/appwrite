mod tokio_adapter;

pub use tokio_adapter::{Swoole, TokioAdapter, Workerman};

use crate::error::WebsocketError;
use crate::http::{HttpRequest, HttpResponse};

/// Native handle returned by [`Adapter::get_native`].
#[derive(Debug, Clone)]
pub struct NativeHandle {
    /// Bind host.
    pub host: String,
    /// Bind port (actual port after `start` when constructed with `0`).
    pub port: u16,
}

/// PHP `Utopia\WebSocket\Adapter`.
pub trait Adapter: Send {
    /// PHP `start()`.
    fn start(&mut self) -> Result<(), WebsocketError>;
    /// PHP `shutdown()`.
    fn shutdown(&mut self) -> Result<(), WebsocketError>;
    /// PHP `send(array $connections, string $message)`.
    fn send(&self, connections: &[i64], message: &str) -> Result<(), WebsocketError>;
    /// PHP `close(int $connection, int $code)`.
    fn close(&self, connection: i64, code: i32) -> Result<(), WebsocketError>;
    /// PHP `onStart(callable $callback)`.
    fn on_start(&mut self, callback: Box<dyn Fn() + Send + Sync>) -> &mut Self;
    /// PHP `onWorkerStart(callable $callback)`.
    fn on_worker_start(&mut self, callback: Box<dyn Fn(i32) + Send + Sync>) -> &mut Self;
    /// PHP `onWorkerStop(callable $callback)`.
    fn on_worker_stop(&mut self, callback: Box<dyn Fn(i32) + Send + Sync>) -> &mut Self;
    /// PHP `onOpen(callable $callback)`.
    fn on_open(&mut self, callback: Box<dyn Fn(i64, HttpRequest) + Send + Sync>) -> &mut Self;
    /// PHP `onMessage(callable $callback)`.
    fn on_message(&mut self, callback: Box<dyn Fn(i64, String) + Send + Sync>) -> &mut Self;
    /// PHP `onRequest(callable $callback)`.
    fn on_request(
        &mut self,
        callback: Box<dyn Fn(HttpRequest, HttpResponse) + Send + Sync>,
    ) -> &mut Self;
    /// PHP `onClose(callable $callback)`.
    fn on_close(&mut self, callback: Box<dyn Fn(i64) + Send + Sync>) -> &mut Self;
    /// PHP `setPackageMaxLength(int $bytes)`.
    fn set_package_max_length(&mut self, bytes: i32) -> &mut Self;
    /// PHP `setCompressionEnabled(bool $enabled)`.
    fn set_compression_enabled(&mut self, enabled: bool) -> &mut Self;
    /// PHP `setWorkerNumber(int $num)`.
    fn set_worker_number(&mut self, num: i32) -> &mut Self;
    /// PHP `getNative()`.
    fn get_native(&self) -> NativeHandle;
    /// PHP `getConnections()`.
    fn get_connections(&self) -> Vec<i64>;
}
