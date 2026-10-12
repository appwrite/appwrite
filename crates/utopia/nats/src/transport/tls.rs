//! TLS from the first byte (`tls://`, the server's `handshake_first`).

use std::time::Duration;

use async_trait::async_trait;

use super::socket::{IoFailure, Stream, dial, handshake};
use super::{TlsOptions, Transport};
use crate::error::{Error, Result};

/// A TLS stream negotiated at connect, before the server's `INFO`.
///
/// PHP: `Utopia\NATS\Transport\TlsTransport`.
pub struct TlsTransport {
    options: TlsOptions,
    stream: Option<Stream>,
    timeout: Duration,
    pending: Vec<u8>,
}

impl TlsTransport {
    /// A transport that will connect with `options`.
    ///
    /// PHP: `new TlsTransport($tlsOptions)`.
    pub fn new(options: TlsOptions) -> Self {
        TlsTransport { options, stream: None, timeout: Duration::ZERO, pending: Vec::new() }
    }

    /// The TLS options the handshake uses.
    ///
    /// PHP: `TlsTransport::buildSslOptions()` (private).
    pub fn options(&self) -> &TlsOptions {
        &self.options
    }

    fn stream(&mut self) -> Result<&mut Stream> {
        self.stream.as_mut().ok_or_else(|| Error::Connection("Not connected".into()))
    }
}

#[async_trait]
impl Transport for TlsTransport {
    async fn connect(&mut self, host: &str, port: u16, timeout: Duration) -> Result<()> {
        let address = format!("tls://{host}:{port}");
        let tcp = dial(host, port, timeout)
            .await
            .map_err(|e| Error::Connection(format!("Failed to connect to {address}: {e}")))?;
        let tls = match tokio::time::timeout(timeout, handshake(tcp, host, &self.options)).await {
            Ok(Ok(tls)) => tls,
            Ok(Err(e)) => return Err(Error::Connection(format!("Failed to connect to {address}: [0] {e}"))),
            Err(_) => {
                return Err(Error::Connection(format!("Failed to connect to {address}: [110] Connection timed out")));
            }
        };
        self.stream = Some(Stream::Tls(Box::new(tls)));
        self.timeout = timeout;
        self.pending.clear();
        Ok(())
    }

    async fn write(&mut self, data: &[u8]) -> Result<usize> {
        let timeout = self.timeout;
        match self.stream()?.write_all(data, timeout).await {
            Ok(()) => Ok(data.len()),
            Err(IoFailure::Timeout) => Err(Error::Timeout("Write timed out".into())),
            Err(IoFailure::Closed) => Err(Error::Connection("Connection closed by server".into())),
            Err(IoFailure::Other) => Err(Error::Connection("Failed to write to TLS socket".into())),
        }
    }

    async fn read(&mut self, buf: &mut Vec<u8>, max: usize, timeout: Option<Duration>) -> Result<usize> {
        if let Some(timeout) = timeout {
            self.timeout = timeout;
        }
        if !self.pending.is_empty() {
            let n = max.min(self.pending.len());
            buf.extend(self.pending.drain(..n));
            return Ok(n);
        }
        let timeout = self.timeout;
        match self.stream()?.read(buf, max, timeout).await {
            Ok(n) => Ok(n),
            Err(IoFailure::Timeout) => Err(Error::Timeout("Read timed out".into())),
            Err(IoFailure::Closed) => Err(Error::Connection("Connection closed by server".into())),
            Err(IoFailure::Other) => Err(Error::Connection("Failed to read from TLS socket".into())),
        }
    }

    async fn read_line(&mut self, timeout: Option<Duration>) -> Result<Vec<u8>> {
        if let Some(timeout) = timeout {
            self.timeout = timeout;
        }
        loop {
            if let Some(at) = self.pending.iter().position(|&b| b == b'\n') {
                return Ok(self.pending.drain(..=at).collect());
            }
            let timeout = self.timeout;
            let mut pending = std::mem::take(&mut self.pending);
            let read = self.stream()?.read(&mut pending, 65536, timeout).await;
            self.pending = pending;
            match read {
                Ok(_) => {}
                Err(IoFailure::Timeout) => return Err(Error::Timeout("Read timed out".into())),
                Err(IoFailure::Closed) => return Err(Error::Connection("Connection closed by server".into())),
                Err(IoFailure::Other) => return Err(Error::Connection("Failed to read line from TLS socket".into())),
            }
        }
    }

    async fn upgrade_tls(&mut self, _options: &TlsOptions) -> Result<()> {
        Ok(())
    }

    fn is_connected(&self) -> bool {
        self.stream.as_ref().is_some_and(Stream::is_open)
    }

    async fn close(&mut self) {
        if let Some(mut stream) = self.stream.take() {
            stream.shutdown().await;
        }
        self.pending.clear();
    }
}
