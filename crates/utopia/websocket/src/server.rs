//! The server: an adapter, its hooks, and the error callbacks.

use std::fmt;
use std::sync::{Arc, RwLock};

use bytes::Bytes;

use crate::Error;
use crate::adapter::{Adapter, ConnectionId, Request, Response};

/// The step of the server that failed, passed to error callbacks.
///
/// [`Stage::as_str`] is the name PHP passes as the callback's second
/// argument (`"start"`, `"onMessage"`, ...).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Stage {
    /// [`Server::start`].
    Start,
    /// [`Server::shutdown`].
    Shutdown,
    /// [`Server::send`].
    Send,
    /// [`Server::close`].
    Close,
    /// [`Server::on_start`].
    OnStart,
    /// [`Server::on_worker_start`].
    OnWorkerStart,
    /// [`Server::on_worker_stop`].
    OnWorkerStop,
    /// [`Server::on_open`].
    OnOpen,
    /// [`Server::on_message`].
    OnMessage,
    /// [`Server::on_close`].
    OnClose,
    /// [`Server::on_request`].
    OnRequest,
}

impl Stage {
    /// Every stage.
    pub const ALL: [Stage; 11] = [
        Stage::Start,
        Stage::Shutdown,
        Stage::Send,
        Stage::Close,
        Stage::OnStart,
        Stage::OnWorkerStart,
        Stage::OnWorkerStop,
        Stage::OnOpen,
        Stage::OnMessage,
        Stage::OnClose,
        Stage::OnRequest,
    ];

    /// The stage's name: the method it guards, as PHP names it.
    pub fn as_str(self) -> &'static str {
        match self {
            Stage::Start => "start",
            Stage::Shutdown => "shutdown",
            Stage::Send => "send",
            Stage::Close => "close",
            Stage::OnStart => "onStart",
            Stage::OnWorkerStart => "onWorkerStart",
            Stage::OnWorkerStop => "onWorkerStop",
            Stage::OnOpen => "onOpen",
            Stage::OnMessage => "onMessage",
            Stage::OnClose => "onClose",
            Stage::OnRequest => "onRequest",
        }
    }

    /// The stage named `name` ([`Stage::as_str`]).
    pub fn from_name(name: &str) -> Option<Stage> {
        Stage::ALL.into_iter().find(|s| s.as_str() == name)
    }
}

impl fmt::Display for Stage {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.as_str())
    }
}

type ErrorCallback = Arc<dyn Fn(&Error, Stage) + Send + Sync>;

/// A WebSocket server on an [`Adapter`].
///
/// Every method forwards to the adapter. A failure is never returned: it is
/// passed, with the [`Stage`] that failed, to each callback registered with
/// [`Server::error`], in registration order (and dropped when there is
/// none). Clones share the adapter and the callbacks, so a hook can hold
/// one to send or close from inside an event.
///
/// ```
/// use utopia_websocket::{Memory, Server};
///
/// let adapter = Memory::new();
/// let server = Server::new(adapter.clone());
/// let replies = server.clone();
/// server.on_message(move |connection, message| {
///     if message == b"ping" {
///         replies.send(&[connection], "pong");
///     }
/// });
///
/// adapter.open(1, &Default::default());
/// adapter.message(1, b"ping");
/// assert_eq!(adapter.frames()[0].data, "pong");
/// ```
///
/// ```php
/// use Utopia\WebSocket\Adapter\Swoole;
/// use Utopia\WebSocket\Server;
///
/// $server = new Server(new Swoole('0.0.0.0', 8080));
/// $server->onMessage(function (int $connection, string $message) use ($server) {
///     if ($message === 'ping') {
///         $server->send([$connection], 'pong');
///     }
/// });
/// ```
///
/// PHP: `Utopia\WebSocket\Server`.
pub struct Server<A> {
    adapter: A,
    errors: Arc<RwLock<Vec<ErrorCallback>>>,
}

impl<A: Clone> Clone for Server<A> {
    fn clone(&self) -> Self {
        Self { adapter: self.adapter.clone(), errors: self.errors.clone() }
    }
}

impl<A: Adapter> Server<A> {
    /// A server on `adapter`.
    ///
    /// PHP: `new Server(Adapter $adapter)`.
    pub fn new(adapter: A) -> Self {
        Self { adapter, errors: Arc::default() }
    }

    /// The adapter.
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// Starts the server and runs until it shuts down.
    ///
    /// PHP: `start()`.
    pub async fn start(&self) {
        if let Err(e) = self.adapter.start().await {
            self.fail(&e, Stage::Start);
        }
    }

    /// Shuts the server down.
    ///
    /// PHP: `shutdown()`.
    pub fn shutdown(&self) {
        if let Err(e) = self.adapter.shutdown() {
            self.fail(&e, Stage::Shutdown);
        }
    }

    /// Sends a text message to each of `connections`.
    ///
    /// PHP: `send(array $connections, string $message)`.
    pub fn send(&self, connections: &[ConnectionId], message: impl Into<Bytes>) {
        if let Err(e) = self.adapter.send(connections, message.into()) {
            self.fail(&e, Stage::Send);
        }
    }

    /// Closes a connection with a close code.
    ///
    /// PHP: `close(int $connection, int $code)`.
    pub fn close(&self, connection: ConnectionId, code: i64) {
        if let Err(e) = self.adapter.close(connection, code) {
            self.fail(&e, Stage::Close);
        }
    }

    /// Calls `hook` when the server starts.
    ///
    /// PHP: `onStart(callable $callback)`.
    pub fn on_start(&self, hook: impl Fn() + Send + Sync + 'static) -> &Self {
        self.guard(self.adapter.on_start(Arc::new(hook)), Stage::OnStart)
    }

    /// Calls `hook` with the worker id when a worker starts.
    ///
    /// PHP: `onWorkerStart(callable $callback)`.
    pub fn on_worker_start(&self, hook: impl Fn(i64) + Send + Sync + 'static) -> &Self {
        self.guard(self.adapter.on_worker_start(Arc::new(hook)), Stage::OnWorkerStart)
    }

    /// Calls `hook` with the worker id when a worker stops.
    ///
    /// PHP: `onWorkerStop(callable $callback)`.
    pub fn on_worker_stop(&self, hook: impl Fn(i64) + Send + Sync + 'static) -> &Self {
        self.guard(self.adapter.on_worker_stop(Arc::new(hook)), Stage::OnWorkerStop)
    }

    /// Calls `hook` with the connection and its handshake request when a
    /// connection is established.
    ///
    /// PHP: `onOpen(callable $callback)`.
    pub fn on_open(&self, hook: impl Fn(ConnectionId, &Request) + Send + Sync + 'static) -> &Self {
        self.guard(self.adapter.on_open(Arc::new(hook)), Stage::OnOpen)
    }

    /// Calls `hook` with the connection and the message for each message
    /// received.
    ///
    /// PHP: `onMessage(callable $callback)`.
    pub fn on_message(&self, hook: impl Fn(ConnectionId, &[u8]) + Send + Sync + 'static) -> &Self {
        self.guard(self.adapter.on_message(Arc::new(hook)), Stage::OnMessage)
    }

    /// Calls `hook` with the connection when a connection closes.
    ///
    /// PHP: `onClose(callable $callback)`.
    pub fn on_close(&self, hook: impl Fn(ConnectionId) + Send + Sync + 'static) -> &Self {
        self.guard(self.adapter.on_close(Arc::new(hook)), Stage::OnClose)
    }

    /// Calls `hook` with each plain HTTP request and the response to fill.
    ///
    /// PHP: `onRequest(callable $callback)`.
    pub fn on_request(&self, hook: impl Fn(&Request, &mut Response) + Send + Sync + 'static) -> &Self {
        self.guard(self.adapter.on_request(Arc::new(hook)), Stage::OnRequest)
    }

    /// Registers a callback for failures: it receives the error and the
    /// stage that failed.
    ///
    /// PHP: `error(callable $callback)`.
    pub fn error(&self, callback: impl Fn(&Error, Stage) + Send + Sync + 'static) -> &Self {
        self.errors.write().unwrap_or_else(|e| e.into_inner()).push(Arc::new(callback));
        self
    }

    fn guard(&self, result: Result<(), Error>, stage: Stage) -> &Self {
        if let Err(e) = result {
            self.fail(&e, stage);
        }
        self
    }

    fn fail(&self, error: &Error, stage: Stage) {
        // A snapshot, so a callback may register more callbacks.
        let callbacks = self.errors.read().unwrap_or_else(|e| e.into_inner()).clone();
        for callback in callbacks {
            callback(error, stage);
        }
    }
}
