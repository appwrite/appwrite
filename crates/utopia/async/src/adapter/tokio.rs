//! The Tokio adapter: the contract on Tokio's multi-threaded work-stealing
//! scheduler (or its current-thread one), its timer wheel, its epoll/kqueue
//! I/O driver, its blocking pool, processes and signals.

use std::ffi::OsStr;
use std::fmt::{self, Write as _};
use std::fs::Metadata;
use std::future::Future;
use std::io;
use std::net::SocketAddr;
#[cfg(unix)]
use std::path::PathBuf;
use std::pin::Pin;
use std::process::ExitStatus;
use std::task::{Context, Poll};
use std::time::{Duration, Instant};

use ::tokio::runtime::RuntimeFlavor;

use crate::runtime::{Builder, Flavor};
#[cfg(unix)]
use crate::signal::Kind;
use crate::task::JoinError;

/// The Tokio engine.
///
/// PHP: the Swoole extension.
#[derive(Debug, Clone, Copy, Default)]
pub struct Tokio;

impl super::Adapter for Tokio {
    type Runtime = ::tokio::runtime::Runtime;
    type Handle = ::tokio::runtime::Handle;
    type JoinHandle<T: Send + 'static> = Task<T>;
    type Sleep = ::tokio::time::Sleep;
    type TcpStream = ::tokio::net::TcpStream;
    type TcpListener = ::tokio::net::TcpListener;
    type TcpSocket = ::tokio::net::TcpSocket;
    type UdpSocket = ::tokio::net::UdpSocket;
    #[cfg(unix)]
    type UnixStream = ::tokio::net::UnixStream;
    type File = ::tokio::fs::File;
    type Command = ::tokio::process::Command;
    type Child = ::tokio::process::Child;
    #[cfg(unix)]
    type Signal = ::tokio::signal::unix::Signal;
    type Stdin = ::tokio::io::Stdin;
    type Stdout = ::tokio::io::Stdout;
    type Stderr = ::tokio::io::Stderr;

    #[inline]
    fn spawn<F>(future: F) -> Task<F::Output>
    where
        F: Future + Send + 'static,
        F::Output: Send + 'static,
    {
        Task(::tokio::spawn(future))
    }

    #[inline]
    fn spawn_on<F>(handle: &::tokio::runtime::Handle, future: F) -> Task<F::Output>
    where
        F: Future + Send + 'static,
        F::Output: Send + 'static,
    {
        Task(handle.spawn(future))
    }

    #[inline]
    fn spawn_blocking<F, R>(work: F) -> Task<R>
    where
        F: FnOnce() -> R + Send + 'static,
        R: Send + 'static,
    {
        Task(::tokio::task::spawn_blocking(work))
    }

    /// On a multi-threaded runtime the worker hands its other tasks to
    /// another worker first (`block_in_place`); a current-thread runtime has
    /// no other worker, so the work runs in place, as it does outside a
    /// runtime.
    fn block_in_place<F: FnOnce() -> R, R>(work: F) -> R {
        match ::tokio::runtime::Handle::try_current() {
            Ok(handle) if handle.runtime_flavor() == RuntimeFlavor::MultiThread => ::tokio::task::block_in_place(work),
            _ => work(),
        }
    }

    #[inline]
    fn yield_now() -> impl Future<Output = ()> + Send {
        ::tokio::task::yield_now()
    }

    #[inline]
    fn task_id() -> Option<u64> {
        ::tokio::task::try_id().map(id)
    }

    #[inline]
    fn sleep_until(deadline: Instant) -> ::tokio::time::Sleep {
        ::tokio::time::sleep_until(deadline.into())
    }

    async fn lookup_host(host: String, port: u16) -> io::Result<Vec<SocketAddr>> {
        Ok(::tokio::net::lookup_host((host.as_str(), port)).await?.collect())
    }

    #[inline]
    fn ctrl_c() -> impl Future<Output = io::Result<()>> + Send {
        ::tokio::signal::ctrl_c()
    }

    #[inline]
    fn stdin() -> ::tokio::io::Stdin {
        ::tokio::io::stdin()
    }

    #[inline]
    fn stdout() -> ::tokio::io::Stdout {
        ::tokio::io::stdout()
    }

    #[inline]
    fn stderr() -> ::tokio::io::Stderr {
        ::tokio::io::stderr()
    }
}

/// A task id as a number: Tokio shows its ids as their number and keeps the
/// number private, so it is read back from the text, without allocating.
fn id(id: ::tokio::task::Id) -> u64 {
    struct Digits(u64);
    impl fmt::Write for Digits {
        fn write_str(&mut self, text: &str) -> fmt::Result {
            for digit in text.bytes().filter(u8::is_ascii_digit) {
                self.0 = self.0.wrapping_mul(10).wrapping_add(u64::from(digit - b'0'));
            }
            Ok(())
        }
    }
    let mut digits = Digits(0);
    let _ = write!(digits, "{id}");
    digits.0
}

impl super::Runtime for ::tokio::runtime::Runtime {
    type Handle = ::tokio::runtime::Handle;

    fn new(builder: &Builder) -> io::Result<Self> {
        let mut tokio = match builder.flavor {
            Flavor::CurrentThread => ::tokio::runtime::Builder::new_current_thread(),
            Flavor::MultiThread => ::tokio::runtime::Builder::new_multi_thread(),
        };
        tokio.enable_all();
        if let Some(threads) = builder.threads {
            tokio.worker_threads(threads);
        }
        if let Some(threads) = builder.blocking_threads {
            tokio.max_blocking_threads(threads);
        }
        if let Some(size) = builder.stack_size {
            tokio.thread_stack_size(size);
        }
        if let Some(name) = &builder.name {
            tokio.thread_name(name.clone());
        }
        tokio.build()
    }

    #[inline]
    fn handle(&self) -> ::tokio::runtime::Handle {
        ::tokio::runtime::Runtime::handle(self).clone()
    }

    #[inline]
    fn block_on<F: Future>(&self, future: F) -> F::Output {
        ::tokio::runtime::Runtime::block_on(self, future)
    }

    fn shutdown_timeout(self, timeout: Duration) {
        ::tokio::runtime::Runtime::shutdown_timeout(self, timeout);
    }
}

impl super::Handle for ::tokio::runtime::Handle {
    #[inline]
    fn current() -> Option<Self> {
        ::tokio::runtime::Handle::try_current().ok()
    }

    fn flavor(&self) -> Flavor {
        match self.runtime_flavor() {
            RuntimeFlavor::CurrentThread => Flavor::CurrentThread,
            _ => Flavor::MultiThread,
        }
    }

    #[inline]
    fn block_on<F: Future>(&self, future: F) -> F::Output {
        ::tokio::runtime::Handle::block_on(self, future)
    }
}

/// A Tokio task, whose failure is a [`JoinError`].
#[derive(Debug)]
pub struct Task<T>(::tokio::task::JoinHandle<T>);

impl<T: Send + 'static> Future for Task<T> {
    type Output = Result<T, JoinError>;

    #[inline]
    fn poll(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Self::Output> {
        Pin::new(&mut self.0).poll(cx).map(|result| {
            result.map_err(|error| {
                let task = id(error.id());
                match error.try_into_panic() {
                    Ok(payload) => JoinError::panicked(task, payload),
                    Err(_) => JoinError::cancelled(task),
                }
            })
        })
    }
}

impl<T: Send + 'static> super::JoinHandle<T> for Task<T> {
    #[inline]
    fn abort(&self) {
        self.0.abort();
    }

    #[inline]
    fn is_finished(&self) -> bool {
        self.0.is_finished()
    }

    #[inline]
    fn id(&self) -> u64 {
        id(self.0.id())
    }
}

impl super::Sleep for ::tokio::time::Sleep {
    #[inline]
    fn deadline(&self) -> Instant {
        ::tokio::time::Sleep::deadline(self).into_std()
    }

    #[inline]
    fn is_elapsed(&self) -> bool {
        ::tokio::time::Sleep::is_elapsed(self)
    }

    #[inline]
    fn reset(self: Pin<&mut Self>, deadline: Instant) {
        ::tokio::time::Sleep::reset(self, deadline.into());
    }
}

impl super::TcpStream for ::tokio::net::TcpStream {
    type ReadHalf = ::tokio::net::tcp::OwnedReadHalf;
    type WriteHalf = ::tokio::net::tcp::OwnedWriteHalf;

    #[inline]
    fn connect(addr: SocketAddr) -> impl Future<Output = io::Result<Self>> + Send {
        ::tokio::net::TcpStream::connect(addr)
    }

    #[inline]
    fn from_std(stream: std::net::TcpStream) -> io::Result<Self> {
        ::tokio::net::TcpStream::from_std(stream)
    }

    #[inline]
    fn local_addr(&self) -> io::Result<SocketAddr> {
        ::tokio::net::TcpStream::local_addr(self)
    }

    #[inline]
    fn peer_addr(&self) -> io::Result<SocketAddr> {
        ::tokio::net::TcpStream::peer_addr(self)
    }

    #[inline]
    fn nodelay(&self) -> io::Result<bool> {
        ::tokio::net::TcpStream::nodelay(self)
    }

    #[inline]
    fn set_nodelay(&self, nodelay: bool) -> io::Result<()> {
        ::tokio::net::TcpStream::set_nodelay(self, nodelay)
    }

    fn set_keepalive(&self, time: Option<Duration>) -> io::Result<()> {
        let socket = socket2::SockRef::from(self);
        match time {
            Some(time) => socket.set_tcp_keepalive(&socket2::TcpKeepalive::new().with_time(time)),
            None => socket.set_keepalive(false),
        }
    }

    #[inline]
    fn into_split(self) -> (Self::ReadHalf, Self::WriteHalf) {
        ::tokio::net::TcpStream::into_split(self)
    }
}

impl super::TcpListener for ::tokio::net::TcpListener {
    type Stream = ::tokio::net::TcpStream;

    #[inline]
    fn bind(addr: SocketAddr) -> impl Future<Output = io::Result<Self>> + Send {
        ::tokio::net::TcpListener::bind(addr)
    }

    #[inline]
    fn from_std(listener: std::net::TcpListener) -> io::Result<Self> {
        ::tokio::net::TcpListener::from_std(listener)
    }

    #[inline]
    fn accept(&self) -> impl Future<Output = io::Result<(Self::Stream, SocketAddr)>> + Send + '_ {
        ::tokio::net::TcpListener::accept(self)
    }

    #[inline]
    fn local_addr(&self) -> io::Result<SocketAddr> {
        ::tokio::net::TcpListener::local_addr(self)
    }
}

impl super::TcpSocket for ::tokio::net::TcpSocket {
    type Stream = ::tokio::net::TcpStream;
    type Listener = ::tokio::net::TcpListener;

    #[inline]
    fn new_v4() -> io::Result<Self> {
        ::tokio::net::TcpSocket::new_v4()
    }

    #[inline]
    fn new_v6() -> io::Result<Self> {
        ::tokio::net::TcpSocket::new_v6()
    }

    #[inline]
    fn set_reuseaddr(&self, reuseaddr: bool) -> io::Result<()> {
        ::tokio::net::TcpSocket::set_reuseaddr(self, reuseaddr)
    }

    #[cfg(unix)]
    #[inline]
    fn set_reuseport(&self, reuseport: bool) -> io::Result<()> {
        ::tokio::net::TcpSocket::set_reuseport(self, reuseport)
    }

    #[inline]
    fn set_send_buffer_size(&self, size: u32) -> io::Result<()> {
        ::tokio::net::TcpSocket::set_send_buffer_size(self, size)
    }

    #[inline]
    fn set_recv_buffer_size(&self, size: u32) -> io::Result<()> {
        ::tokio::net::TcpSocket::set_recv_buffer_size(self, size)
    }

    #[inline]
    fn set_nodelay(&self, nodelay: bool) -> io::Result<()> {
        ::tokio::net::TcpSocket::set_nodelay(self, nodelay)
    }

    #[inline]
    fn bind(&self, addr: SocketAddr) -> io::Result<()> {
        ::tokio::net::TcpSocket::bind(self, addr)
    }

    #[inline]
    fn local_addr(&self) -> io::Result<SocketAddr> {
        ::tokio::net::TcpSocket::local_addr(self)
    }

    #[inline]
    fn connect(self, addr: SocketAddr) -> impl Future<Output = io::Result<Self::Stream>> + Send {
        ::tokio::net::TcpSocket::connect(self, addr)
    }

    #[inline]
    fn listen(self, backlog: u32) -> io::Result<Self::Listener> {
        ::tokio::net::TcpSocket::listen(self, backlog)
    }
}

impl super::UdpSocket for ::tokio::net::UdpSocket {
    #[inline]
    fn bind(addr: SocketAddr) -> impl Future<Output = io::Result<Self>> + Send {
        ::tokio::net::UdpSocket::bind(addr)
    }

    #[inline]
    fn from_std(socket: std::net::UdpSocket) -> io::Result<Self> {
        ::tokio::net::UdpSocket::from_std(socket)
    }

    #[inline]
    fn local_addr(&self) -> io::Result<SocketAddr> {
        ::tokio::net::UdpSocket::local_addr(self)
    }

    #[inline]
    fn peer_addr(&self) -> io::Result<SocketAddr> {
        ::tokio::net::UdpSocket::peer_addr(self)
    }

    #[inline]
    fn connect(&self, addr: SocketAddr) -> impl Future<Output = io::Result<()>> + Send + '_ {
        ::tokio::net::UdpSocket::connect(self, addr)
    }

    #[inline]
    fn send<'a>(&'a self, buf: &'a [u8]) -> impl Future<Output = io::Result<usize>> + Send + 'a {
        ::tokio::net::UdpSocket::send(self, buf)
    }

    #[inline]
    fn recv<'a>(&'a self, buf: &'a mut [u8]) -> impl Future<Output = io::Result<usize>> + Send + 'a {
        ::tokio::net::UdpSocket::recv(self, buf)
    }

    #[inline]
    fn send_to<'a>(&'a self, buf: &'a [u8], target: SocketAddr) -> impl Future<Output = io::Result<usize>> + Send + 'a {
        ::tokio::net::UdpSocket::send_to(self, buf, target)
    }

    #[inline]
    fn recv_from<'a>(&'a self, buf: &'a mut [u8]) -> impl Future<Output = io::Result<(usize, SocketAddr)>> + Send + 'a {
        ::tokio::net::UdpSocket::recv_from(self, buf)
    }
}

#[cfg(unix)]
impl super::UnixStream for ::tokio::net::UnixStream {
    #[inline]
    fn connect(path: PathBuf) -> impl Future<Output = io::Result<Self>> + Send {
        ::tokio::net::UnixStream::connect(path)
    }
}

impl super::File for ::tokio::fs::File {
    #[inline]
    fn from_std(file: std::fs::File) -> Self {
        ::tokio::fs::File::from_std(file)
    }

    #[inline]
    fn into_std(self) -> impl Future<Output = std::fs::File> + Send {
        ::tokio::fs::File::into_std(self)
    }

    #[inline]
    fn metadata(&self) -> impl Future<Output = io::Result<Metadata>> + Send + '_ {
        ::tokio::fs::File::metadata(self)
    }

    #[inline]
    fn set_len(&self, size: u64) -> impl Future<Output = io::Result<()>> + Send + '_ {
        ::tokio::fs::File::set_len(self, size)
    }

    #[inline]
    fn sync_all(&self) -> impl Future<Output = io::Result<()>> + Send + '_ {
        ::tokio::fs::File::sync_all(self)
    }

    #[inline]
    fn sync_data(&self) -> impl Future<Output = io::Result<()>> + Send + '_ {
        ::tokio::fs::File::sync_data(self)
    }
}

impl super::Command for ::tokio::process::Command {
    type Child = ::tokio::process::Child;

    #[inline]
    fn new(program: &OsStr) -> Self {
        ::tokio::process::Command::new(program)
    }

    #[inline]
    fn as_std(&self) -> &std::process::Command {
        ::tokio::process::Command::as_std(self)
    }

    #[inline]
    fn as_std_mut(&mut self) -> &mut std::process::Command {
        ::tokio::process::Command::as_std_mut(self)
    }

    #[inline]
    fn kill_on_drop(&mut self, kill: bool) {
        ::tokio::process::Command::kill_on_drop(self, kill);
    }

    #[inline]
    fn spawn(&mut self) -> io::Result<Self::Child> {
        ::tokio::process::Command::spawn(self)
    }
}

impl super::Child for ::tokio::process::Child {
    type Stdin = ::tokio::process::ChildStdin;
    type Stdout = ::tokio::process::ChildStdout;
    type Stderr = ::tokio::process::ChildStderr;

    #[inline]
    fn id(&self) -> Option<u32> {
        ::tokio::process::Child::id(self)
    }

    #[inline]
    fn take_stdin(&mut self) -> Option<Self::Stdin> {
        self.stdin.take()
    }

    #[inline]
    fn take_stdout(&mut self) -> Option<Self::Stdout> {
        self.stdout.take()
    }

    #[inline]
    fn take_stderr(&mut self) -> Option<Self::Stderr> {
        self.stderr.take()
    }

    #[inline]
    fn wait(&mut self) -> impl Future<Output = io::Result<ExitStatus>> + Send + '_ {
        ::tokio::process::Child::wait(self)
    }

    #[inline]
    fn try_wait(&mut self) -> io::Result<Option<ExitStatus>> {
        ::tokio::process::Child::try_wait(self)
    }

    #[inline]
    fn start_kill(&mut self) -> io::Result<()> {
        ::tokio::process::Child::start_kill(self)
    }
}

#[cfg(unix)]
impl super::Signal for ::tokio::signal::unix::Signal {
    fn new(kind: Kind) -> io::Result<Self> {
        use ::tokio::signal::unix::SignalKind;

        ::tokio::signal::unix::signal(match kind {
            Kind::Interrupt => SignalKind::interrupt(),
            Kind::Terminate => SignalKind::terminate(),
            Kind::Hangup => SignalKind::hangup(),
            Kind::Quit => SignalKind::quit(),
            Kind::User1 => SignalKind::user_defined1(),
            Kind::User2 => SignalKind::user_defined2(),
        })
    }

    #[inline]
    fn recv(&mut self) -> impl Future<Output = Option<()>> + Send + '_ {
        ::tokio::signal::unix::Signal::recv(self)
    }
}
