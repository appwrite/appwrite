//! An MQTT client connection: sends encoded packets and receives whole ones.

use std::io;
use std::sync::{Arc, OnceLock};
use std::time::Duration;

use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::pki_types::{CertificateDer, ServerName, UnixTime};
use rustls::{DigitallySignedStruct, SignatureScheme};
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt};
use tokio::net::TcpStream;

use crate::Error;

/// The `errno` Linux gives a receive that timed out, as Swoole reports it.
pub const TIMED_OUT: i64 = 110;

/// Options of a [`Client`].
///
/// PHP: the `$options` array of `new Client($url, $options)`.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Options {
    /// Seconds to wait to connect, and for each packet on receive (30 by
    /// default).
    ///
    /// PHP: `timeout`.
    pub timeout: f64,
}

impl Default for Options {
    fn default() -> Self {
        Self { timeout: 30.0 }
    }
}

trait Stream: AsyncRead + AsyncWrite + Unpin + Send {}
impl<T: AsyncRead + AsyncWrite + Unpin + Send> Stream for T {}

type Hook = Box<dyn Fn() + Send + Sync>;
type ReceiveHook = Box<dyn Fn(&[u8]) -> Result<(), Error> + Send + Sync>;
type ErrorHook = Box<dyn Fn(&Error) + Send + Sync>;

/// A connection to an MQTT broker over TCP (`mqtt://`) or TLS
/// (`mqtts://`), sending packets the caller encodes (see [`crate::spec`])
/// and receiving whole packets, framed by their fixed header.
///
/// Hooks see the connection's life: [`on_open`](Client::on_open) once
/// connected, [`on_receive`](Client::on_receive) with each packet,
/// [`on_close`](Client::on_close) once it ends, and
/// [`on_error`](Client::on_error) with each failure. A receive hook that
/// fails is reported to the error hook, then the connection closes.
///
/// The broker's TLS certificate is not verified, as Swoole's client does
/// not verify it by default (a quirk the compat spec records).
///
/// ```
/// use utopia_mqtt::client::{Client, Options};
///
/// let client = Client::new("mqtts://broker.local", Options::default())?;
/// assert_eq!((client.host(), client.port(), client.is_tls()), (&b"broker.local"[..], 8883, true));
/// assert!(!client.is_connected());
/// assert_eq!(Client::new("mqtt://", Options::default()).unwrap_err().to_string(), "Invalid MQTT URL");
/// # Ok::<(), utopia_mqtt::Error>(())
/// ```
///
/// ```php
/// use Utopia\Mqtt\Client;
///
/// $client = new Client('mqtts://broker.local');
/// var_dump($client->isConnected()); // false
/// try {
///     new Client('mqtt://');
/// } catch (\InvalidArgumentException $e) {
///     echo $e->getMessage(), "\n"; // Invalid MQTT URL
/// }
/// ```
///
/// PHP: `Utopia\Mqtt\Client`.
pub struct Client {
    host: Vec<u8>,
    port: u16,
    tls: bool,
    timeout: f64,
    stream: Option<Box<dyn Stream>>,
    buffer: Vec<u8>,
    connected: bool,
    on_open: Option<Hook>,
    on_receive: Option<ReceiveHook>,
    on_close: Option<Hook>,
    on_error: Option<ErrorHook>,
}

impl std::fmt::Debug for Client {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Client")
            .field("host", &String::from_utf8_lossy(&self.host))
            .field("port", &self.port)
            .field("tls", &self.tls)
            .field("connected", &self.connected)
            .finish()
    }
}

enum Read {
    Packet(Vec<u8>),
    Closed,
    TimedOut,
    Failed(i64, String),
}

impl Client {
    /// A client for `mqtt://host[:port]` (port 1883 by default) or
    /// `mqtts://host[:port]` (TLS, 8883), parsed with PHP's `parse_url`
    /// rules. A URL PHP cannot parse, or without a host, is an
    /// [`Error::InvalidArgument`].
    ///
    /// PHP: `new Client(string $url, array $options = [])`.
    pub fn new(url: &str, options: Options) -> Result<Self, Error> {
        let parsed = php_std::url::parse_url(url.as_bytes()).ok_or_else(invalid)?;
        let host = parsed.host().ok_or_else(invalid)?.into_owned();
        let tls = parsed.scheme().as_deref() == Some(b"mqtts");
        let port = parsed.port().unwrap_or(if tls { 8883 } else { 1883 });
        Ok(Self {
            host,
            port,
            tls,
            timeout: options.timeout,
            stream: None,
            buffer: Vec::new(),
            connected: false,
            on_open: None,
            on_receive: None,
            on_close: None,
            on_error: None,
        })
    }

    /// The broker's host.
    pub fn host(&self) -> &[u8] {
        &self.host
    }

    /// The broker's port.
    pub fn port(&self) -> u16 {
        self.port
    }

    /// Whether the connection is TLS (`mqtts://`).
    pub fn is_tls(&self) -> bool {
        self.tls
    }

    /// The timeout, in seconds.
    pub fn timeout(&self) -> f64 {
        self.timeout
    }

    fn wait(&self) -> Duration {
        Duration::try_from_secs_f64(self.timeout).unwrap_or(Duration::MAX)
    }

    /// Connects, then calls the open hook. A failure (`MQTT connection
    /// failed: 111 - Connection refused`) is passed to the error hook and
    /// returned.
    ///
    /// PHP: `connect()`.
    pub async fn connect(&mut self) -> Result<(), Error> {
        match self.open().await {
            Ok(stream) => {
                self.stream = Some(stream);
                self.buffer.clear();
                self.connected = true;
                if let Some(hook) = &self.on_open {
                    hook();
                }
                Ok(())
            }
            Err((code, message)) => {
                let error = Error::Runtime(format!("MQTT connection failed: {code} - {message}"));
                self.emit_error(&error);
                Err(error)
            }
        }
    }

    async fn open(&self) -> Result<Box<dyn Stream>, (i64, String)> {
        let wait = self.wait();
        let host = String::from_utf8_lossy(&self.host).into_owned();
        let lookup = tokio::time::timeout(wait, tokio::net::lookup_host((host.as_str(), self.port))).await;
        let addr = match lookup {
            Ok(Ok(mut addrs)) => addrs.next(),
            Ok(Err(_)) => None,
            Err(_) => return Err((TIMED_OUT, "Connection timed out".into())),
        };
        let Some(addr) = addr else {
            return Err((704, "DNS Lookup resolve failed".into()));
        };
        let stream = match tokio::time::timeout(wait, TcpStream::connect(addr)).await {
            Ok(Ok(stream)) => stream,
            Ok(Err(e)) => return Err(errno(&e)),
            Err(_) => return Err((TIMED_OUT, "Connection timed out".into())),
        };
        let _ = stream.set_nodelay(true);
        if !self.tls {
            return Ok(Box::new(stream));
        }
        let config = rustls::ClientConfig::builder_with_provider(provider())
            .with_safe_default_protocol_versions()
            .map_err(|e| (0, e.to_string()))?
            .dangerous()
            .with_custom_certificate_verifier(Arc::new(Unverified))
            .with_no_client_auth();
        let name = ServerName::try_from(host.clone())
            .unwrap_or_else(|_| ServerName::try_from("localhost").expect("a valid name"));
        let connector = tokio_rustls::TlsConnector::from(Arc::new(config));
        match tokio::time::timeout(wait, connector.connect(name, stream)).await {
            Ok(Ok(stream)) => Ok(Box::new(stream)),
            Ok(Err(e)) => Err(errno(&e)),
            Err(_) => Err((TIMED_OUT, "Connection timed out".into())),
        }
    }

    /// Sends one encoded packet. Without a connection this is an
    /// [`Error::Runtime`] (`Not connected to MQTT broker`); a failed write is
    /// passed to the error hook and returned.
    ///
    /// PHP: `send(string $data)`.
    pub async fn send(&mut self, data: &[u8]) -> Result<(), Error> {
        if !self.connected {
            return Err(not_connected());
        }
        let Some(stream) = self.stream.as_mut() else {
            return Err(not_connected());
        };
        if let Err(e) = stream.write_all(data).await {
            let (code, message) = errno(&e);
            let error = Error::Runtime(format!("Failed to send data: {code} - {message}"));
            self.emit_error(&error);
            return Err(error);
        }
        Ok(())
    }

    /// Waits for the next packet and returns its bytes, after the receive
    /// hook. `None` when the timeout passes (the connection stays open) or
    /// once the connection is closed: by the broker, by a failed receive
    /// (passed to the error hook) or by a failing receive hook.
    ///
    /// PHP: `receive()`.
    pub async fn receive(&mut self) -> Result<Option<Vec<u8>>, Error> {
        if !self.connected {
            return Err(not_connected());
        }
        Ok(match self.read().await {
            Read::Packet(packet) => {
                if self.deliver(&packet) {
                    Some(packet)
                } else {
                    None
                }
            }
            Read::TimedOut => None,
            Read::Closed => {
                self.handle_close().await;
                None
            }
            Read::Failed(code, message) => {
                self.fail(code, &message).await;
                None
            }
        })
    }

    /// Receives packets, calling the receive hook with each, until the
    /// connection closes. A timeout keeps listening.
    ///
    /// PHP: `listen()`.
    pub async fn listen(&mut self) {
        while self.connected {
            match self.read().await {
                Read::Packet(packet) => {
                    if !self.deliver(&packet) {
                        break;
                    }
                }
                Read::TimedOut => {}
                Read::Closed => {
                    self.handle_close().await;
                    break;
                }
                Read::Failed(code, message) => {
                    self.fail(code, &message).await;
                    break;
                }
            }
        }
    }

    /// Closes the connection, calling the close hook once.
    ///
    /// PHP: `close()`.
    pub async fn close(&mut self) {
        self.handle_close().await;
    }

    /// Whether the client is connected.
    ///
    /// PHP: `isConnected()`.
    pub fn is_connected(&self) -> bool {
        self.connected
    }

    /// Sets the hook called once connected.
    ///
    /// PHP: `onOpen(Closure $callback)`.
    pub fn on_open(&mut self, hook: impl Fn() + Send + Sync + 'static) -> &mut Self {
        self.on_open = Some(Box::new(hook));
        self
    }

    /// Sets the hook called with each packet received; an error it returns
    /// is passed to the error hook and closes the connection.
    ///
    /// PHP: `onReceive(Closure $callback)`.
    pub fn on_receive(&mut self, hook: impl Fn(&[u8]) -> Result<(), Error> + Send + Sync + 'static) -> &mut Self {
        self.on_receive = Some(Box::new(hook));
        self
    }

    /// Sets the hook called once the connection closes.
    ///
    /// PHP: `onClose(Closure $callback)`.
    pub fn on_close(&mut self, hook: impl Fn() + Send + Sync + 'static) -> &mut Self {
        self.on_close = Some(Box::new(hook));
        self
    }

    /// Sets the hook called with each failure.
    ///
    /// PHP: `onError(Closure $callback)`.
    pub fn on_error(&mut self, hook: impl Fn(&Error) + Send + Sync + 'static) -> &mut Self {
        self.on_error = Some(Box::new(hook));
        self
    }

    fn emit_error(&self, error: &Error) {
        if let Some(hook) = &self.on_error {
            hook(error);
        }
    }

    /// Runs the receive hook; a failure is reported, then the connection
    /// closes. Returns whether the packet was delivered.
    fn deliver(&mut self, packet: &[u8]) -> bool {
        let Some(hook) = &self.on_receive else {
            return true;
        };
        match hook(packet) {
            Ok(()) => true,
            Err(error) => {
                self.emit_error(&error);
                self.connected = false;
                if let Some(hook) = &self.on_close {
                    hook();
                }
                self.stream = None;
                false
            }
        }
    }

    async fn fail(&mut self, code: i64, message: &str) {
        let error = Error::Runtime(format!("Failed to receive data: {code} - {message}"));
        self.emit_error(&error);
        self.handle_close().await;
    }

    async fn handle_close(&mut self) {
        if !self.connected {
            return;
        }
        self.connected = false;
        if let Some(hook) = &self.on_close {
            hook();
        }
        if let Some(mut stream) = self.stream.take() {
            let _ = stream.shutdown().await;
        }
    }

    /// Reads one whole packet (framed by its fixed header).
    async fn read(&mut self) -> Read {
        let wait = self.wait();
        let deadline = tokio::time::Instant::now() + wait;
        loop {
            if let Some(length) = whole(&self.buffer) {
                return Read::Packet(self.buffer.drain(..length).collect());
            }
            let Some(stream) = self.stream.as_mut() else {
                return Read::Closed;
            };
            let mut chunk = [0u8; 8192];
            match tokio::time::timeout_at(deadline, stream.read(&mut chunk)).await {
                Err(_) => return Read::TimedOut,
                Ok(Ok(0)) => return Read::Closed,
                Ok(Ok(n)) => self.buffer.extend_from_slice(&chunk[..n]),
                Ok(Err(e)) => {
                    let (code, message) = errno(&e);
                    return Read::Failed(code, message);
                }
            }
        }
    }
}

/// The length of the whole packet at the start of `buffer`, once it is all
/// there.
fn whole(buffer: &[u8]) -> Option<usize> {
    let mut remaining = 0usize;
    for i in 0..4 {
        let &b = buffer.get(1 + i)?;
        remaining += usize::from(b & 0x7F) << (7 * i);
        if b & 0x80 == 0 {
            let length = 2 + i + remaining;
            return (buffer.len() >= length).then_some(length);
        }
    }
    None
}

fn invalid() -> Error {
    Error::InvalidArgument("Invalid MQTT URL".into())
}

fn not_connected() -> Error {
    Error::Runtime("Not connected to MQTT broker".into())
}

/// The `errno` (Linux numbering, as Swoole reports it) and message for an
/// I/O failure.
fn errno(e: &io::Error) -> (i64, String) {
    use io::ErrorKind::*;
    let (code, message) = match e.kind() {
        ConnectionRefused => (111, "Connection refused"),
        ConnectionReset => (104, "Connection reset by peer"),
        ConnectionAborted => (103, "Software caused connection abort"),
        TimedOut => (TIMED_OUT, "Connection timed out"),
        BrokenPipe => (32, "Broken pipe"),
        AddrNotAvailable => (99, "Cannot assign requested address"),
        _ => return (e.raw_os_error().map_or(0, i64::from), e.to_string()),
    };
    (code, message.to_owned())
}

fn provider() -> Arc<rustls::crypto::CryptoProvider> {
    static PROVIDER: OnceLock<Arc<rustls::crypto::CryptoProvider>> = OnceLock::new();
    PROVIDER.get_or_init(|| Arc::new(rustls::crypto::ring::default_provider())).clone()
}

/// Accepts any certificate (checking only the handshake signatures), as
/// Swoole's client does by default.
#[derive(Debug)]
struct Unverified;

impl ServerCertVerifier for Unverified {
    fn verify_server_cert(
        &self,
        _end_entity: &CertificateDer<'_>,
        _intermediates: &[CertificateDer<'_>],
        _server_name: &ServerName<'_>,
        _ocsp_response: &[u8],
        _now: UnixTime,
    ) -> Result<ServerCertVerified, rustls::Error> {
        Ok(ServerCertVerified::assertion())
    }

    fn verify_tls12_signature(
        &self,
        message: &[u8],
        cert: &CertificateDer<'_>,
        dss: &DigitallySignedStruct,
    ) -> Result<HandshakeSignatureValid, rustls::Error> {
        rustls::crypto::verify_tls12_signature(message, cert, dss, &provider().signature_verification_algorithms)
    }

    fn verify_tls13_signature(
        &self,
        message: &[u8],
        cert: &CertificateDer<'_>,
        dss: &DigitallySignedStruct,
    ) -> Result<HandshakeSignatureValid, rustls::Error> {
        rustls::crypto::verify_tls13_signature(message, cert, dss, &provider().signature_verification_algorithms)
    }

    fn supported_verify_schemes(&self) -> Vec<SignatureScheme> {
        provider().signature_verification_algorithms.supported_schemes()
    }
}
