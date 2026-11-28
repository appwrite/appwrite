//! An in-process adapter: connections and packets are driven by calls, and
//! what the broker sends and closes is recorded.

use std::collections::HashMap;
use std::sync::{Arc, Mutex, MutexGuard, RwLock, Weak};

use indexmap::IndexSet;

use crate::adapter::{Adapter, ConnectionHook, Outbound, ReceiveHook, StartHook, Timer, WorkerHook};
use crate::{Error, NoTimer};

/// An adapter operation [`Memory::fail`] can make fail.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Operation {
    /// [`Outbound::send`].
    Send,
    /// [`Outbound::close`].
    Close,
    /// [`Adapter::start`].
    Start,
    /// [`Adapter::shutdown`].
    Shutdown,
}

/// A packet the broker sent to one connection.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Sent {
    /// The connection.
    pub connection: i64,
    /// The encoded packet.
    pub data: Vec<u8>,
}

#[derive(Default)]
struct Hooks {
    start: Option<StartHook>,
    worker_start: Option<WorkerHook>,
    open: Option<ConnectionHook>,
    receive: Option<ReceiveHook>,
    close: Option<ConnectionHook>,
}

#[derive(Default)]
struct State {
    started: bool,
    open: IndexSet<i64>,
    sent: Vec<Sent>,
    closed: Vec<i64>,
    failures: HashMap<Operation, String>,
}

struct Inner {
    hooks: RwLock<Hooks>,
    state: Mutex<State>,
    timer: Arc<dyn Timer>,
}

/// An adapter without a network, for tests: the caller opens connections,
/// delivers packets and disconnects clients, and reads back what the broker
/// sent and which connections it closed. Hooks run on the caller's thread.
///
/// - [`open`](Memory::open), [`receive`](Memory::receive) and
///   [`disconnect`](Memory::disconnect) call the open, receive and close
///   hooks, and return what they return.
/// - [`send`](Outbound::send) records a [`Sent`] packet.
/// - [`close`](Outbound::close) records the connection and, when it is
///   open, closes it and calls the close hook, as Swoole does.
/// - [`start`](Adapter::start) calls the start hook and the worker start
///   hook (worker 0) and returns at once.
/// - [`fail`](Memory::fail) makes an operation fail with an
///   [`Error::Runtime`].
/// - The timer's tick closes the connection, as on the network.
///
/// Clones share the same adapter.
///
/// ```
/// use utopia_mqtt::Memory;
/// use utopia_mqtt::adapter::{Adapter, Outbound};
///
/// let adapter = Memory::new();
/// adapter.open(1)?;
/// adapter.send(1, b"\xD0\x00")?;
/// adapter.close(1)?;
/// assert_eq!(adapter.sent()[0].data, b"\xD0\x00");
/// assert_eq!(adapter.closed(), [1]);
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// PHP: none; the compat suite's twin is `tests/compat/mqtt/Memory.php`.
#[derive(Clone)]
pub struct Memory {
    inner: Arc<Inner>,
}

impl Default for Memory {
    fn default() -> Self {
        Self::new()
    }
}

impl Memory {
    /// An adapter whose timer never reaps ([`NoTimer`]).
    pub fn new() -> Self {
        Self::with_timer(Arc::new(NoTimer))
    }

    /// An adapter on `timer`, whose tick closes the connection.
    pub fn with_timer(timer: Arc<dyn Timer>) -> Self {
        let inner = Arc::new(Inner { hooks: RwLock::default(), state: Mutex::default(), timer });
        let weak: Weak<Inner> = Arc::downgrade(&inner);
        inner.timer.on_tick(Arc::new(move |id| {
            if let Some(inner) = weak.upgrade() {
                let _ = Memory { inner }.close(id);
            }
        }));
        Self { inner }
    }

    fn state(&self) -> MutexGuard<'_, State> {
        self.inner.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn failure(&self, operation: Operation) -> Result<(), Error> {
        match self.state().failures.get(&operation) {
            Some(message) => Err(Error::Runtime(message.clone())),
            None => Ok(()),
        }
    }

    /// Makes `operation` fail with an [`Error::Runtime`] carrying `message`
    /// from now on.
    pub fn fail(&self, operation: Operation, message: &str) {
        self.state().failures.insert(operation, message.to_owned());
    }

    /// A client connects as `connection`: the open hook runs.
    pub fn open(&self, connection: i64) -> Result<(), Error> {
        self.state().open.insert(connection);
        let hook = self.inner.hooks.read().unwrap_or_else(|e| e.into_inner()).open.clone();
        hook.map_or(Ok(()), |hook| hook(connection))
    }

    /// `connection` sends one whole packet: the receive hook runs.
    pub fn receive(&self, connection: i64, packet: &[u8]) -> Result<(), Error> {
        let hook = self.inner.hooks.read().unwrap_or_else(|e| e.into_inner()).receive.clone();
        hook.map_or(Ok(()), |hook| hook(connection, packet))
    }

    /// The client of `connection` goes away: when it was open, the close
    /// hook runs. Returns whether it was open.
    pub fn disconnect(&self, connection: i64) -> Result<bool, Error> {
        if !self.state().open.shift_remove(&connection) {
            return Ok(false);
        }
        let hook = self.inner.hooks.read().unwrap_or_else(|e| e.into_inner()).close.clone();
        hook.map_or(Ok(()), |hook| hook(connection)).map(|()| true)
    }

    /// Every packet sent, oldest first.
    pub fn sent(&self) -> Vec<Sent> {
        self.state().sent.clone()
    }

    /// The packets sent since the last call, oldest first.
    pub fn take_sent(&self) -> Vec<Sent> {
        std::mem::take(&mut self.state().sent)
    }

    /// Every connection closed by the broker, in order.
    pub fn closed(&self) -> Vec<i64> {
        self.state().closed.clone()
    }

    /// The open connections, in the order they opened.
    pub fn connections(&self) -> Vec<i64> {
        self.state().open.iter().copied().collect()
    }

    /// Whether [`start`](Adapter::start) ran (and no shutdown since).
    pub fn is_started(&self) -> bool {
        self.state().started
    }
}

impl Outbound for Memory {
    fn send(&self, connection: i64, message: &[u8]) -> Result<(), Error> {
        self.failure(Operation::Send)?;
        self.state().sent.push(Sent { connection, data: message.to_vec() });
        Ok(())
    }

    fn close(&self, connection: i64) -> Result<(), Error> {
        self.failure(Operation::Close)?;
        let was_open = {
            let mut state = self.state();
            state.closed.push(connection);
            state.open.shift_remove(&connection)
        };
        if !was_open {
            return Ok(());
        }
        let hook = self.inner.hooks.read().unwrap_or_else(|e| e.into_inner()).close.clone();
        hook.map_or(Ok(()), |hook| hook(connection))
    }
}

impl Adapter for Memory {
    fn on_start(&self, hook: StartHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).start = Some(hook);
    }

    fn on_worker_start(&self, hook: WorkerHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).worker_start = Some(hook);
    }

    fn on_open(&self, hook: ConnectionHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).open = Some(hook);
    }

    fn on_receive(&self, hook: ReceiveHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).receive = Some(hook);
    }

    fn on_close(&self, hook: ConnectionHook) {
        self.inner.hooks.write().unwrap_or_else(|e| e.into_inner()).close = Some(hook);
    }

    fn timer(&self) -> &dyn Timer {
        &*self.inner.timer
    }

    async fn start(&self) -> Result<(), Error> {
        self.failure(Operation::Start)?;
        self.state().started = true;
        let (start, worker) = {
            let hooks = self.inner.hooks.read().unwrap_or_else(|e| e.into_inner());
            (hooks.start.clone(), hooks.worker_start.clone())
        };
        if let Some(start) = start {
            start();
        }
        if let Some(worker) = worker {
            worker(0);
        }
        Ok(())
    }

    fn shutdown(&self) -> Result<(), Error> {
        self.failure(Operation::Shutdown)?;
        self.state().started = false;
        Ok(())
    }
}
