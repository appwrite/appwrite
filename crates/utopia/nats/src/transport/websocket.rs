//! NATS over WebSockets (`ws://`, `wss://`).

use std::time::Duration;

use async_trait::async_trait;
use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use rand::Rng;
use sha1::{Digest, Sha1};

use super::socket::{IoFailure, Stream, dial, handshake};
use super::{TlsOptions, Transport};
use crate::error::{Error, Result};

const ACCEPT_GUID: &str = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

/// How much one socket read takes.
const READ_SIZE: usize = 65536;

/// Where a whole frame sits in the raw bytes.
struct Header {
    opcode: u8,
    /// Where the payload starts.
    start: usize,
    length: usize,
    mask: Option<[u8; 4]>,
}

/// The NATS protocol inside binary WebSocket frames (RFC 6455). Frames the
/// client sends are masked; reads serve the unframed byte stream, answering
/// pings and failing on a close frame.
///
/// PHP: `Utopia\NATS\Transport\WebSocketTransport`.
pub struct WebSocketTransport {
    secure: bool,
    options: TlsOptions,
    path: String,
    stream: Option<Stream>,
    timeout: Duration,
    /// Unframed bytes not consumed yet.
    buffer: Vec<u8>,
    /// Raw bytes from the socket that do not make up a whole frame yet.
    raw: Vec<u8>,
}

impl WebSocketTransport {
    /// A transport for `ws://` (or `wss://` when `secure`), upgrading at
    /// `path` (NATS serves `/`).
    ///
    /// PHP: `new WebSocketTransport($secure, $tlsOptions, $path)`.
    pub fn new(secure: bool, options: TlsOptions, path: impl Into<String>) -> Self {
        WebSocketTransport {
            secure,
            options,
            path: path.into(),
            stream: None,
            timeout: Duration::ZERO,
            buffer: Vec::new(),
            raw: Vec::new(),
        }
    }

    fn stream(&mut self) -> Result<&mut Stream> {
        self.stream.as_mut().ok_or_else(|| Error::Connection("Not connected".into()))
    }

    async fn raw_write(&mut self, data: &[u8]) -> Result<()> {
        let timeout = self.timeout;
        self.stream()?
            .write_all(data, timeout)
            .await
            .map_err(|_| Error::Connection("Failed to write to WebSocket".into()))
    }

    /// Appends one socket read to the raw bytes.
    ///
    /// PHP: the private `WebSocketTransport::receive()`.
    async fn receive(&mut self) -> Result<()> {
        let timeout = self.timeout;
        let mut raw = std::mem::take(&mut self.raw);
        let read = match self.stream() {
            Ok(stream) => stream.read(&mut raw, READ_SIZE, timeout).await.map(|_| ()),
            Err(error) => {
                self.raw = raw;
                return Err(error);
            }
        };
        self.raw = raw;
        match read {
            Ok(()) => Ok(()),
            Err(IoFailure::Timeout) => Err(Error::Timeout("Read timed out".into())),
            Err(_) => Err(Error::Connection("Connection closed by server".into())),
        }
    }

    /// The next frame's header, once the whole frame is buffered.
    ///
    /// # Errors
    ///
    /// [`Error::Connection`] for a 64-bit length with its most significant
    /// bit set.
    ///
    /// PHP: the private `WebSocketTransport::header()`.
    fn header(&self) -> Result<Option<Header>> {
        let raw = &self.raw;
        if raw.len() < 2 {
            return Ok(None);
        }
        let second = raw[1];
        let mut length = u64::from(second & 0x7F);
        let mut start = match length {
            126 => 4,
            127 => 10,
            _ => 2,
        };
        if raw.len() < start {
            return Ok(None);
        }
        if start == 10 && raw[2] & 0x80 != 0 {
            return Err(Error::Connection("WebSocket frame length has its most significant bit set".into()));
        }
        if start > 2 {
            length = raw[2..start].iter().fold(0u64, |acc, &b| (acc << 8) | u64::from(b));
        }
        let mut mask = None;
        if second & 0x80 != 0 {
            // PHP's substr() takes what there is of the key.
            let mut key = [0u8; 4];
            let end = raw.len().min(start + 4);
            key[..end - start].copy_from_slice(&raw[start..end]);
            mask = Some(key);
            start += 4;
        }
        if (raw.len() as u64) < start as u64 + length {
            return Ok(None);
        }
        Ok(Some(Header { opcode: raw[0] & 0x0F, start, length: length as usize, mask }))
    }

    async fn upgrade(&mut self, host: &str, port: u16) -> Result<()> {
        let mut key = [0u8; 16];
        rand::rng().fill_bytes(&mut key);
        let key = BASE64.encode(key);
        let request = format!(
            "GET {} HTTP/1.1\r\nHost: {host}:{port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n",
            self.path
        );
        self.raw_write(request.as_bytes()).await?;

        let end = loop {
            if let Some(at) = self.raw.windows(4).position(|w| w == b"\r\n\r\n") {
                break at + 4;
            }
            self.receive().await?;
        };
        let response: Vec<u8> = self.raw.drain(..end).collect();
        if response.len() < 12 || !response[..12].eq_ignore_ascii_case(b"HTTP/1.1 101") {
            let status = response.split(|&b| b == b'\r' || b == b'\n').find(|l| !l.is_empty()).unwrap_or_default();
            return Err(Error::Connection(format!("WebSocket upgrade failed: {}", String::from_utf8_lossy(status))));
        }
        let expected = BASE64.encode(Sha1::digest(format!("{key}{ACCEPT_GUID}").as_bytes()));
        if accept_header(&response).as_deref() != Some(expected.as_bytes()) {
            return Err(Error::Connection("WebSocket upgrade failed: bad Sec-WebSocket-Accept".into()));
        }
        Ok(())
    }

    /// Reads one frame: data frames append to the buffer, pings are
    /// answered. A frame is decoded only once all of it has arrived, so a
    /// read that times out mid-frame loses nothing.
    async fn read_frame(&mut self) -> Result<()> {
        let header = loop {
            if let Some(header) = self.header()? {
                break header;
            }
            self.receive().await?;
        };
        let mut payload: Vec<u8> = self.raw.drain(..header.start + header.length).skip(header.start).collect();
        if let Some(mask) = header.mask {
            for (i, b) in payload.iter_mut().enumerate() {
                *b ^= mask[i % 4];
            }
        }
        match header.opcode {
            0x0..=0x2 => {
                self.buffer.extend_from_slice(&payload);
                Ok(())
            }
            0x8 => Err(Error::Connection("WebSocket closed by server".into())),
            0x9 => self.write_frame(&payload, 0xA).await,
            0xA => Ok(()),
            other => Err(Error::Connection(format!("Unexpected WebSocket opcode: {other}"))),
        }
    }

    async fn write_frame(&mut self, payload: &[u8], opcode: u8) -> Result<()> {
        let len = payload.len();
        let mut frame = Vec::with_capacity(len + 14);
        frame.push(0x80 | opcode);
        if len < 126 {
            frame.push(0x80 | len as u8);
        } else if len <= 0xFFFF {
            frame.push(0x80 | 126);
            frame.extend_from_slice(&(len as u16).to_be_bytes());
        } else {
            frame.push(0x80 | 127);
            frame.extend_from_slice(&(len as u64).to_be_bytes());
        }
        let mut mask = [0u8; 4];
        rand::rng().fill_bytes(&mut mask);
        frame.extend_from_slice(&mask);
        frame.extend(payload.iter().enumerate().map(|(i, b)| b ^ mask[i % 4]));
        self.raw_write(&frame).await
    }
}

/// The value of the `Sec-WebSocket-Accept` header (case-insensitive name,
/// leading whitespace skipped, trimmed), as PHP's
/// `#Sec-WebSocket-Accept:\s*(.+?)\r\n#i` and `trim()` find it.
fn accept_header(response: &[u8]) -> Option<Vec<u8>> {
    const NAME: &[u8] = b"sec-websocket-accept:";
    let at = response.windows(NAME.len()).position(|w| w.eq_ignore_ascii_case(NAME))?;
    let mut rest = &response[at + NAME.len()..];
    while let [b, tail @ ..] = rest
        && matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
    {
        rest = tail;
    }
    let end = rest.windows(2).skip(1).position(|w| w == b"\r\n")? + 1;
    Some(php_std::string::trim(&rest[..end], b" \t\n\r\0\x0B").to_vec())
}

#[async_trait]
impl Transport for WebSocketTransport {
    async fn connect(&mut self, host: &str, port: u16, timeout: Duration) -> Result<()> {
        let failed = |e: &str| Error::Connection(format!("Failed to connect to {host}:{port}: {e}"));
        let tcp = dial(host, port, timeout).await.map_err(|e| failed(&e))?;
        let stream = if self.secure {
            let tls = handshake(tcp, host, &self.options).await.map_err(|e| failed(&format!("[0] {e}")))?;
            Stream::Tls(Box::new(tls))
        } else {
            Stream::Plain(tcp)
        };
        self.stream = Some(stream);
        self.timeout = timeout;
        self.buffer.clear();
        self.raw.clear();
        self.upgrade(host, port).await
    }

    async fn write(&mut self, data: &[u8]) -> Result<usize> {
        self.write_frame(data, 0x2).await?;
        Ok(data.len())
    }

    async fn read(&mut self, buf: &mut Vec<u8>, max: usize, timeout: Option<Duration>) -> Result<usize> {
        if let Some(timeout) = timeout {
            self.stream()?;
            self.timeout = timeout;
        }
        while self.buffer.is_empty() {
            self.read_frame().await?;
        }
        let n = max.min(self.buffer.len());
        buf.extend(self.buffer.drain(..n));
        Ok(n)
    }

    async fn read_line(&mut self, timeout: Option<Duration>) -> Result<Vec<u8>> {
        if let Some(timeout) = timeout {
            self.stream()?;
            self.timeout = timeout;
        }
        loop {
            if let Some(at) = self.buffer.iter().position(|&b| b == b'\n') {
                return Ok(self.buffer.drain(..=at).collect());
            }
            self.read_frame().await?;
        }
    }

    async fn upgrade_tls(&mut self, _options: &TlsOptions) -> Result<()> {
        Err(Error::Connection("STARTTLS is not supported over WebSocket; use wss://".into()))
    }

    fn is_connected(&self) -> bool {
        self.stream.as_ref().is_some_and(Stream::is_open)
    }

    fn poll(&mut self) -> Result<bool> {
        if !self.buffer.is_empty() || self.header()?.is_some() {
            return Ok(true);
        }
        Ok(self.stream.as_mut().is_none_or(Stream::ready))
    }

    async fn close(&mut self) {
        if let Some(mut stream) = self.stream.take() {
            stream.shutdown().await;
        }
    }
}
