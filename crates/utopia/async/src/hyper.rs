//! hyper on the engine (feature `hyper`): hyper is runtime-agnostic and asks
//! for an executor ([`Executor`]), a timer ([`Timer`]), streams implementing
//! its I/O traits ([`Io`]) and, for its client, a connector ([`Connector`]).
//!
//! ```
//! use utopia_async::hyper::{Connector, Executor};
//!
//! let mut http = Connector::new();
//! http.enforce_http(false);
//! let client: hyper_util::client::legacy::Client<Connector, http_body_util::Empty<bytes::Bytes>> =
//!     hyper_util::client::legacy::Client::builder(Executor).build(http);
//! # drop(client);
//! ```
//!
//! PHP: none; Swoole's HTTP server and coroutine HTTP client are the engine's
//! own.

use std::fmt;
use std::future::Future;
use std::io;
use std::pin::Pin;
use std::task::{Context, Poll};
use std::time::{Duration, Instant};

use hyper_util::client::legacy::connect::{Connected, Connection};

use crate::io::{AsyncRead, AsyncWrite, ReadBuf};
use crate::net::{TcpSocket, TcpStream};
use crate::time::Sleep;

/// Runs hyper's background work (connection tasks, HTTP/2 streams) as tasks
/// of the current runtime.
#[derive(Debug, Clone, Copy, Default)]
pub struct Executor;

impl<F> hyper::rt::Executor<F> for Executor
where
    F: Future + Send + 'static,
    F::Output: Send + 'static,
{
    #[inline]
    fn execute(&self, future: F) {
        crate::spawn(future);
    }
}

/// hyper's timeouts (header reads, keep-alive, pool idle) on the engine's
/// timers.
#[derive(Debug, Clone, Copy, Default)]
pub struct Timer;

impl hyper::rt::Timer for Timer {
    fn sleep(&self, duration: Duration) -> Pin<Box<dyn hyper::rt::Sleep>> {
        Box::pin(crate::time::sleep(duration))
    }

    fn sleep_until(&self, deadline: Instant) -> Pin<Box<dyn hyper::rt::Sleep>> {
        Box::pin(crate::time::sleep_until(deadline))
    }

    fn reset(&self, sleep: &mut Pin<Box<dyn hyper::rt::Sleep>>, deadline: Instant) {
        match sleep.as_mut().downcast_mut_pin::<Sleep>() {
            Some(sleep) => sleep.reset(deadline),
            None => *sleep = self.sleep_until(deadline),
        }
    }
}

impl hyper::rt::Sleep for Sleep {}

/// A stream of this crate ([`TcpStream`], [`crate::net::UnixStream`], a TLS
/// stream over one) with hyper's I/O traits.
pub struct Io<T> {
    inner: T,
}

impl<T> Io<T> {
    /// Wraps `inner`.
    pub fn new(inner: T) -> Io<T> {
        Io { inner }
    }

    /// The wrapped stream.
    pub fn inner(&self) -> &T {
        &self.inner
    }

    /// The wrapped stream.
    pub fn inner_mut(&mut self) -> &mut T {
        &mut self.inner
    }

    /// The wrapped stream, unwrapped.
    pub fn into_inner(self) -> T {
        self.inner
    }
}

impl<T: fmt::Debug> fmt::Debug for Io<T> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_tuple("Io").field(&self.inner).finish()
    }
}

impl<T: AsyncRead + Unpin> hyper::rt::Read for Io<T> {
    fn poll_read(
        self: Pin<&mut Self>,
        cx: &mut Context<'_>,
        mut buf: hyper::rt::ReadBufCursor<'_>,
    ) -> Poll<io::Result<()>> {
        // SAFETY: the bytes `poll_read` fills are initialised, and only they
        // are marked filled in hyper's cursor; `ReadBuf::uninit` never
        // de-initialises memory.
        let filled = unsafe {
            let mut read = ReadBuf::uninit(buf.as_mut());
            match Pin::new(&mut self.get_mut().inner).poll_read(cx, &mut read) {
                Poll::Ready(Ok(())) => read.filled().len(),
                other => return other,
            }
        };
        // SAFETY: `filled` bytes were initialised by the read above.
        unsafe { buf.advance(filled) };
        Poll::Ready(Ok(()))
    }
}

impl<T: AsyncWrite + Unpin> hyper::rt::Write for Io<T> {
    #[inline]
    fn poll_write(self: Pin<&mut Self>, cx: &mut Context<'_>, buf: &[u8]) -> Poll<io::Result<usize>> {
        Pin::new(&mut self.get_mut().inner).poll_write(cx, buf)
    }

    #[inline]
    fn poll_flush(self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        Pin::new(&mut self.get_mut().inner).poll_flush(cx)
    }

    #[inline]
    fn poll_shutdown(self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        Pin::new(&mut self.get_mut().inner).poll_shutdown(cx)
    }

    #[inline]
    fn is_write_vectored(&self) -> bool {
        self.inner.is_write_vectored()
    }

    #[inline]
    fn poll_write_vectored(
        self: Pin<&mut Self>,
        cx: &mut Context<'_>,
        bufs: &[io::IoSlice<'_>],
    ) -> Poll<io::Result<usize>> {
        Pin::new(&mut self.get_mut().inner).poll_write_vectored(cx, bufs)
    }
}

impl Connection for Io<TcpStream> {
    fn connected(&self) -> Connected {
        Connected::new()
    }
}

/// Opens TCP connections for hyper's client: resolves the URI's host,
/// connects to its addresses in turn, and sets the socket options.
///
/// PHP: the connection step of `Swoole\Coroutine\Http\Client` and cURL.
#[derive(Debug, Clone)]
pub struct Connector {
    enforce_http: bool,
    connect_timeout: Option<Duration>,
    keepalive: Option<Duration>,
    nodelay: bool,
    send_buffer_size: Option<usize>,
    recv_buffer_size: Option<usize>,
}

impl Connector {
    /// A connector for `http://` URIs, without a connect timeout, keepalive
    /// or `TCP_NODELAY`.
    pub fn new() -> Connector {
        Connector {
            enforce_http: true,
            connect_timeout: None,
            keepalive: None,
            nodelay: false,
            send_buffer_size: None,
            recv_buffer_size: None,
        }
    }

    /// Whether only `http://` URIs are accepted; turn it off when a TLS
    /// connector wraps this one.
    pub fn enforce_http(&mut self, enforce: bool) {
        self.enforce_http = enforce;
    }

    /// The most a connection may take, shared evenly by the addresses the
    /// host resolves to.
    pub fn set_connect_timeout(&mut self, timeout: Option<Duration>) {
        self.connect_timeout = timeout;
    }

    /// TCP keepalive: probe after this long idle (`None`: off).
    pub fn set_keepalive(&mut self, time: Option<Duration>) {
        self.keepalive = time;
    }

    /// Turns Nagle's algorithm off (`true`) or on.
    pub fn set_nodelay(&mut self, nodelay: bool) {
        self.nodelay = nodelay;
    }

    /// The socket's send buffer size (`SO_SNDBUF`; `None`: the system's).
    pub fn set_send_buffer_size(&mut self, size: Option<usize>) {
        self.send_buffer_size = size;
    }

    /// The socket's receive buffer size (`SO_RCVBUF`; `None`: the
    /// system's).
    pub fn set_recv_buffer_size(&mut self, size: Option<usize>) {
        self.recv_buffer_size = size;
    }

    /// Connects to `addr`, with the buffer sizes set before connecting.
    async fn dial(&self, addr: std::net::SocketAddr) -> io::Result<TcpStream> {
        if self.send_buffer_size.is_none() && self.recv_buffer_size.is_none() {
            return TcpStream::connect(addr).await;
        }
        let socket = if addr.is_ipv4() { TcpSocket::new_v4()? } else { TcpSocket::new_v6()? };
        let size = |bytes: usize| u32::try_from(bytes).unwrap_or(u32::MAX);
        if let Some(bytes) = self.send_buffer_size {
            socket.set_send_buffer_size(size(bytes))?;
        }
        if let Some(bytes) = self.recv_buffer_size {
            socket.set_recv_buffer_size(size(bytes))?;
        }
        socket.connect(addr).await
    }

    async fn connect(self, uri: http::Uri) -> io::Result<Io<TcpStream>> {
        let invalid = |message: &str| io::Error::new(io::ErrorKind::InvalidInput, message.to_owned());
        let scheme = uri.scheme_str();
        match scheme {
            None => return Err(invalid("invalid URL, scheme is missing")),
            Some(scheme) if self.enforce_http && scheme != "http" => {
                return Err(invalid("invalid URL, scheme is not http"));
            }
            _ => {}
        }
        let host = uri.host().ok_or_else(|| invalid("invalid URL, host is missing"))?;
        let host = host.strip_prefix('[').and_then(|h| h.strip_suffix(']')).unwrap_or(host);
        let port = uri.port_u16().unwrap_or(if scheme == Some("https") { 443 } else { 80 });
        let addrs: Vec<_> = crate::net::lookup_host((host, port)).await?.collect();
        let budget = self.connect_timeout.map(|timeout| timeout / addrs.len().max(1) as u32);
        let mut last = None;
        for addr in addrs {
            let attempt = match budget {
                Some(budget) => match crate::timeout(budget, self.dial(addr)).await {
                    Ok(attempt) => attempt,
                    Err(_) => Err(io::Error::new(io::ErrorKind::TimedOut, "connect timeout")),
                },
                None => self.dial(addr).await,
            };
            match attempt {
                Ok(stream) => {
                    if self.nodelay {
                        stream.set_nodelay(true)?;
                    }
                    if self.keepalive.is_some() {
                        stream.set_keepalive(self.keepalive)?;
                    }
                    return Ok(Io::new(stream));
                }
                Err(error) => last = Some(error),
            }
        }
        Err(last.unwrap_or_else(|| io::Error::new(io::ErrorKind::NotFound, "dns error: no addresses")))
    }
}

impl Default for Connector {
    fn default() -> Self {
        Self::new()
    }
}

impl tower_service::Service<http::Uri> for Connector {
    type Response = Io<TcpStream>;
    type Error = io::Error;
    type Future = Pin<Box<dyn Future<Output = io::Result<Io<TcpStream>>> + Send>>;

    fn poll_ready(&mut self, _cx: &mut Context<'_>) -> Poll<io::Result<()>> {
        Poll::Ready(Ok(()))
    }

    fn call(&mut self, uri: http::Uri) -> Self::Future {
        Box::pin(self.clone().connect(uri))
    }
}
