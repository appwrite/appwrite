//! An in-process adapter: connections, messages and requests are driven by
//! calls, and what the server sends is recorded.

use std::collections::HashMap;
use std::sync::{Arc, Mutex, MutexGuard, RwLock};

use bytes::Bytes;
use indexmap::IndexSet;

use crate::adapter::{
    Adapter, CloseHook, ConnectionId, Hooks, MessageHook, OpenHook, Request, RequestHook, Response, StartHook,
    WorkerHook, response,
};
use crate::{Error, Stage};

/// A message the server sent to one connection.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Frame {
    /// The connection it was sent to.
    pub connection: ConnectionId,
    /// The message.
    pub data: Bytes,
}

/// A connection the server closed.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Closed {
    /// The connection.
    pub connection: ConnectionId,
    /// The close code the server gave.
    pub code: i64,
}

/// What the configuration setters were given; `None` for a setter not
/// called.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Settings {
    /// [`Adapter::set_package_max_length`].
    pub package_max_length: Option<i64>,
    /// [`Adapter::set_compression_enabled`].
    pub compression: Option<bool>,
    /// [`Adapter::set_worker_number`].
    pub workers: Option<i64>,
}

#[derive(Default)]
struct State {
    started: bool,
    open: IndexSet<ConnectionId>,
    frames: Vec<Frame>,
    closed: Vec<Closed>,
    settings: Settings,
    failures: HashMap<Stage, String>,
}

#[derive(Default)]
struct Inner {
    state: Mutex<State>,
    hooks: RwLock<Hooks>,
}

/// An adapter without a network, for tests: the caller opens connections,
/// delivers messages and requests, and reads back the frames the server
/// sent and the connections it closed. Hooks run on the caller's thread,
/// in the order PHP's servers call them.
///
/// - [`start`](Adapter::start) calls the start hook, then the worker start
///   hook for each worker (`0` to the worker number, one by default);
///   [`shutdown`](Adapter::shutdown) calls the worker stop hook for each
///   worker of a started server.
/// - [`send`](Adapter::send) records a [`Frame`] for each open connection
///   in the list and skips the others.
/// - [`close`](Adapter::close) closes an open connection, records it and
///   calls the close hook, as Swoole does.
/// - [`fail`](Memory::fail) makes one stage fail, to exercise
///   [`Server::error`](crate::Server::error).
///
/// Clones share the same server.
///
/// ```
/// use utopia_websocket::{Memory, Server};
///
/// let adapter = Memory::new();
/// let server = Server::new(adapter.clone());
/// server.on_open(|connection, _| println!("connected {connection}"));
///
/// adapter.open(7, &Default::default());
/// server.send(&[7, 8], "hello");
///
/// assert_eq!(adapter.frames().len(), 1); // 8 is not open
/// assert_eq!(adapter.connections(), [7]);
/// ```
///
/// ```php
/// // PHP has no in-memory adapter; tests/compat/websocket/Memory.php is
/// // the same adapter as a subclass of Utopia\WebSocket\Adapter.
/// ```
#[derive(Clone, Default)]
pub struct Memory {
    inner: Arc<Inner>,
}

impl Memory {
    /// A stopped server without connections.
    pub fn new() -> Self {
        Self::default()
    }

    fn state(&self) -> MutexGuard<'_, State> {
        self.inner.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn hooks(&self) -> Hooks {
        self.inner.hooks.read().unwrap_or_else(|e| e.into_inner()).clone()
    }

    fn check(&self, stage: Stage) -> Result<(), Error> {
        match self.state().failures.get(&stage) {
            Some(message) => Err(Error::Exception(message.clone())),
            None => Ok(()),
        }
    }

    fn set_hook(&self, stage: Stage, set: impl FnOnce(&mut Hooks)) -> Result<(), Error> {
        self.check(stage)?;
        set(&mut self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()));
        Ok(())
    }

    /// Makes the adapter method behind `stage` fail with an
    /// [`Error::Exception`] carrying `message`, from now on.
    pub fn fail(&self, stage: Stage, message: impl Into<String>) {
        self.state().failures.insert(stage, message.into());
    }

    /// A client connects: the connection opens and the open hook runs.
    pub fn open(&self, connection: ConnectionId, request: &Request) {
        self.state().open.insert(connection);
        if let Some(hook) = self.hooks().open {
            hook(connection, request);
        }
    }

    /// A client sends a message: the message hook runs if the connection is
    /// open. Returns whether it ran.
    pub fn message(&self, connection: ConnectionId, data: &[u8]) -> bool {
        if !self.state().open.contains(&connection) {
            return false;
        }
        let hook = self.inner.hooks.read().unwrap_or_else(|e| e.into_inner()).message.clone();
        if let Some(hook) = hook {
            hook(connection, data);
        }
        true
    }

    /// A client disconnects: an open connection closes and the close hook
    /// runs. Returns whether it was open.
    pub fn disconnect(&self, connection: ConnectionId) -> bool {
        if !self.state().open.shift_remove(&connection) {
            return false;
        }
        if let Some(hook) = self.hooks().close {
            hook(connection);
        }
        true
    }

    /// A plain HTTP request arrives: the request hook fills the response.
    pub fn request(&self, request: &Request) -> Response {
        let mut out = response();
        if let Some(hook) = self.hooks().request {
            hook(request, &mut out);
        }
        out
    }

    /// Whether the server is started.
    pub fn is_started(&self) -> bool {
        self.state().started
    }

    /// The open connections, in the order they opened.
    pub fn connections(&self) -> Vec<ConnectionId> {
        self.state().open.iter().copied().collect()
    }

    /// The frames sent so far, oldest first.
    pub fn frames(&self) -> Vec<Frame> {
        self.state().frames.clone()
    }

    /// Takes the frames sent so far, oldest first, leaving none.
    pub fn take_frames(&self) -> Vec<Frame> {
        std::mem::take(&mut self.state().frames)
    }

    /// The connections the server closed, oldest first.
    pub fn closed(&self) -> Vec<Closed> {
        self.state().closed.clone()
    }

    /// What the configuration setters were given.
    pub fn settings(&self) -> Settings {
        self.state().settings
    }

    fn workers(&self) -> i64 {
        self.state().settings.workers.unwrap_or(1)
    }
}

impl Adapter for Memory {
    async fn start(&self) -> Result<(), Error> {
        self.check(Stage::Start)?;
        self.state().started = true;
        let hooks = self.hooks();
        if let Some(hook) = hooks.start {
            hook();
        }
        if let Some(hook) = hooks.worker_start {
            for worker in 0..self.workers() {
                hook(worker);
            }
        }
        Ok(())
    }

    fn shutdown(&self) -> Result<(), Error> {
        self.check(Stage::Shutdown)?;
        let started = std::mem::replace(&mut self.state().started, false);
        if let (true, Some(hook)) = (started, self.hooks().worker_stop) {
            for worker in 0..self.workers() {
                hook(worker);
            }
        }
        Ok(())
    }

    fn send(&self, connections: &[ConnectionId], message: Bytes) -> Result<(), Error> {
        let mut state = self.state();
        if let Some(message) = state.failures.get(&Stage::Send) {
            return Err(Error::Exception(message.clone()));
        }
        let state = &mut *state;
        state.frames.reserve(connections.len());
        for &connection in connections {
            if state.open.contains(&connection) {
                state.frames.push(Frame { connection, data: message.clone() });
            }
        }
        Ok(())
    }

    fn close(&self, connection: ConnectionId, code: i64) -> Result<(), Error> {
        self.check(Stage::Close)?;
        {
            let mut state = self.state();
            if !state.open.shift_remove(&connection) {
                return Ok(());
            }
            state.closed.push(Closed { connection, code });
        }
        if let Some(hook) = self.hooks().close {
            hook(connection);
        }
        Ok(())
    }

    fn on_start(&self, hook: StartHook) -> Result<(), Error> {
        self.set_hook(Stage::OnStart, |h| h.start = Some(hook))
    }

    fn on_worker_start(&self, hook: WorkerHook) -> Result<(), Error> {
        self.set_hook(Stage::OnWorkerStart, |h| h.worker_start = Some(hook))
    }

    fn on_worker_stop(&self, hook: WorkerHook) -> Result<(), Error> {
        self.set_hook(Stage::OnWorkerStop, |h| h.worker_stop = Some(hook))
    }

    fn on_open(&self, hook: OpenHook) -> Result<(), Error> {
        self.set_hook(Stage::OnOpen, |h| h.open = Some(hook))
    }

    fn on_message(&self, hook: MessageHook) -> Result<(), Error> {
        self.set_hook(Stage::OnMessage, |h| h.message = Some(hook))
    }

    fn on_close(&self, hook: CloseHook) -> Result<(), Error> {
        self.set_hook(Stage::OnClose, |h| h.close = Some(hook))
    }

    fn on_request(&self, hook: RequestHook) -> Result<(), Error> {
        self.set_hook(Stage::OnRequest, |h| h.request = Some(hook))
    }

    fn set_package_max_length(&self, bytes: i64) {
        self.state().settings.package_max_length = Some(bytes);
    }

    fn set_compression_enabled(&self, enabled: bool) {
        self.state().settings.compression = Some(enabled);
    }

    fn set_worker_number(&self, workers: i64) {
        self.state().settings.workers = Some(workers);
    }
}
