//! The interface every server adapter implements, and the hooks it calls.

use std::future::Future;
use std::sync::Arc;

use bytes::Bytes;

use crate::Error;

/// The id of one WebSocket connection, unique while it is open.
///
/// PHP: the `int $connection` the hooks receive (a Swoole fd, a Workerman
/// connection id).
pub type ConnectionId = i64;

/// An HTTP request: the handshake of a WebSocket connection (to
/// [`Adapter::on_open`]) or a plain request on the server's port (to
/// [`Adapter::on_request`]).
///
/// PHP: `Swoole\Http\Request` (Workerman passes `$_SERVER` to `onOpen`).
pub type Request = http::Request<Bytes>;

/// The response an [`Adapter::on_request`] hook fills: `200 OK` with no
/// headers and an empty body until the hook changes it.
///
/// PHP: `Swoole\Http\Response`.
pub type Response = http::Response<Vec<u8>>;

/// Called once when the server starts listening.
pub type StartHook = Arc<dyn Fn() + Send + Sync>;
/// Called with a worker id when a worker starts or stops.
pub type WorkerHook = Arc<dyn Fn(i64) + Send + Sync>;
/// Called when a connection is established, with its handshake request.
pub type OpenHook = Arc<dyn Fn(ConnectionId, &Request) + Send + Sync>;
/// Called with each message a connection sends.
pub type MessageHook = Arc<dyn Fn(ConnectionId, &[u8]) + Send + Sync>;
/// Called when a connection is closed, by either side.
pub type CloseHook = Arc<dyn Fn(ConnectionId) + Send + Sync>;
/// Called with each plain HTTP request and the response to fill.
pub type RequestHook = Arc<dyn Fn(&Request, &mut Response) + Send + Sync>;

/// A WebSocket server implementation: [`Tokio`](crate::Tokio) on the
/// network, [`Memory`](crate::Memory) in process. [`Server`](crate::Server)
/// drives one.
///
/// Adapters are cheap handles (clones share one server), so a hook can keep
/// one to send from inside an event. Hooks are registered before
/// [`start`](Adapter::start); each `on_*` method replaces the hook set
/// before.
///
/// PHP: `Utopia\WebSocket\Adapter`. Implement this trait to add a backend,
/// as PHP subclasses `Adapter`.
pub trait Adapter: Send + Sync + 'static {
    /// Starts serving and runs until [`shutdown`](Adapter::shutdown).
    ///
    /// PHP: `start()`.
    fn start(&self) -> impl Future<Output = Result<(), Error>> + Send;

    /// Stops the server: closes every connection and makes
    /// [`start`](Adapter::start) return.
    ///
    /// PHP: `shutdown()`.
    fn shutdown(&self) -> Result<(), Error>;

    /// Sends a text message to each connection in `connections`, without
    /// waiting for it to be written. Connections that are not open are
    /// skipped.
    ///
    /// PHP: `send(array $connections, string $message)`.
    fn send(&self, connections: &[ConnectionId], message: Bytes) -> Result<(), Error>;

    /// Closes a connection with a close code.
    ///
    /// PHP: `close(int $connection, int $code)`.
    fn close(&self, connection: ConnectionId, code: i64) -> Result<(), Error>;

    /// Sets the hook called when the server starts.
    ///
    /// PHP: `onStart(callable $callback)`.
    fn on_start(&self, hook: StartHook) -> Result<(), Error>;

    /// Sets the hook called with each worker id when a worker starts.
    ///
    /// PHP: `onWorkerStart(callable $callback)`.
    fn on_worker_start(&self, hook: WorkerHook) -> Result<(), Error>;

    /// Sets the hook called with each worker id when a worker stops.
    ///
    /// PHP: `onWorkerStop(callable $callback)`.
    fn on_worker_stop(&self, hook: WorkerHook) -> Result<(), Error>;

    /// Sets the hook called when a connection is established.
    ///
    /// PHP: `onOpen(callable $callback)`.
    fn on_open(&self, hook: OpenHook) -> Result<(), Error>;

    /// Sets the hook called with each message received.
    ///
    /// PHP: `onMessage(callable $callback)`.
    fn on_message(&self, hook: MessageHook) -> Result<(), Error>;

    /// Sets the hook called when a connection closes.
    ///
    /// PHP: `onClose(callable $callback)`.
    fn on_close(&self, hook: CloseHook) -> Result<(), Error>;

    /// Sets the hook called with each plain HTTP request.
    ///
    /// PHP: `onRequest(callable $callback)`.
    fn on_request(&self, hook: RequestHook) -> Result<(), Error>;

    /// Sets the largest message accepted, in bytes.
    ///
    /// PHP: `setPackageMaxLength(int $bytes)`.
    fn set_package_max_length(&self, bytes: i64);

    /// Enables or disables per-message compression.
    ///
    /// PHP: `setCompressionEnabled(bool $enabled)`.
    fn set_compression_enabled(&self, enabled: bool);

    /// Sets the number of workers.
    ///
    /// PHP: `setWorkerNumber(int $num)`.
    fn set_worker_number(&self, workers: i64);
}

/// The hooks an adapter has been given.
#[derive(Clone, Default)]
pub(crate) struct Hooks {
    pub start: Option<StartHook>,
    pub worker_start: Option<WorkerHook>,
    pub worker_stop: Option<WorkerHook>,
    pub open: Option<OpenHook>,
    pub message: Option<MessageHook>,
    pub close: Option<CloseHook>,
    pub request: Option<RequestHook>,
}

/// A new response for an [`Adapter::on_request`] hook: `200 OK`, no headers,
/// empty body.
pub(crate) fn response() -> Response {
    http::Response::new(Vec::new())
}
