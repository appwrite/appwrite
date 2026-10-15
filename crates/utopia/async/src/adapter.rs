//! The engine contract: [`Adapter`], and the engines that implement it.
//!
//! An engine schedules tasks, keeps timers, drives sockets, runs blocking
//! work on a pool, and spawns processes. Everything the facade (the rest of
//! this crate) needs from it is in [`Adapter`] and the resource traits its
//! associated types implement ([`TcpStream`], [`File`], [`Child`], ...).
//! The facade wraps each engine type in its own type, so code that uses this
//! crate cannot reach an engine's extras, and switching the engine is a
//! feature flag, not a rewrite.
//!
//! The engine is chosen at compile time ([`Engine`](crate::Engine)), so every
//! call is a direct call into the engine: no boxing, no dynamic dispatch.
//!
//! An adapter implements the contract with its engine's types. Adapters live
//! in this crate, one module each, behind a feature of the engine's name:
//!
//! | Adapter | Feature | Engine |
//! |---|---|---|
//! | [`Tokio`] | `tokio` (default) | [Tokio](https://tokio.rs): a work-stealing scheduler, epoll/kqueue I/O |
//!
//! PHP: an abstract `Adapter` with one class per engine, as
//! `Utopia\Pools\Adapter` has `Adapter\Swoole` and `Adapter\Stack`; here the
//! engine is the one the Swoole extension is to PHP.

use std::ffi::OsStr;
use std::fmt::Debug;
use std::fs::Metadata;
use std::future::Future;
use std::io;
use std::net::SocketAddr;
#[cfg(unix)]
use std::path::PathBuf;
use std::pin::Pin;
use std::process::ExitStatus;
use std::time::{Duration, Instant};

use crate::io::{AsyncRead, AsyncSeek, AsyncWrite};
use crate::runtime::{Builder, Flavor};
#[cfg(unix)]
use crate::signal::Kind;
use crate::task::JoinError;

#[cfg(feature = "tokio")]
mod tokio;

#[cfg(feature = "tokio")]
pub use self::tokio::Tokio;

/// An async engine: what the facade runs tasks, timers and I/O on.
///
/// Every function is associated (no `self`): an engine is process-wide, as
/// Swoole's scheduler is, and the facade calls the engine of the build
/// ([`Engine`](crate::Engine)) directly.
///
/// PHP: the Swoole extension's coroutine API (`Swoole\Coroutine`,
/// `Swoole\Timer`, its sockets and hooks), as one interface an engine
/// implements.
pub trait Adapter: Sized + Send + Sync + 'static {
    /// A running engine instance.
    type Runtime: Runtime<Handle = Self::Handle>;
    /// A handle on a running engine instance.
    type Handle: Handle;
    /// A spawned task's handle.
    type JoinHandle<T: Send + 'static>: JoinHandle<T>;
    /// A timer future.
    type Sleep: Sleep;
    /// A TCP connection.
    type TcpStream: TcpStream;
    /// A TCP listener.
    type TcpListener: TcpListener<Stream = Self::TcpStream>;
    /// A TCP socket not yet connected or listening.
    type TcpSocket: TcpSocket<Stream = Self::TcpStream, Listener = Self::TcpListener>;
    /// A UDP socket.
    type UdpSocket: UdpSocket;
    /// A Unix domain socket connection.
    #[cfg(unix)]
    type UnixStream: UnixStream;
    /// An open file.
    type File: File;
    /// A process to spawn.
    type Command: Command<Child = Self::Child>;
    /// A spawned process.
    type Child: Child;
    /// A listener for a Unix signal.
    #[cfg(unix)]
    type Signal: Signal;
    /// The process's standard input.
    type Stdin: AsyncRead + Debug + Send + Sync + Unpin + 'static;
    /// The process's standard output.
    type Stdout: AsyncWrite + Debug + Send + Sync + Unpin + 'static;
    /// The process's standard error.
    type Stderr: AsyncWrite + Debug + Send + Sync + Unpin + 'static;

    /// Runs `future` as a new task on the current runtime.
    ///
    /// PHP: `go()`, `Swoole\Coroutine::create()`.
    fn spawn<F>(future: F) -> Self::JoinHandle<F::Output>
    where
        F: Future + Send + 'static,
        F::Output: Send + 'static;

    /// Runs `future` as a new task on the runtime `handle` refers to.
    fn spawn_on<F>(handle: &Self::Handle, future: F) -> Self::JoinHandle<F::Output>
    where
        F: Future + Send + 'static,
        F::Output: Send + 'static;

    /// Runs blocking work on the engine's blocking pool.
    fn spawn_blocking<F, R>(work: F) -> Self::JoinHandle<R>
    where
        F: FnOnce() -> R + Send + 'static,
        R: Send + 'static;

    /// Runs blocking work on the current thread without stalling the
    /// runtime's other tasks where the engine can hand them to another
    /// worker; in place otherwise.
    fn block_in_place<F: FnOnce() -> R, R>(work: F) -> R;

    /// Gives the other tasks of the runtime a turn.
    ///
    /// PHP: `Swoole\Coroutine::yield()` followed by a resume.
    fn yield_now() -> impl Future<Output = ()> + Send;

    /// The id of the task running now; `None` outside a task.
    ///
    /// PHP: `Swoole\Coroutine::getCid()` (`-1` outside a coroutine).
    fn task_id() -> Option<u64>;

    /// A timer that completes at `deadline`.
    ///
    /// PHP: `Swoole\Coroutine::sleep()`.
    fn sleep_until(deadline: Instant) -> Self::Sleep;

    /// The addresses `host` resolves to, with `port`.
    ///
    /// PHP: `Swoole\Coroutine\System::getaddrinfo()`.
    fn lookup_host(host: String, port: u16) -> impl Future<Output = io::Result<Vec<SocketAddr>>> + Send;

    /// Completes on the next Ctrl-C (`SIGINT`).
    fn ctrl_c() -> impl Future<Output = io::Result<()>> + Send;

    /// The process's standard input.
    fn stdin() -> Self::Stdin;

    /// The process's standard output.
    fn stdout() -> Self::Stdout;

    /// The process's standard error.
    fn stderr() -> Self::Stderr;
}

/// A running engine instance: its worker threads, timers and I/O driver.
///
/// PHP: `Swoole\Coroutine\Scheduler`.
pub trait Runtime: Send + Sync + Sized + 'static {
    /// A handle on this runtime.
    type Handle: Handle;

    /// Starts a runtime configured by `builder`.
    fn new(builder: &Builder) -> io::Result<Self>;

    /// A handle on this runtime.
    fn handle(&self) -> Self::Handle;

    /// Runs `future` to its end on this runtime, driving its tasks, timers
    /// and I/O, and blocks the calling thread until then.
    fn block_on<F: Future>(&self, future: F) -> F::Output;

    /// Stops the runtime, waiting up to `timeout` for blocking work.
    fn shutdown_timeout(self, timeout: Duration);
}

/// A handle on a running engine instance, cheap to clone.
pub trait Handle: Clone + Send + Sync + 'static {
    /// The runtime the calling thread runs in; `None` outside one.
    fn current() -> Option<Self>;

    /// The runtime's scheduler.
    fn flavor(&self) -> Flavor;

    /// Runs `future` to its end with this runtime's timers and I/O, blocking
    /// the calling thread.
    fn block_on<F: Future>(&self, future: F) -> F::Output;
}

/// A spawned task: awaiting it gives the task's output, or why it has none.
pub trait JoinHandle<T>: Future<Output = Result<T, JoinError>> + Send + Unpin + 'static {
    /// Cancels the task: it stops at its next await point.
    fn abort(&self);

    /// Whether the task has ended (finished, panicked or been cancelled).
    fn is_finished(&self) -> bool;

    /// The task's id.
    fn id(&self) -> u64;
}

/// A timer future: completes at its deadline.
pub trait Sleep: Future<Output = ()> + Send + Sync + 'static {
    /// When the timer completes.
    fn deadline(&self) -> Instant;

    /// Whether the deadline has passed.
    fn is_elapsed(&self) -> bool;

    /// Moves the deadline, also when the timer has completed.
    fn reset(self: Pin<&mut Self>, deadline: Instant);
}

/// A TCP connection.
pub trait TcpStream: AsyncRead + AsyncWrite + Debug + Send + Sync + Unpin + Sized + 'static {
    /// The reading half of a split connection.
    type ReadHalf: AsyncRead + Debug + Send + Sync + Unpin + 'static;
    /// The writing half of a split connection.
    type WriteHalf: AsyncWrite + Debug + Send + Sync + Unpin + 'static;

    /// Connects to `addr`.
    fn connect(addr: SocketAddr) -> impl Future<Output = io::Result<Self>> + Send;

    /// Takes over a connected standard library stream (in non-blocking mode).
    fn from_std(stream: std::net::TcpStream) -> io::Result<Self>;

    /// This end's address.
    fn local_addr(&self) -> io::Result<SocketAddr>;

    /// The other end's address.
    fn peer_addr(&self) -> io::Result<SocketAddr>;

    /// Whether Nagle's algorithm is off.
    fn nodelay(&self) -> io::Result<bool>;

    /// Turns Nagle's algorithm off (`true`) or on.
    fn set_nodelay(&self, nodelay: bool) -> io::Result<()>;

    /// Turns TCP keepalive on, probing after `time` idle, or off (`None`).
    fn set_keepalive(&self, time: Option<Duration>) -> io::Result<()>;

    /// Splits the connection into halves that can be used from two tasks.
    fn into_split(self) -> (Self::ReadHalf, Self::WriteHalf);
}

/// A TCP listener.
pub trait TcpListener: Debug + Send + Sync + Sized + 'static {
    /// The connections it accepts.
    type Stream;

    /// Listens on `addr`.
    fn bind(addr: SocketAddr) -> impl Future<Output = io::Result<Self>> + Send;

    /// Takes over a bound standard library listener (in non-blocking mode).
    fn from_std(listener: std::net::TcpListener) -> io::Result<Self>;

    /// Waits for the next connection.
    fn accept(&self) -> impl Future<Output = io::Result<(Self::Stream, SocketAddr)>> + Send + '_;

    /// The address it listens on.
    fn local_addr(&self) -> io::Result<SocketAddr>;
}

/// A TCP socket to configure before it connects or listens.
pub trait TcpSocket: Debug + Send + Sync + Sized + 'static {
    /// The connection [`TcpSocket::connect`] makes.
    type Stream;
    /// The listener [`TcpSocket::listen`] makes.
    type Listener;

    /// A new IPv4 socket.
    fn new_v4() -> io::Result<Self>;

    /// A new IPv6 socket.
    fn new_v6() -> io::Result<Self>;

    /// Allows binding an address in `TIME_WAIT` (`SO_REUSEADDR`).
    fn set_reuseaddr(&self, reuseaddr: bool) -> io::Result<()>;

    /// Allows several sockets to bind the same port (`SO_REUSEPORT`).
    #[cfg(unix)]
    fn set_reuseport(&self, reuseport: bool) -> io::Result<()>;

    /// The send buffer size (`SO_SNDBUF`).
    fn set_send_buffer_size(&self, size: u32) -> io::Result<()>;

    /// The receive buffer size (`SO_RCVBUF`).
    fn set_recv_buffer_size(&self, size: u32) -> io::Result<()>;

    /// Turns Nagle's algorithm off (`true`) or on.
    fn set_nodelay(&self, nodelay: bool) -> io::Result<()>;

    /// Binds the socket to `addr`.
    fn bind(&self, addr: SocketAddr) -> io::Result<()>;

    /// The address the socket is bound to.
    fn local_addr(&self) -> io::Result<SocketAddr>;

    /// Connects to `addr`.
    fn connect(self, addr: SocketAddr) -> impl Future<Output = io::Result<Self::Stream>> + Send;

    /// Listens, queueing up to `backlog` connections.
    fn listen(self, backlog: u32) -> io::Result<Self::Listener>;
}

/// A UDP socket.
pub trait UdpSocket: Debug + Send + Sync + Sized + 'static {
    /// Binds a socket to `addr`.
    fn bind(addr: SocketAddr) -> impl Future<Output = io::Result<Self>> + Send;

    /// Takes over a bound standard library socket (in non-blocking mode).
    fn from_std(socket: std::net::UdpSocket) -> io::Result<Self>;

    /// The address the socket is bound to.
    fn local_addr(&self) -> io::Result<SocketAddr>;

    /// The address the socket is connected to.
    fn peer_addr(&self) -> io::Result<SocketAddr>;

    /// Sends to and receives from `addr` only.
    fn connect(&self, addr: SocketAddr) -> impl Future<Output = io::Result<()>> + Send + '_;

    /// Sends a datagram to the connected address.
    fn send<'a>(&'a self, buf: &'a [u8]) -> impl Future<Output = io::Result<usize>> + Send + 'a;

    /// Receives a datagram from the connected address.
    fn recv<'a>(&'a self, buf: &'a mut [u8]) -> impl Future<Output = io::Result<usize>> + Send + 'a;

    /// Sends a datagram to `target`.
    fn send_to<'a>(&'a self, buf: &'a [u8], target: SocketAddr) -> impl Future<Output = io::Result<usize>> + Send + 'a;

    /// Receives a datagram and the address it came from.
    fn recv_from<'a>(&'a self, buf: &'a mut [u8]) -> impl Future<Output = io::Result<(usize, SocketAddr)>> + Send + 'a;
}

/// A Unix domain socket connection.
#[cfg(unix)]
pub trait UnixStream: AsyncRead + AsyncWrite + Debug + Send + Sync + Unpin + Sized + 'static {
    /// Connects to the socket at `path`.
    fn connect(path: PathBuf) -> impl Future<Output = io::Result<Self>> + Send;
}

/// An open file. Opening is the facade's ([`crate::fs::OpenOptions`]): it
/// opens a standard library file on the blocking pool and hands it over.
pub trait File: AsyncRead + AsyncWrite + AsyncSeek + Debug + Send + Sync + Unpin + Sized + 'static {
    /// Takes over an open standard library file.
    fn from_std(file: std::fs::File) -> Self;

    /// The standard library file, once pending work is done.
    fn into_std(self) -> impl Future<Output = std::fs::File> + Send;

    /// The file's metadata.
    fn metadata(&self) -> impl Future<Output = io::Result<Metadata>> + Send + '_;

    /// Truncates or extends the file to `size` bytes.
    fn set_len(&self, size: u64) -> impl Future<Output = io::Result<()>> + Send + '_;

    /// Flushes the file's data and metadata to disk.
    fn sync_all(&self) -> impl Future<Output = io::Result<()>> + Send + '_;

    /// Flushes the file's data to disk.
    fn sync_data(&self) -> impl Future<Output = io::Result<()>> + Send + '_;
}

/// A process to spawn: a standard library command the engine spawns.
pub trait Command: Debug + Send + Sync + Sized + 'static {
    /// The process [`Command::spawn`] starts.
    type Child;

    /// A command running `program`.
    fn new(program: &OsStr) -> Self;

    /// The standard library command (program, arguments, environment,
    /// working directory, standard streams).
    fn as_std(&self) -> &std::process::Command;

    /// The standard library command, to configure.
    fn as_std_mut(&mut self) -> &mut std::process::Command;

    /// Kills the process when its [`Child`] is dropped.
    fn kill_on_drop(&mut self, kill: bool);

    /// Starts the process.
    fn spawn(&mut self) -> io::Result<Self::Child>;
}

/// A spawned process.
pub trait Child: Debug + Send + Sync + Sized + 'static {
    /// The process's standard input, when piped.
    type Stdin: AsyncWrite + Debug + Send + Sync + Unpin + 'static;
    /// The process's standard output, when piped.
    type Stdout: AsyncRead + Debug + Send + Sync + Unpin + 'static;
    /// The process's standard error, when piped.
    type Stderr: AsyncRead + Debug + Send + Sync + Unpin + 'static;

    /// The OS process id; `None` once it has been waited for.
    fn id(&self) -> Option<u32>;

    /// Takes the standard input pipe.
    fn take_stdin(&mut self) -> Option<Self::Stdin>;

    /// Takes the standard output pipe.
    fn take_stdout(&mut self) -> Option<Self::Stdout>;

    /// Takes the standard error pipe.
    fn take_stderr(&mut self) -> Option<Self::Stderr>;

    /// Waits for the process to exit.
    fn wait(&mut self) -> impl Future<Output = io::Result<ExitStatus>> + Send + '_;

    /// The exit status if the process has exited, without waiting.
    fn try_wait(&mut self) -> io::Result<Option<ExitStatus>>;

    /// Sends the kill signal, without waiting for the process to exit.
    fn start_kill(&mut self) -> io::Result<()>;
}

/// A listener for one kind of Unix signal.
#[cfg(unix)]
pub trait Signal: Debug + Send + Sync + Sized + 'static {
    /// Starts listening for `kind`.
    fn new(kind: Kind) -> io::Result<Self>;

    /// Waits for the next signal; `None` when no more can arrive.
    fn recv(&mut self) -> impl Future<Output = Option<()>> + Send + '_;
}
