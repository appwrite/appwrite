//! Plain TCP, upgradable to TLS in place.

use std::time::Duration;

use async_trait::async_trait;

use super::socket::{IoFailure, Stream, dial, handshake};
use super::{TlsOptions, Transport};
use crate::error::{Error, Result};

/// A TCP stream with Nagle's algorithm off, which
/// [`Transport::upgrade_tls`] turns into TLS when the server asks for it
/// (`tls_required`) after its plaintext `INFO`.
///
/// PHP: `Utopia\NATS\Transport\TcpTransport` (and
/// `Utopia\NATS\Transport\SwooleTransport`, its coroutine twin: every
/// transport here is async on utopia-async).
#[derive(Default)]
pub struct TcpTransport {
    stream: Option<Stream>,
    host: String,
    timeout: Duration,
    /// Bytes `read_line` read past the line, served first by later reads.
    pending: Vec<u8>,
}

impl TcpTransport {
    /// A transport that is not connected yet.
    ///
    /// PHP: `new TcpTransport()`.
    pub fn new() -> Self {
        Self::default()
    }

    fn stream(&mut self) -> Result<&mut Stream> {
        self.stream.as_mut().ok_or_else(|| Error::Connection("Not connected".into()))
    }
}

#[async_trait]
impl Transport for TcpTransport {
    async fn connect(&mut self, host: &str, port: u16, timeout: Duration) -> Result<()> {
        let stream = dial(host, port, timeout)
            .await
            .map_err(|e| Error::Connection(format!("Failed to connect to tcp://{host}:{port}: {e}")))?;
        self.stream = Some(Stream::Plain(stream));
        self.host = host.to_owned();
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
            Err(IoFailure::Other) => Err(Error::Connection("Failed to write to socket".into())),
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
            Err(IoFailure::Other) => Err(Error::Connection("Failed to read from socket".into())),
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
                Err(IoFailure::Other) => return Err(Error::Connection("Failed to read line from socket".into())),
            }
        }
    }

    async fn upgrade_tls(&mut self, options: &TlsOptions) -> Result<()> {
        match self.stream.take() {
            None => Err(Error::Connection("Not connected".into())),
            Some(Stream::Tls(tls)) => {
                self.stream = Some(Stream::Tls(tls));
                Ok(())
            }
            Some(Stream::Plain(tcp)) => match handshake(tcp, &self.host, options).await {
                Ok(tls) => {
                    self.stream = Some(Stream::Tls(Box::new(tls)));
                    Ok(())
                }
                Err(_) => Err(Error::Connection("Failed to upgrade connection to TLS".into())),
            },
        }
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
