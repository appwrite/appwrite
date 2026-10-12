//! The socket under the network transports: plain TCP or TLS over TCP, with
//! a PHP-stream-like timeout that every read and write uses.

use std::sync::{Arc, OnceLock};
use std::time::Duration;

use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::crypto::CryptoProvider;
use rustls::pki_types::pem::PemObject;
use rustls::pki_types::{CertificateDer, PrivateKeyDer, ServerName, UnixTime};
use rustls::{ClientConfig, DigitallySignedStruct, RootCertStore, SignatureScheme};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use tokio_rustls::TlsConnector;
use tokio_rustls::client::TlsStream;

use super::TlsOptions;
use crate::error::{Error, Result};

/// An open stream.
pub(crate) enum Stream {
    Plain(TcpStream),
    Tls(Box<TlsStream<TcpStream>>),
}

/// How a failed read or write ended.
pub(crate) enum IoFailure {
    /// The stream timeout passed.
    Timeout,
    /// The peer closed the stream (EOF, reset, broken pipe).
    Closed,
    /// Anything else.
    Other,
}

impl Stream {
    /// Reads at most `max` bytes into `buf` within `timeout`.
    pub(crate) async fn read(&mut self, buf: &mut Vec<u8>, max: usize, timeout: Duration) -> Result<usize, IoFailure> {
        let start = buf.len();
        buf.resize(start + max.max(1), 0);
        let target = &mut buf[start..];
        let read = match self {
            Stream::Plain(s) => tokio::time::timeout(timeout, s.read(target)).await,
            Stream::Tls(s) => tokio::time::timeout(timeout, s.read(target)).await,
        };
        let outcome = match read {
            Err(_) => Err(IoFailure::Timeout),
            Ok(Ok(0)) => Err(IoFailure::Closed),
            Ok(Ok(n)) => Ok(n),
            Ok(Err(e)) => Err(failure(&e)),
        };
        buf.truncate(start + *outcome.as_ref().unwrap_or(&0));
        outcome
    }

    /// Writes all of `data` within `timeout`.
    pub(crate) async fn write_all(&mut self, data: &[u8], timeout: Duration) -> Result<(), IoFailure> {
        let written = match self {
            Stream::Plain(s) => {
                tokio::time::timeout(timeout, async {
                    s.write_all(data).await?;
                    s.flush().await
                })
                .await
            }
            Stream::Tls(s) => {
                tokio::time::timeout(timeout, async {
                    s.write_all(data).await?;
                    s.flush().await
                })
                .await
            }
        };
        match written {
            Err(_) => Err(IoFailure::Timeout),
            Ok(Ok(())) => Ok(()),
            Ok(Err(e)) => Err(failure(&e)),
        }
    }

    /// Whether the peer has not closed the stream: a non-blocking peek
    /// that finds the end of the stream means it did (PHP's `feof()`).
    pub(crate) fn is_open(&self) -> bool {
        let tcp = match self {
            Stream::Plain(s) => s,
            Stream::Tls(s) => s.get_ref().0,
        };
        let mut byte = [0u8; 1];
        !matches!(futures_util::FutureExt::now_or_never(tcp.peek(&mut byte)), Some(Ok(0) | Err(_)))
    }

    pub(crate) async fn shutdown(&mut self) {
        match self {
            Stream::Plain(s) => {
                let _ = s.shutdown().await;
            }
            Stream::Tls(s) => {
                let _ = s.shutdown().await;
            }
        }
    }
}

fn failure(error: &std::io::Error) -> IoFailure {
    use std::io::ErrorKind::*;
    match error.kind() {
        TimedOut | WouldBlock => IoFailure::Timeout,
        UnexpectedEof | ConnectionReset | ConnectionAborted | BrokenPipe | NotConnected => IoFailure::Closed,
        _ => IoFailure::Other,
    }
}

/// Opens a TCP connection with Nagle off, as PHP's `tcp_nodelay` context
/// option does. The error is `[errno] text`, as `stream_socket_client`
/// reports it.
pub(crate) async fn dial(host: &str, port: u16, timeout: Duration) -> Result<TcpStream, String> {
    match tokio::time::timeout(timeout, TcpStream::connect((host, port))).await {
        Err(_) => Err("[110] Connection timed out".into()),
        Ok(Err(e)) => Err(format!("[{}] {}", e.raw_os_error().unwrap_or(0), os_error(&e))),
        Ok(Ok(stream)) => {
            let _ = stream.set_nodelay(true);
            Ok(stream)
        }
    }
}

/// The text of an OS error without Rust's ` (os error N)` suffix.
fn os_error(error: &std::io::Error) -> String {
    let text = error.to_string();
    match text.find(" (os error ") {
        Some(at) => text[..at].to_owned(),
        None => text,
    }
}

/// The TLS client handshake over `tcp`.
pub(crate) async fn handshake(tcp: TcpStream, host: &str, options: &TlsOptions) -> Result<TlsStream<TcpStream>> {
    let config = client_config(options)?;
    let name = options.peer_name.as_deref().unwrap_or(host);
    let server = ServerName::try_from(name.to_owned())
        .map_err(|_| Error::Connection(format!("Invalid TLS server name: {name}")))?;
    TlsConnector::from(Arc::new(config))
        .connect(server, tcp)
        .await
        .map_err(|e| Error::Connection(format!("TLS handshake failed: {e}")))
}

fn provider() -> Arc<CryptoProvider> {
    static PROVIDER: OnceLock<Arc<CryptoProvider>> = OnceLock::new();
    PROVIDER.get_or_init(|| Arc::new(rustls::crypto::ring::default_provider())).clone()
}

fn client_config(options: &TlsOptions) -> Result<ClientConfig> {
    let builder = ClientConfig::builder_with_provider(provider())
        .with_safe_default_protocol_versions()
        .map_err(|e| Error::Connection(e.to_string()))?;
    let builder = if options.verify_peer {
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
        if options.verify_peer_name {
            builder.with_root_certificates(roots)
        } else {
            let inner = rustls::client::WebPkiServerVerifier::builder_with_provider(Arc::new(roots), provider())
                .build()
                .map_err(|e| Error::Connection(e.to_string()))?;
            builder.dangerous().with_custom_certificate_verifier(Arc::new(Lenient { chain: Some(inner) }))
        }
    } else {
        builder.dangerous().with_custom_certificate_verifier(Arc::new(Lenient { chain: None }))
    };
    match (&options.cert_file, &options.key_file) {
        (Some(cert), key) => {
            let chain: Vec<CertificateDer<'static>> = CertificateDer::pem_file_iter(cert)
                .map_err(|_| Error::Connection(format!("Cannot read the certificate file {}", cert.display())))?
                .flatten()
                .collect();
            let key_path = key.as_ref().unwrap_or(cert);
            let key = PrivateKeyDer::from_pem_file(key_path)
                .map_err(|_| Error::Connection(format!("Cannot read the key file {}", key_path.display())))?;
            builder.with_client_auth_cert(chain, key).map_err(|e| Error::Connection(e.to_string()))
        }
        (None, _) => Ok(builder.with_no_client_auth()),
    }
}

/// Verifies the chain without the name (`verify_peer_name: false`), or
/// nothing at all (`verify_peer: false`); handshake signatures always.
#[derive(Debug)]
struct Lenient {
    chain: Option<Arc<rustls::client::WebPkiServerVerifier>>,
}

impl ServerCertVerifier for Lenient {
    fn verify_server_cert(
        &self,
        end_entity: &CertificateDer<'_>,
        intermediates: &[CertificateDer<'_>],
        server_name: &ServerName<'_>,
        ocsp_response: &[u8],
        now: UnixTime,
    ) -> Result<ServerCertVerified, rustls::Error> {
        if let Some(chain) = &self.chain {
            match chain.verify_server_cert(end_entity, intermediates, server_name, ocsp_response, now) {
                Ok(verified) => return Ok(verified),
                Err(rustls::Error::InvalidCertificate(rustls::CertificateError::NotValidForName))
                | Err(rustls::Error::InvalidCertificate(rustls::CertificateError::NotValidForNameContext { .. })) => {}
                Err(e) => return Err(e),
            }
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
