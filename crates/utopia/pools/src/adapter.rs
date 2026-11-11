//! Where a pool keeps its idle connections: [`Adapter`], with [`Stack`]
//! (last in, first out, never waits) and [`Channel`] (first in, first out,
//! waits for a connection to come back).

use std::collections::VecDeque;
use std::future::Future;
use std::sync::{Mutex, MutexGuard};
use std::time::Duration;

use utopia_async::sync::Notify;

use crate::Connection;

/// Storage and wake-ups for a pool's idle connections. The pool does the
/// capacity accounting; an adapter only holds what is idle. Implement it to
/// keep idle connections another way.
///
/// PHP: `Utopia\Pools\Adapter`.
pub trait Adapter<T>: Send + Sync + 'static {
    /// Prepares to hold up to `size` idle connections (a sizing hint: the
    /// pool enforces the limit).
    ///
    /// PHP: `initialize($size)`.
    fn initialize(&mut self, size: usize);

    /// Adds an idle connection.
    fn push(&self, connection: Connection<T>);

    /// Takes an idle connection, waiting up to `timeout` for one when the
    /// adapter can wait; `None` when none was handed over.
    fn pop(&self, timeout: Duration) -> impl Future<Output = Option<Connection<T>>> + Send;

    /// The number of idle connections.
    fn count(&self) -> usize;

    /// Releases anyone waiting in [`Adapter::pop`] without handing over a
    /// connection, so they look at the pool again (capacity was freed).
    fn unblock(&self) {}

    /// Runs `f` atomically with respect to other pool operations.
    ///
    /// PHP: `synchronized(callable $callback)`. The pool keeps its own
    /// bookkeeping under a lock, so adapters need no lock of their own here.
    fn synchronized<R>(&self, f: impl FnOnce() -> R) -> R {
        f()
    }
}

fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

/// Idle connections on a stack: the most recently returned is reused first,
/// and [`Adapter::pop`] never waits.
///
/// PHP: `Utopia\Pools\Adapter\Stack`.
pub struct Stack<T> {
    items: Mutex<Vec<Connection<T>>>,
}

impl<T> Stack<T> {
    /// An empty stack.
    pub fn new() -> Self {
        Stack { items: Mutex::new(Vec::new()) }
    }
}

impl<T> Default for Stack<T> {
    /// An empty stack.
    fn default() -> Self {
        Self::new()
    }
}

impl<T: Send + Sync + 'static> Adapter<T> for Stack<T> {
    /// Empties the stack; the size is not needed.
    fn initialize(&mut self, _size: usize) {
        lock(&self.items).clear();
    }

    /// Pushes the connection on top.
    fn push(&self, connection: Connection<T>) {
        lock(&self.items).push(connection);
    }

    /// Pops the top connection; does not wait, so an empty stack yields at
    /// once (giving other tasks a turn).
    async fn pop(&self, _timeout: Duration) -> Option<Connection<T>> {
        let popped = lock(&self.items).pop();
        if popped.is_none() {
            utopia_async::yield_now().await;
        }
        popped
    }

    /// The number of connections on the stack.
    fn count(&self) -> usize {
        lock(&self.items).len()
    }
}

/// The shortest wait: a pop with no time left still looks once.
const POLL: Duration = Duration::from_millis(1);

/// Idle connections in a queue: the longest idle is reused first, and
/// [`Adapter::pop`] waits for a connection to come back (or for capacity to
/// be freed) up to its timeout. Wake-ups are coalesced to one pending
/// signal, and a woken waiter that takes a connection passes the signal on
/// while more remain.
///
/// PHP: `Utopia\Pools\Adapter\Swoole` (a coroutine channel and a release
/// signal channel); here the tasks of an async runtime wait instead of
/// coroutines.
pub struct Channel<T> {
    items: Mutex<VecDeque<Connection<T>>>,
    signal: Notify,
}

impl<T> Channel<T> {
    /// An empty channel.
    pub fn new() -> Self {
        Channel { items: Mutex::new(VecDeque::new()), signal: Notify::new() }
    }

    fn take(&self) -> Option<Connection<T>> {
        let mut items = lock(&self.items);
        let connection = items.pop_front();
        if !items.is_empty() {
            self.signal.notify_one();
        }
        connection
    }
}

impl<T> Default for Channel<T> {
    /// An empty channel.
    fn default() -> Self {
        Self::new()
    }
}

impl<T: Send + Sync + 'static> Adapter<T> for Channel<T> {
    /// Empties the channel and reserves room for `size` connections.
    fn initialize(&mut self, size: usize) {
        let mut items = lock(&self.items);
        items.clear();
        items.reserve(size);
    }

    /// Queues the connection and wakes a waiter.
    fn push(&self, connection: Connection<T>) {
        lock(&self.items).push_back(connection);
        self.unblock();
    }

    /// Takes the longest idle connection, waiting up to `timeout` (at least
    /// one short poll) for a wake-up when there is none.
    async fn pop(&self, timeout: Duration) -> Option<Connection<T>> {
        if self.count() > 0 {
            return self.take();
        }
        let budget = if timeout.is_zero() { POLL } else { timeout };
        let _ = utopia_async::timeout(budget, self.signal.notified()).await;
        if self.count() > 0 { self.take() } else { None }
    }

    /// The number of queued connections.
    fn count(&self) -> usize {
        lock(&self.items).len()
    }

    /// Wakes one waiter, or the next to wait when none is waiting.
    fn unblock(&self) {
        self.signal.notify_one();
    }
}
