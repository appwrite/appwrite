//! The interfaces a broker runs on: an [`Adapter`] serves connections and
//! calls the server's hooks, and its [`Timer`] reaps connections that go
//! silent.

use std::future::Future;
use std::sync::Arc;

use crate::Error;

/// Called once the adapter is listening.
pub type StartHook = Arc<dyn Fn() + Send + Sync>;
/// Called with each worker id as its worker starts.
pub type WorkerHook = Arc<dyn Fn(i64) + Send + Sync>;
/// Called with a connection id when it opens or closes.
pub type ConnectionHook = Arc<dyn Fn(i64) -> Result<(), Error> + Send + Sync>;
/// Called with a connection id and one whole MQTT packet it sent.
pub type ReceiveHook = Arc<dyn Fn(i64, &[u8]) -> Result<(), Error> + Send + Sync>;
/// Called with the id a [`Timer`] reaps or clears.
pub type TimerHook = Arc<dyn Fn(i64) + Send + Sync>;

/// The way back to the clients: write bytes to a connection, or close it.
/// Every [`Adapter`] is one; a [`Connection`](crate::Connection) keeps one to
/// deliver.
pub trait Outbound: Send + Sync {
    /// Writes one encoded packet to a connection.
    ///
    /// PHP: `Adapter::send(int $connection, string $message)`.
    fn send(&self, connection: i64, message: &[u8]) -> Result<(), Error>;

    /// Closes a connection.
    ///
    /// PHP: `Adapter::close(int $connection)`.
    fn close(&self, connection: i64) -> Result<(), Error>;
}

/// An MQTT transport: [`Tokio`](crate::Tokio) on the network,
/// [`Memory`](crate::Memory) in process. [`Server`](crate::Server) drives
/// one.
///
/// An adapter delivers whole MQTT packets, one per call of the receive hook,
/// however its carrier frames them. Adapters are cheap handles (clones share
/// one server); each `on_*` method replaces the hook set before.
///
/// PHP: `Utopia\Mqtt\Adapter`. Implement this trait to add a transport, as
/// PHP subclasses `Adapter`.
pub trait Adapter: Outbound + Clone + 'static {
    /// Sets the hook called once the adapter is listening.
    ///
    /// PHP: `onStart(callable $callback)`.
    fn on_start(&self, hook: StartHook);

    /// Sets the hook called with each worker id as its worker starts.
    ///
    /// PHP: `onWorkerStart(callable $callback)`.
    fn on_worker_start(&self, hook: WorkerHook);

    /// Sets the hook called when a connection opens.
    ///
    /// PHP: `onOpen(callable $callback)`.
    fn on_open(&self, hook: ConnectionHook);

    /// Sets the hook called with each packet a connection sends.
    ///
    /// PHP: `onReceive(callable $callback)`.
    fn on_receive(&self, hook: ReceiveHook);

    /// Sets the hook called when a connection closes, by either side.
    ///
    /// PHP: `onClose(callable $callback)`.
    fn on_close(&self, hook: ConnectionHook);

    /// The keep-alive timer; it closes a connection through this adapter
    /// when it ticks.
    ///
    /// PHP: `timer()`.
    fn timer(&self) -> &dyn Timer;

    /// Starts serving and runs until [`shutdown`](Adapter::shutdown).
    ///
    /// PHP: `start()`.
    fn start(&self) -> impl Future<Output = Result<(), Error>> + Send;

    /// Stops serving: closes every connection and makes
    /// [`start`](Adapter::start) return.
    ///
    /// PHP: `shutdown()`.
    fn shutdown(&self) -> Result<(), Error>;
}

/// Keep-alive deadlines by connection: a connection scheduled with a
/// keep-alive interval is reaped (the tick hook gets its id) once it has been
/// silent for longer than the interval allows.
///
/// PHP: `Utopia\Mqtt\Adapter\Swoole\Timer`. Implement it to replace the
/// [`TimingWheel`](crate::TimingWheel).
pub trait Timer: Send + Sync {
    /// Sets the hook called with each id whose deadline passed.
    ///
    /// PHP: `onTick(callable $callback)`.
    fn on_tick(&self, hook: TimerHook);

    /// Sets the hook called with each id [`clear`](Timer::clear) removes.
    ///
    /// PHP: `onClear(callable $callback)`.
    fn on_clear(&self, hook: TimerHook);

    /// (Re)schedules `id` for `keep_alive` seconds of silence; `0` or less
    /// unschedules it.
    ///
    /// PHP: `schedule(int $id, int $keepAlive)`.
    fn schedule(&self, id: i64, keep_alive: i64);

    /// Unschedules `id`.
    ///
    /// PHP: `clear(int $id)`.
    fn clear(&self, id: i64);
}

impl<T: Outbound + ?Sized> Outbound for Arc<T> {
    fn send(&self, connection: i64, message: &[u8]) -> Result<(), Error> {
        (**self).send(connection, message)
    }

    fn close(&self, connection: i64) -> Result<(), Error> {
        (**self).close(connection)
    }
}
