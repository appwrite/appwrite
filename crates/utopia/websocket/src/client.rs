//! A WebSocket client: connect, send, receive or listen, with event hooks.

use std::time::Duration;

use bytes::Bytes;
use futures_util::future::BoxFuture;

use crate::Error;

/// The error code of a transport whose connection is gone: [`Client::listen`]
/// and [`Client::receive`] treat it as the server closing the connection.
///
/// PHP: `SWOOLE_ERROR_CLIENT_NO_CONNECTION`.
pub const NO_CONNECTION: i64 = 5001;

/// The error code of a receive that timed out: [`Client::listen`] keeps
/// waiting.
///
/// PHP: `110` (`ETIMEDOUT`), as Swoole reports it.
pub const TIMED_OUT: i64 = 110;

/// The opcode of a WebSocket frame.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Opcode {
    /// A text message (`1`).
    Text,
    /// A binary message (`2`): the client ignores it.
    Binary,
    /// The peer is closing the connection (`8`).
    Close,
    /// A ping (`9`): the client answers with a pong.
    Ping,
    /// A pong (`10`).
    Pong,
    /// Any other opcode, such as a continuation (`0`): ignored.
    Other(u8),
}

impl Opcode {
    /// The opcode for its number on the wire.
    pub fn from_code(code: u8) -> Opcode {
        match code {
            1 => Opcode::Text,
            2 => Opcode::Binary,
            8 => Opcode::Close,
            9 => Opcode::Ping,
            10 => Opcode::Pong,
            other => Opcode::Other(other),
        }
    }

    /// The opcode's number on the wire.
    ///
    /// PHP: `WEBSOCKET_OPCODE_TEXT` (1), `_BINARY` (2), `_CLOSE` (8),
    /// `_PING` (9), `_PONG` (10).
    pub fn code(self) -> u8 {
        match self {
            Opcode::Text => 1,
            Opcode::Binary => 2,
            Opcode::Close => 8,
            Opcode::Ping => 9,
            Opcode::Pong => 10,
            Opcode::Other(code) => code,
        }
    }
}

/// A frame received from the server.
///
/// PHP: `Swoole\WebSocket\Frame`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Frame {
    /// The frame's opcode.
    pub opcode: Opcode,
    /// The payload.
    pub data: Bytes,
}

/// A transport failure: an error code and its message.
///
/// PHP: the `errCode` and `errMsg` of the Swoole client, which the client's
/// error messages quote as `"{code} - {message}"`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TransportError {
    /// The code: an `errno` value as Linux numbers it (`111` for a refused
    /// connection), [`TIMED_OUT`], [`NO_CONNECTION`], or a Swoole error code
    /// (`711` for a failed DNS lookup).
    pub code: i64,
    /// The message.
    pub message: String,
}

impl TransportError {
    /// A failure with a code and a message.
    pub fn new(code: i64, message: impl Into<String>) -> Self {
        Self { code, message: message.into() }
    }
}

/// What one receive returned.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Received {
    /// A frame.
    Frame(Frame),
    /// Nothing to report (PHP: `recv()` returned `''`).
    Empty,
    /// The receive failed (PHP: `recv()` returned `false`).
    Failed(TransportError),
}

/// An established WebSocket connection the client reads and writes:
/// tokio-tungstenite on the network (the client's own, made by
/// [`Client::connect`]), or one given to [`Client::attach`], such as a
/// scripted connection in tests.
///
/// PHP: the `Swoole\Coroutine\Http\Client` the client upgrades.
pub trait Transport: Send {
    /// Waits up to `timeout` for the next frame.
    fn recv(&mut self, timeout: Duration) -> BoxFuture<'_, Received>;
    /// Sends one frame.
    fn push(&mut self, data: Bytes, opcode: Opcode) -> BoxFuture<'_, Result<(), TransportError>>;
    /// Closes the connection.
    fn close(&mut self) -> BoxFuture<'_, ()>;
}

/// Client options.
///
/// PHP: the `$options` array (`headers`, `timeout`).
#[derive(Debug, Clone, PartialEq)]
pub struct Options {
    /// Headers sent with the handshake, in order.
    pub headers: Vec<(String, String)>,
    /// How long connecting and each receive may take (30 seconds by
    /// default).
    pub timeout: Duration,
}

impl Default for Options {
    fn default() -> Self {
        Self { headers: Vec::new(), timeout: Duration::from_secs(30) }
    }
}

type DataHook = Box<dyn FnMut(&[u8]) + Send>;
type EventHook = Box<dyn FnMut() + Send>;
type ErrorHook = Box<dyn FnMut(&Error) + Send>;

/// A WebSocket client.
///
/// [`Client::new`] parses the URL with PHP's `parse_url` rules: the host is
/// required, the port defaults to 443 for `wss` and 80 otherwise, the path to
/// `/`, and the query is kept. [`connect`](Client::connect) opens the
/// connection (TLS when the port is 443), then [`send`](Client::send),
/// [`receive`](Client::receive) one message or [`listen`](Client::listen)
/// until the connection closes, with the `on_*` hooks called along the way.
///
/// ```
/// use utopia_websocket::client::{Client, Options};
///
/// let client = Client::new("wss://realtime.example.com/v1/realtime?project=main", Options::default())?;
/// assert_eq!(client.host(), "realtime.example.com");
/// assert_eq!(client.port(), 443);
/// assert_eq!(client.path(), "/v1/realtime?project=main");
/// assert!(!client.is_connected());
///
/// let err = Client::new("not a url", Options::default()).unwrap_err();
/// assert_eq!(err.to_string(), "WebSocket URL must contain a host");
/// # Ok::<(), utopia_websocket::Error>(())
/// ```
///
/// ```php
/// use Utopia\WebSocket\Client;
///
/// $client = new Client('wss://realtime.example.com/v1/realtime?project=main');
/// var_dump($client->isConnected()); // bool(false)
///
/// try {
///     new Client('not a url');
/// } catch (\InvalidArgumentException $e) {
///     echo $e->getMessage(), "\n"; // WebSocket URL must contain a host
/// }
/// ```
///
/// PHP: `Utopia\WebSocket\Client`.
pub struct Client {
    host: String,
    port: u16,
    path: String,
    headers: Vec<(String, String)>,
    timeout: Duration,
    connected: bool,
    transport: Option<Box<dyn Transport>>,
    on_message: Option<DataHook>,
    on_close: Option<EventHook>,
    on_error: Option<ErrorHook>,
    on_open: Option<EventHook>,
    on_ping: Option<DataHook>,
    on_pong: Option<DataHook>,
}

const NOT_CONNECTED: &str = "Not connected to WebSocket server";

impl std::fmt::Debug for Client {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Client")
            .field("host", &self.host)
            .field("port", &self.port)
            .field("path", &self.path)
            .field("timeout", &self.timeout)
            .field("connected", &self.connected)
            .finish_non_exhaustive()
    }
}

impl Client {
    /// A client for `url`, not connected yet.
    ///
    /// Fails with [`Error::InvalidArgument`] when PHP's `parse_url` rejects
    /// the URL (`Invalid WebSocket URL`) or finds no host in it (`WebSocket
    /// URL must contain a host`).
    ///
    /// PHP: `new Client(string $url, array $options = [])`.
    pub fn new(url: &str, options: Options) -> Result<Self, Error> {
        let parsed = php_std::url::parse_url(url.as_bytes())
            .ok_or_else(|| Error::InvalidArgument("Invalid WebSocket URL".into()))?;
        let text = |part: std::borrow::Cow<'_, [u8]>| String::from_utf8_lossy(&part).into_owned();
        let host = parsed
            .host()
            .map(text)
            .ok_or_else(|| Error::InvalidArgument("WebSocket URL must contain a host".into()))?;
        let port =
            parsed.port().unwrap_or(if parsed.scheme().as_deref() == Some(b"wss".as_slice()) { 443 } else { 80 });
        let mut path = parsed.path().map(text).unwrap_or_else(|| "/".to_owned());
        if let Some(query) = parsed.query() {
            path.push('?');
            path.push_str(&text(query));
        }
        Ok(Self {
            host,
            port,
            path,
            headers: options.headers,
            timeout: options.timeout,
            connected: false,
            transport: None,
            on_message: None,
            on_close: None,
            on_error: None,
            on_open: None,
            on_ping: None,
            on_pong: None,
        })
    }

    /// The host to connect to.
    pub fn host(&self) -> &str {
        &self.host
    }

    /// The port to connect to.
    pub fn port(&self) -> u16 {
        self.port
    }

    /// The path and query of the handshake request.
    pub fn path(&self) -> &str {
        &self.path
    }

    /// The headers sent with the handshake.
    pub fn headers(&self) -> &[(String, String)] {
        &self.headers
    }

    /// How long connecting and each receive may take.
    pub fn timeout(&self) -> Duration {
        self.timeout
    }

    /// Uses `transport` as an established connection, without calling the
    /// open hook: the client is connected from now on.
    pub fn attach(&mut self, transport: Box<dyn Transport>) {
        self.transport = Some(transport);
        self.connected = true;
    }

    /// Whether the client is connected.
    ///
    /// PHP: `isConnected()`.
    pub fn is_connected(&self) -> bool {
        self.connected
    }

    /// Calls `hook` with each text message received.
    ///
    /// PHP: `onMessage(\Closure $callback)`.
    pub fn on_message(&mut self, hook: impl FnMut(&[u8]) + Send + 'static) -> &mut Self {
        self.on_message = Some(Box::new(hook));
        self
    }

    /// Calls `hook` when the connection closes.
    ///
    /// PHP: `onClose(\Closure $callback)`.
    pub fn on_close(&mut self, hook: impl FnMut() + Send + 'static) -> &mut Self {
        self.on_close = Some(Box::new(hook));
        self
    }

    /// Calls `hook` with each error: a failed connect or send, and the
    /// failure that ends [`listen`](Client::listen).
    ///
    /// PHP: `onError(\Closure $callback)`.
    pub fn on_error(&mut self, hook: impl FnMut(&Error) + Send + 'static) -> &mut Self {
        self.on_error = Some(Box::new(hook));
        self
    }

    /// Calls `hook` once connected.
    ///
    /// PHP: `onOpen(\Closure $callback)`.
    pub fn on_open(&mut self, hook: impl FnMut() + Send + 'static) -> &mut Self {
        self.on_open = Some(Box::new(hook));
        self
    }

    /// Calls `hook` with the payload of each ping.
    ///
    /// PHP: `onPing(\Closure $callback)`.
    pub fn on_ping(&mut self, hook: impl FnMut(&[u8]) + Send + 'static) -> &mut Self {
        self.on_ping = Some(Box::new(hook));
        self
    }

    /// Calls `hook` with the payload of each pong.
    ///
    /// PHP: `onPong(\Closure $callback)`.
    pub fn on_pong(&mut self, hook: impl FnMut(&[u8]) + Send + 'static) -> &mut Self {
        self.on_pong = Some(Box::new(hook));
        self
    }

    /// Opens the connection, then calls the open hook. A failure calls the
    /// error hook and is returned as [`Error::Runtime`] (`WebSocket
    /// connection failed: 111 - Connection refused`).
    ///
    /// PHP: `connect()`.
    #[cfg(feature = "tokio")]
    pub async fn connect(&mut self) -> Result<(), Error> {
        match crate::net::connect(&self.host, self.port, &self.path, &self.headers, self.timeout).await {
            Ok(transport) => {
                self.transport = Some(Box::new(transport));
                self.connected = true;
                if let Some(hook) = &mut self.on_open {
                    hook();
                }
                Ok(())
            }
            Err(e) => {
                let error = Error::Runtime(format!("WebSocket connection failed: {} - {}", e.code, e.message));
                self.emit_error(&error);
                Err(error)
            }
        }
    }

    /// Reads frames until the connection closes, calling the hooks: text
    /// messages go to the message hook, pings are answered. A receive that
    /// times out is retried; any other failure calls the error hook and
    /// closes the connection.
    ///
    /// PHP: `listen()`.
    pub async fn listen(&mut self) {
        while self.connected {
            let Some(transport) = self.transport.as_mut() else { break };
            match transport.recv(self.timeout).await {
                Received::Failed(e) if e.code == NO_CONNECTION => {
                    self.handle_close().await;
                    break;
                }
                Received::Failed(e) if e.code == TIMED_OUT => continue,
                Received::Failed(e) => {
                    let error = Error::Runtime(format!("Failed to receive data: {} - {}", e.code, e.message));
                    self.emit_error(&error);
                    self.handle_close().await;
                    break;
                }
                Received::Empty => continue,
                Received::Frame(frame) => {
                    self.handle_frame(frame).await;
                }
            }
        }
    }

    /// Receives one frame: the payload of a text message (after calling the
    /// message hook), or `None` for anything else (a ping is answered, a
    /// close closes the client).
    ///
    /// PHP: `receive()`.
    pub async fn receive(&mut self) -> Result<Option<Bytes>, Error> {
        let transport = match (self.connected, self.transport.as_mut()) {
            (true, Some(transport)) => transport,
            _ => return Err(Error::Runtime(NOT_CONNECTED.into())),
        };
        match transport.recv(self.timeout).await {
            Received::Failed(e) if e.code == NO_CONNECTION => {
                self.handle_close().await;
                Ok(None)
            }
            Received::Failed(e) => Err(Error::Runtime(format!("Failed to receive data: {} - {}", e.code, e.message))),
            Received::Empty => Ok(None),
            Received::Frame(frame) => Ok(match frame.opcode {
                Opcode::Text => {
                    if let Some(hook) = &mut self.on_message {
                        hook(&frame.data);
                    }
                    Some(frame.data)
                }
                _ => {
                    self.handle_frame(frame).await;
                    None
                }
            }),
        }
    }

    async fn handle_frame(&mut self, frame: Frame) {
        match frame.opcode {
            Opcode::Text => {
                if let Some(hook) = &mut self.on_message {
                    hook(&frame.data);
                }
            }
            Opcode::Close => self.handle_close().await,
            Opcode::Ping => {
                if let Some(hook) = &mut self.on_ping {
                    hook(&frame.data);
                }
                if let Some(transport) = self.transport.as_mut() {
                    // PHP answers with an empty pong and ignores a failure.
                    let _ = transport.push(Bytes::new(), Opcode::Pong).await;
                }
            }
            Opcode::Pong => {
                if let Some(hook) = &mut self.on_pong {
                    hook(&frame.data);
                }
            }
            Opcode::Binary | Opcode::Other(_) => {}
        }
    }

    async fn handle_close(&mut self) {
        if self.connected {
            self.connected = false;
            if let Some(hook) = &mut self.on_close {
                hook();
            }
            if let Some(transport) = self.transport.as_mut() {
                transport.close().await;
            }
        }
    }

    fn emit_error(&mut self, error: &Error) {
        if let Some(hook) = &mut self.on_error {
            hook(error);
        }
    }

    /// Sends a text message. Fails with [`Error::Runtime`] when not
    /// connected, or when the send fails (after calling the error hook).
    ///
    /// PHP: `send(string $data)`.
    pub async fn send(&mut self, data: impl Into<Bytes>) -> Result<(), Error> {
        let transport = match (self.connected, self.transport.as_mut()) {
            (true, Some(transport)) => transport,
            _ => return Err(Error::Runtime(NOT_CONNECTED.into())),
        };
        if let Err(e) = transport.push(data.into(), Opcode::Text).await {
            let error = Error::Runtime(format!("Failed to send data: {} - {}", e.code, e.message));
            self.emit_error(&error);
            return Err(error);
        }
        Ok(())
    }

    /// Closes the connection, calling the close hook, if connected.
    ///
    /// PHP: `close()`.
    pub async fn close(&mut self) {
        self.handle_close().await;
    }
}
