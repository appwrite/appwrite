//! Networking: TCP connections, listeners and sockets, UDP sockets, Unix
//! domain sockets and name resolution, on the engine's I/O driver.
//!
//! | PHP (Swoole) | Rust |
//! |---|---|
//! | `Swoole\Coroutine\Socket` (`AF_INET`, `SOCK_STREAM`), `Swoole\Coroutine\Client` | [`TcpStream`] |
//! | `Swoole\Coroutine\Server`, `Swoole\Coroutine\Socket::listen()` / `accept()` | [`TcpListener`], [`TcpSocket`] |
//! | `Swoole\Coroutine\Socket` (`SOCK_DGRAM`) | [`UdpSocket`] |
//! | `Swoole\Coroutine\Socket` (`AF_UNIX`) | [`UnixStream`] |
//! | `Swoole\Coroutine\System::getaddrinfo()`, `gethostbyname()` | [`lookup_host`] |

use std::future::Future;
use std::io;
use std::net::{IpAddr, Ipv4Addr, Ipv6Addr, SocketAddr, SocketAddrV4, SocketAddrV6};
#[cfg(unix)]
use std::path::Path;
use std::time::Duration;

use crate::Engine;
use crate::adapter::{self, Adapter};
use crate::io::{impl_read, impl_write};

type Stream = <Engine as Adapter>::TcpStream;
type Listener = <Engine as Adapter>::TcpListener;
type Socket = <Engine as Adapter>::TcpSocket;
type Datagram = <Engine as Adapter>::UdpSocket;

/// Something that names one or more socket addresses: an address, an IP and
/// a port, `"host:port"`, or a host name and a port (resolved by
/// [`lookup_host`]).
pub trait ToSocketAddrs: sealed::Sealed {
    /// The addresses, resolved.
    #[doc(hidden)]
    fn resolve(self) -> impl Future<Output = io::Result<Vec<SocketAddr>>> + Send;
}

mod sealed {
    pub trait Sealed {}
}

macro_rules! ready {
    ($($type:ty => |$this:ident| $addrs:expr),* $(,)?) => {$(
        impl sealed::Sealed for $type {}

        impl ToSocketAddrs for $type {
            #[inline]
            fn resolve(self) -> impl Future<Output = io::Result<Vec<SocketAddr>>> + Send {
                let $this = self;
                std::future::ready(Ok($addrs))
            }
        }
    )*};
}

ready! {
    SocketAddr => |addr| vec![addr],
    &SocketAddr => |addr| vec![*addr],
    SocketAddrV4 => |addr| vec![SocketAddr::V4(addr)],
    SocketAddrV6 => |addr| vec![SocketAddr::V6(addr)],
    (IpAddr, u16) => |addr| vec![SocketAddr::new(addr.0, addr.1)],
    (Ipv4Addr, u16) => |addr| vec![SocketAddr::new(IpAddr::V4(addr.0), addr.1)],
    (Ipv6Addr, u16) => |addr| vec![SocketAddr::new(IpAddr::V6(addr.0), addr.1)],
    &[SocketAddr] => |addrs| addrs.to_vec(),
    Vec<SocketAddr> => |addrs| addrs,
}

/// The addresses of a host name and a port: an IP literal as is, a name
/// through the engine's resolver.
async fn host(host: &str, port: u16) -> io::Result<Vec<SocketAddr>> {
    match host.parse::<IpAddr>() {
        Ok(ip) => Ok(vec![SocketAddr::new(ip, port)]),
        Err(_) => Engine::lookup_host(host.to_owned(), port).await,
    }
}

/// The addresses of `"host:port"` (or a socket address literal).
async fn host_port(text: &str) -> io::Result<Vec<SocketAddr>> {
    if let Ok(addr) = text.parse::<SocketAddr>() {
        return Ok(vec![addr]);
    }
    let (name, port) =
        text.rsplit_once(':').ok_or_else(|| io::Error::new(io::ErrorKind::InvalidInput, "invalid socket address"))?;
    let port = port.parse::<u16>().map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "invalid port value"))?;
    host(name, port).await
}

macro_rules! named {
    ($($type:ty => |$this:ident| $resolve:expr),* $(,)?) => {$(
        impl sealed::Sealed for $type {}

        impl ToSocketAddrs for $type {
            #[inline]
            fn resolve(self) -> impl Future<Output = io::Result<Vec<SocketAddr>>> + Send {
                let $this = self;
                async move { $resolve.await }
            }
        }
    )*};
}

named! {
    (&str, u16) => |addr| host(addr.0, addr.1),
    (String, u16) => |addr| host(&addr.0, addr.1),
    (&String, u16) => |addr| host(addr.0, addr.1),
    &str => |addr| host_port(addr),
    String => |addr| host_port(&addr),
    &String => |addr| host_port(addr),
}

/// The addresses a host resolves to: `(name, port)`, `"name:port"`, or
/// anything else [`ToSocketAddrs`] takes. IP literals are not looked up.
///
/// ```
/// utopia_async::run(async {
///     let mut addrs = utopia_async::net::lookup_host(("127.0.0.1", 80)).await?;
///     assert_eq!(addrs.next(), Some("127.0.0.1:80".parse().unwrap()));
///     Ok::<(), std::io::Error>(())
/// })?;
/// # Ok::<(), std::io::Error>(())
/// ```
///
/// PHP: `Swoole\Coroutine\System::getaddrinfo()`.
pub async fn lookup_host(host: impl ToSocketAddrs) -> io::Result<std::vec::IntoIter<SocketAddr>> {
    Ok(host.resolve().await?.into_iter())
}

/// Tries `attempt` on each address in turn: the first success, or the last
/// error.
async fn each<T, F, Fut>(addrs: impl ToSocketAddrs, mut attempt: F) -> io::Result<T>
where
    F: FnMut(SocketAddr) -> Fut,
    Fut: Future<Output = io::Result<T>>,
{
    let mut last = None;
    for addr in addrs.resolve().await? {
        match attempt(addr).await {
            Ok(value) => return Ok(value),
            Err(error) => last = Some(error),
        }
    }
    Err(last.unwrap_or_else(|| io::Error::new(io::ErrorKind::InvalidInput, "could not resolve to any address")))
}

/// A TCP connection. Read and write it with [`crate::io::AsyncReadExt`] and
/// [`crate::io::AsyncWriteExt`]; `shutdown()` closes the writing side.
///
/// ```
/// use utopia_async::io::{AsyncReadExt, AsyncWriteExt};
/// use utopia_async::net::{TcpListener, TcpStream};
///
/// utopia_async::run(async {
///     let listener = TcpListener::bind("127.0.0.1:0").await?;
///     let addr = listener.local_addr()?;
///     let server = utopia_async::spawn(async move {
///         let (mut socket, _) = listener.accept().await?;
///         socket.write_all(b"hello").await
///     });
///
///     let mut client = TcpStream::connect(addr).await?;
///     let mut greeting = String::new();
///     client.read_to_string(&mut greeting).await?;
///     assert_eq!(greeting, "hello");
///     server.await??;
///     Ok::<(), std::io::Error>(())
/// })?;
/// # Ok::<(), std::io::Error>(())
/// ```
///
/// PHP: `Swoole\Coroutine\Client` (`SWOOLE_SOCK_TCP`).
#[derive(Debug)]
pub struct TcpStream {
    inner: Stream,
}

impl TcpStream {
    /// Connects to `addr`, trying each address it resolves to in turn.
    pub async fn connect(addr: impl ToSocketAddrs) -> io::Result<TcpStream> {
        let inner = each(addr, <Stream as adapter::TcpStream>::connect).await?;
        Ok(TcpStream { inner })
    }

    /// Takes over a connected standard library stream, which must be in
    /// non-blocking mode.
    pub fn from_std(stream: std::net::TcpStream) -> io::Result<TcpStream> {
        Ok(TcpStream { inner: adapter::TcpStream::from_std(stream)? })
    }

    /// This end's address.
    pub fn local_addr(&self) -> io::Result<SocketAddr> {
        adapter::TcpStream::local_addr(&self.inner)
    }

    /// The other end's address.
    pub fn peer_addr(&self) -> io::Result<SocketAddr> {
        adapter::TcpStream::peer_addr(&self.inner)
    }

    /// Whether Nagle's algorithm is off (`TCP_NODELAY`).
    pub fn nodelay(&self) -> io::Result<bool> {
        adapter::TcpStream::nodelay(&self.inner)
    }

    /// Turns Nagle's algorithm off (`true`: small writes leave at once) or
    /// on.
    pub fn set_nodelay(&self, nodelay: bool) -> io::Result<()> {
        adapter::TcpStream::set_nodelay(&self.inner, nodelay)
    }

    /// Turns TCP keepalive on, probing after `time` idle, or off (`None`).
    pub fn set_keepalive(&self, time: Option<Duration>) -> io::Result<()> {
        adapter::TcpStream::set_keepalive(&self.inner, time)
    }

    /// Splits the connection into a reading and a writing half, to read and
    /// write from two tasks.
    pub fn into_split(self) -> (tcp::OwnedReadHalf, tcp::OwnedWriteHalf) {
        let (read, write) = adapter::TcpStream::into_split(self.inner);
        (tcp::OwnedReadHalf { inner: read }, tcp::OwnedWriteHalf { inner: write })
    }
}

impl_read!(TcpStream);
impl_write!(TcpStream);

/// The halves of a split [`TcpStream`].
pub mod tcp {
    use crate::Engine;
    use crate::adapter::{Adapter, TcpStream};
    use crate::io::{impl_read, impl_write};

    /// The reading half of a [`super::TcpStream`].
    #[derive(Debug)]
    pub struct OwnedReadHalf {
        pub(super) inner: <<Engine as Adapter>::TcpStream as TcpStream>::ReadHalf,
    }

    /// The writing half of a [`super::TcpStream`]; dropping it closes the
    /// writing side.
    #[derive(Debug)]
    pub struct OwnedWriteHalf {
        pub(super) inner: <<Engine as Adapter>::TcpStream as TcpStream>::WriteHalf,
    }

    impl_read!(OwnedReadHalf);
    impl_write!(OwnedWriteHalf);
}

/// A TCP listener.
///
/// PHP: `Swoole\Coroutine\Server`, `Swoole\Coroutine\Socket::listen()`.
#[derive(Debug)]
pub struct TcpListener {
    inner: Listener,
}

impl TcpListener {
    /// Listens on `addr` (port 0 picks a free port), trying each address it
    /// resolves to in turn.
    pub async fn bind(addr: impl ToSocketAddrs) -> io::Result<TcpListener> {
        let inner = each(addr, <Listener as adapter::TcpListener>::bind).await?;
        Ok(TcpListener { inner })
    }

    /// Takes over a bound standard library listener, which must be in
    /// non-blocking mode.
    pub fn from_std(listener: std::net::TcpListener) -> io::Result<TcpListener> {
        Ok(TcpListener { inner: adapter::TcpListener::from_std(listener)? })
    }

    /// Waits for the next connection and returns it with the client's
    /// address.
    ///
    /// PHP: `Swoole\Coroutine\Socket::accept()`.
    pub async fn accept(&self) -> io::Result<(TcpStream, SocketAddr)> {
        let (inner, addr) = adapter::TcpListener::accept(&self.inner).await?;
        Ok((TcpStream { inner }, addr))
    }

    /// The address it listens on.
    pub fn local_addr(&self) -> io::Result<SocketAddr> {
        adapter::TcpListener::local_addr(&self.inner)
    }
}

/// A TCP socket to configure (address reuse, buffer sizes) before it
/// connects or listens.
#[derive(Debug)]
pub struct TcpSocket {
    inner: Socket,
}

impl TcpSocket {
    /// A new IPv4 socket.
    pub fn new_v4() -> io::Result<TcpSocket> {
        Ok(TcpSocket { inner: adapter::TcpSocket::new_v4()? })
    }

    /// A new IPv6 socket.
    pub fn new_v6() -> io::Result<TcpSocket> {
        Ok(TcpSocket { inner: adapter::TcpSocket::new_v6()? })
    }

    /// Allows binding an address in `TIME_WAIT` (`SO_REUSEADDR`).
    pub fn set_reuseaddr(&self, reuseaddr: bool) -> io::Result<()> {
        adapter::TcpSocket::set_reuseaddr(&self.inner, reuseaddr)
    }

    /// Allows several sockets to bind the same port (`SO_REUSEPORT`).
    #[cfg(unix)]
    pub fn set_reuseport(&self, reuseport: bool) -> io::Result<()> {
        adapter::TcpSocket::set_reuseport(&self.inner, reuseport)
    }

    /// The send buffer size (`SO_SNDBUF`).
    pub fn set_send_buffer_size(&self, size: u32) -> io::Result<()> {
        adapter::TcpSocket::set_send_buffer_size(&self.inner, size)
    }

    /// The receive buffer size (`SO_RCVBUF`).
    pub fn set_recv_buffer_size(&self, size: u32) -> io::Result<()> {
        adapter::TcpSocket::set_recv_buffer_size(&self.inner, size)
    }

    /// Turns Nagle's algorithm off (`true`) or on.
    pub fn set_nodelay(&self, nodelay: bool) -> io::Result<()> {
        adapter::TcpSocket::set_nodelay(&self.inner, nodelay)
    }

    /// Binds the socket to `addr`.
    pub fn bind(&self, addr: SocketAddr) -> io::Result<()> {
        adapter::TcpSocket::bind(&self.inner, addr)
    }

    /// The address the socket is bound to.
    pub fn local_addr(&self) -> io::Result<SocketAddr> {
        adapter::TcpSocket::local_addr(&self.inner)
    }

    /// Connects to `addr`.
    pub async fn connect(self, addr: SocketAddr) -> io::Result<TcpStream> {
        Ok(TcpStream { inner: adapter::TcpSocket::connect(self.inner, addr).await? })
    }

    /// Listens, queueing up to `backlog` connections.
    pub fn listen(self, backlog: u32) -> io::Result<TcpListener> {
        Ok(TcpListener { inner: adapter::TcpSocket::listen(self.inner, backlog)? })
    }
}

/// A UDP socket.
///
/// PHP: `Swoole\Coroutine\Socket` (`SOCK_DGRAM`), `Swoole\Coroutine\Client`
/// (`SWOOLE_SOCK_UDP`).
#[derive(Debug)]
pub struct UdpSocket {
    inner: Datagram,
}

impl UdpSocket {
    /// Binds a socket to `addr` (port 0 picks a free port), trying each
    /// address it resolves to in turn.
    pub async fn bind(addr: impl ToSocketAddrs) -> io::Result<UdpSocket> {
        let inner = each(addr, <Datagram as adapter::UdpSocket>::bind).await?;
        Ok(UdpSocket { inner })
    }

    /// Takes over a bound standard library socket, which must be in
    /// non-blocking mode.
    pub fn from_std(socket: std::net::UdpSocket) -> io::Result<UdpSocket> {
        Ok(UdpSocket { inner: adapter::UdpSocket::from_std(socket)? })
    }

    /// The address the socket is bound to.
    pub fn local_addr(&self) -> io::Result<SocketAddr> {
        adapter::UdpSocket::local_addr(&self.inner)
    }

    /// The address the socket is connected to.
    pub fn peer_addr(&self) -> io::Result<SocketAddr> {
        adapter::UdpSocket::peer_addr(&self.inner)
    }

    /// Sends to and receives from `addr` only, trying each address it
    /// resolves to in turn.
    pub async fn connect(&self, addr: impl ToSocketAddrs) -> io::Result<()> {
        each(addr, |addr| adapter::UdpSocket::connect(&self.inner, addr)).await
    }

    /// Sends a datagram to the connected address.
    pub async fn send(&self, buf: &[u8]) -> io::Result<usize> {
        adapter::UdpSocket::send(&self.inner, buf).await
    }

    /// Receives a datagram from the connected address into `buf`; a longer
    /// datagram is cut to `buf`'s length.
    pub async fn recv(&self, buf: &mut [u8]) -> io::Result<usize> {
        adapter::UdpSocket::recv(&self.inner, buf).await
    }

    /// Sends a datagram to `target` (its first address).
    pub async fn send_to(&self, buf: &[u8], target: impl ToSocketAddrs) -> io::Result<usize> {
        let Some(target) = target.resolve().await?.into_iter().next() else {
            return Err(io::Error::new(io::ErrorKind::InvalidInput, "no addresses to send data to"));
        };
        adapter::UdpSocket::send_to(&self.inner, buf, target).await
    }

    /// Receives a datagram into `buf` and the address it came from.
    pub async fn recv_from(&self, buf: &mut [u8]) -> io::Result<(usize, SocketAddr)> {
        adapter::UdpSocket::recv_from(&self.inner, buf).await
    }
}

/// A Unix domain socket connection.
///
/// PHP: `Swoole\Coroutine\Socket` (`AF_UNIX`), `unix://` streams.
#[cfg(unix)]
#[derive(Debug)]
pub struct UnixStream {
    inner: <Engine as Adapter>::UnixStream,
}

#[cfg(unix)]
impl UnixStream {
    /// Connects to the socket at `path`.
    pub async fn connect(path: impl AsRef<Path>) -> io::Result<UnixStream> {
        let inner =
            <<Engine as Adapter>::UnixStream as adapter::UnixStream>::connect(path.as_ref().to_path_buf()).await?;
        Ok(UnixStream { inner })
    }
}

#[cfg(unix)]
impl_read!(UnixStream);
#[cfg(unix)]
impl_write!(UnixStream);
