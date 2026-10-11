//! Bytes to and from the server.
//!
//! Line assembly and the reply grammar live in the [`crate::Client`], so a
//! transport moves bytes and nothing else, and a scripted double is all a
//! test needs.

use std::future::Future;
use std::sync::{Arc, OnceLock};
use std::time::{Duration, Instant};

use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::crypto::CryptoProvider;
use rustls::pki_types::pem::PemObject;
use rustls::pki_types::{CertificateDer, ServerName, UnixTime};
use rustls::{ClientConfig, DigitallySignedStruct, RootCertStore, SignatureScheme};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use tokio_rustls::TlsConnector;
use tokio_rustls::client::TlsStream;

use crate::{Error, Tls, Verification};

/// Bytes to and from the server. Implement it to carry SMTP over something
/// other than [`TcpTransport`] (a proxy, a test double).
///
/// PHP: `Utopia\SMTP\Transport\Transport`.
pub trait Transport: Send {
    /// Opens the connection, wrapped in TLS from the first byte when `tls`.
    ///
    /// PHP: `connect()`.
    fn connect(&mut self, timeout: Duration, tls: bool) -> impl Future<Output = Result<(), Error>> + Send;

    /// Appends at least one byte and at most `length` to `buffer` and
    /// returns how many. An error rather than nothing at the end of the
    /// stream; [`Error::InvalidArgument`] for a zero `length`.
    ///
    /// PHP: `read()`.
    fn read(
        &mut self,
        buffer: &mut Vec<u8>,
        length: usize,
        timeout: Duration,
    ) -> impl Future<Output = Result<usize, Error>> + Send;

    /// Writes all of `data`.
    ///
    /// PHP: `write()`.
    fn write(&mut self, data: &[u8], timeout: Duration) -> impl Future<Output = Result<(), Error>> + Send;

    /// Upgrades an open plaintext connection, for STARTTLS.
    ///
    /// PHP: `startTls()`.
    fn start_tls(&mut self, timeout: Duration) -> impl Future<Output = Result<(), Error>> + Send;

    /// Whether the connection is encrypted.
    ///
    /// PHP: `isTls()`.
    fn is_tls(&self) -> bool;

    /// Drops the connection; safe to repeat.
    ///
    /// PHP: `close()`.
    fn close(&mut self);
}

enum Stream {
    Plain(TcpStream),
    Tls(Box<TlsStream<TcpStream>>),
}

/// TCP on tokio, with TLS by rustls.
///
/// PHP: `Utopia\SMTP\Transport\Native` and `Transport\Swoole`, which this
/// one replaces: both are the same promise on PHP's two I/O models.
///
/// ```no_run
/// use utopia_smtp::{Client, Encryption, TcpTransport, Tls};
///
/// # async fn run() -> Result<(), utopia_smtp::Error> {
/// let transport = TcpTransport::new("smtp.example.test", 587, Tls::default());
/// let mut client = Client::new(transport).with_encryption(Encryption::StartTls);
/// client.noop().await?;
/// # Ok(()) }
/// ```
///
/// ```php
/// use Utopia\SMTP\Client;
/// use Utopia\SMTP\Encryption;
/// use Utopia\SMTP\Transport\Native;
///
/// $client = new Client(new Native('smtp.example.test', 587), encryption: Encryption::StartTls);
/// ```
pub struct TcpTransport {
    host: String,
    port: u16,
    options: Tls,
    stream: Option<Stream>,
    config: Option<Arc<ClientConfig>>,
}

impl TcpTransport {
    /// A transport for `host:port`, not yet connected.
    ///
    /// PHP: `new Native($host, $port, $tls)`.
    pub fn new(host: impl Into<String>, port: u16, options: Tls) -> TcpTransport {
        TcpTransport { host: host.into(), port, options, stream: None, config: None }
    }

    fn not_connected() -> Error {
        Error::Logic("The transport is not connected".into())
    }

    fn connector(&mut self) -> Result<(TlsConnector, ServerName<'static>), Error> {
        let name = self.options.peer_name.clone().unwrap_or_else(|| self.host.clone());
        let name = ServerName::try_from(name)
            .map_err(|_| Error::Connection(format!("Not a name to check a certificate against: {}", self.host)))?;
        let config = match &self.config {
            Some(config) => config.clone(),
            None => {
                let config = Arc::new(client_config(&self.options)?);
                self.config = Some(config.clone());
                config
            }
        };
        Ok((TlsConnector::from(config), name))
    }
}

impl Transport for TcpTransport {
    async fn connect(&mut self, timeout: Duration, tls: bool) -> Result<(), Error> {
        let started = Instant::now();
        let target = format!("{}:{}", self.host, self.port);
        let reason = |error: String| {
            let reason = format!("Cannot reach {target}: {error}");
            if started.elapsed() >= timeout { Error::Timeout(reason) } else { Error::Connection(reason) }
        };
        let tcp = match tokio::time::timeout(timeout, TcpStream::connect((self.host.as_str(), self.port))).await {
            Ok(Ok(tcp)) => tcp,
            Ok(Err(e)) => return Err(reason(os_error(&e))),
            Err(_) => return Err(reason("Connection timed out".into())),
        };
        let _ = tcp.set_nodelay(true);
        let stream = if tls {
            let (connector, name) = self.connector()?;
            let left = timeout.saturating_sub(started.elapsed());
            match tokio::time::timeout(left, connector.connect(name, tcp)).await {
                Ok(Ok(stream)) => Stream::Tls(Box::new(stream)),
                Ok(Err(e)) => return Err(reason(os_error(&e))),
                Err(_) => return Err(reason("Connection timed out".into())),
            }
        } else {
            Stream::Plain(tcp)
        };
        self.stream = Some(stream);
        Ok(())
    }

    async fn read(&mut self, buffer: &mut Vec<u8>, length: usize, timeout: Duration) -> Result<usize, Error> {
        if length == 0 {
            return Err(Error::InvalidArgument("A read needs a positive length".into()));
        }
        let stream = self.stream.as_mut().ok_or_else(Self::not_connected)?;
        let start = buffer.len();
        buffer.resize(start + length, 0);
        let read = match stream {
            Stream::Plain(s) => tokio::time::timeout(timeout, s.read(&mut buffer[start..])).await,
            Stream::Tls(s) => tokio::time::timeout(timeout, s.read(&mut buffer[start..])).await,
        };
        let n = match read {
            Ok(Ok(n)) => n,
            Ok(Err(_)) => 0,
            Err(_) => {
                buffer.truncate(start);
                return Err(Error::Timeout("Timed out waiting for the server".into()));
            }
        };
        buffer.truncate(start + n);
        if n == 0 {
            return Err(Error::Connection("The server closed the connection".into()));
        }
        Ok(n)
    }

    async fn write(&mut self, data: &[u8], timeout: Duration) -> Result<(), Error> {
        let stream = self.stream.as_mut().ok_or_else(Self::not_connected)?;
        let written = match stream {
            Stream::Plain(s) => tokio::time::timeout(timeout, s.write_all(data)).await,
            Stream::Tls(s) => {
                tokio::time::timeout(timeout, async {
                    s.write_all(data).await?;
                    s.flush().await
                })
                .await
            }
        };
        match written {
            Ok(Ok(())) => Ok(()),
            Ok(Err(_)) => Err(Error::Connection("Failed writing to the server".into())),
            Err(_) => Err(Error::Timeout("Timed out writing to the server".into())),
        }
    }

    async fn start_tls(&mut self, timeout: Duration) -> Result<(), Error> {
        let tcp = match self.stream.take() {
            Some(Stream::Plain(tcp)) => tcp,
            Some(tls @ Stream::Tls(_)) => {
                self.stream = Some(tls);
                return Ok(());
            }
            None => return Err(Self::not_connected()),
        };
        let (connector, name) = self.connector()?;
        match tokio::time::timeout(timeout, connector.connect(name, tcp)).await {
            Ok(Ok(stream)) => {
                self.stream = Some(Stream::Tls(Box::new(stream)));
                Ok(())
            }
            Ok(Err(_)) => Err(Error::Connection(format!("STARTTLS handshake with {} failed", self.host))),
            Err(_) => Err(Error::Timeout(format!("STARTTLS handshake with {} timed out", self.host))),
        }
    }

    fn is_tls(&self) -> bool {
        matches!(self.stream, Some(Stream::Tls(_)))
    }

    fn close(&mut self) {
        self.stream = None;
    }
}

/// The text of an OS error without Rust's ` (os error N)` suffix, as PHP
/// reports it.
fn os_error(error: &std::io::Error) -> String {
    let text = error.to_string();
    match text.find(" (os error ") {
        Some(at) => text[..at].to_owned(),
        None => text,
    }
}

fn provider() -> Arc<CryptoProvider> {
    static PROVIDER: OnceLock<Arc<CryptoProvider>> = OnceLock::new();
    PROVIDER.get_or_init(|| Arc::new(rustls::crypto::ring::default_provider())).clone()
}

fn client_config(options: &Tls) -> Result<ClientConfig, Error> {
    let builder = ClientConfig::builder_with_provider(provider())
        .with_safe_default_protocol_versions()
        .map_err(|e| Error::Connection(e.to_string()))?;
    let config = match options.verify {
        Verification::Full => {
            let mut roots = RootCertStore::empty();
            match &options.ca_file {
                Some(file) => {
                    let certificates = CertificateDer::pem_file_iter(file)
                        .map_err(|_| Error::Connection(format!("Cannot read the CA file {}", file.display())))?;
                    for certificate in certificates.flatten() {
                        let _ = roots.add(certificate);
                    }
                }
                None => roots.extend(webpki_roots::TLS_SERVER_ROOTS.iter().cloned()),
            }
            builder.with_root_certificates(roots).with_no_client_auth()
        }
        Verification::SelfSigned | Verification::None => builder
            .dangerous()
            .with_custom_certificate_verifier(Arc::new(Lenient { name: options.verify == Verification::SelfSigned }))
            .with_no_client_auth(),
    };
    Ok(config)
}

/// Accepts any issuer; checks the name when `name` (SelfSigned) and the
/// handshake signatures always.
#[derive(Debug)]
struct Lenient {
    name: bool,
}

impl ServerCertVerifier for Lenient {
    fn verify_server_cert(
        &self,
        end_entity: &CertificateDer<'_>,
        _intermediates: &[CertificateDer<'_>],
        server_name: &ServerName<'_>,
        _ocsp_response: &[u8],
        _now: UnixTime,
    ) -> Result<ServerCertVerified, rustls::Error> {
        if self.name {
            let certificate = rustls::server::ParsedCertificate::try_from(end_entity)?;
            rustls::client::verify_server_name(&certificate, server_name)?;
        }
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
