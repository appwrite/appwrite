//! Decodes server operations from the byte stream.

use std::time::Duration;

use serde_json::Value;

use super::ServerOp;
use crate::error::{Error, Result};
use crate::transport::Transport;

/// One operation read from the server.
///
/// PHP: the `[ServerOp, $data]` tuple `Parser::next()` returns.
#[derive(Debug, Clone, PartialEq)]
pub enum Op {
    /// `INFO {json}`, decoded.
    Info(Value),
    /// `MSG`.
    Msg(RawMessage),
    /// `HMSG`.
    HMsg(RawMessage),
    /// `PING`.
    Ping,
    /// `PONG`.
    Pong,
    /// `+OK`.
    Ok,
    /// `-ERR 'message'`, without the quotes.
    Err(String),
}

impl Op {
    /// The kind of operation.
    pub fn kind(&self) -> ServerOp {
        match self {
            Op::Info(_) => ServerOp::Info,
            Op::Msg(_) => ServerOp::Msg,
            Op::HMsg(_) => ServerOp::HMsg,
            Op::Ping => ServerOp::Ping,
            Op::Pong => ServerOp::Pong,
            Op::Ok => ServerOp::Ok,
            Op::Err(_) => ServerOp::Err,
        }
    }
}

/// A `MSG` or `HMSG` frame as read, before it becomes a [`crate::Message`].
///
/// PHP: the array `['subject', 'sid', 'replyTo', 'payload', 'headers']`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RawMessage {
    /// The subject.
    pub subject: String,
    /// The subscription id.
    pub sid: String,
    /// The reply subject.
    pub reply_to: Option<String>,
    /// The payload.
    pub payload: Vec<u8>,
    /// The raw header block (`HMSG` only).
    pub headers: Option<Vec<u8>>,
}

/// Reads server operations off a [`Transport`], buffering what it read past
/// the current operation.
///
/// A frame body that fails half way (its header line consumed, its payload
/// not) poisons the parser: every later call fails with
/// [`Error::Connection`] until the connection builds a new one.
///
/// ```
/// # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
/// use utopia_nats::protocol::{Op, Parser};
/// # use utopia_nats::transport::{TlsOptions, Transport};
/// # struct Script(Vec<u8>);
/// # #[async_trait::async_trait]
/// # impl Transport for Script {
/// #     async fn connect(&mut self, _: &str, _: u16, _: std::time::Duration) -> utopia_nats::Result<()> { Ok(()) }
/// #     async fn write(&mut self, d: &[u8]) -> utopia_nats::Result<usize> { Ok(d.len()) }
/// #     async fn read(&mut self, b: &mut Vec<u8>, max: usize, _: Option<std::time::Duration>) -> utopia_nats::Result<usize> {
/// #         let n = max.min(self.0.len()); b.extend(self.0.drain(..n)); Ok(n)
/// #     }
/// #     async fn read_line(&mut self, _: Option<std::time::Duration>) -> utopia_nats::Result<Vec<u8>> { Ok(vec![]) }
/// #     async fn upgrade_tls(&mut self, _: &TlsOptions) -> utopia_nats::Result<()> { Ok(()) }
/// #     fn is_connected(&self) -> bool { true }
/// #     async fn close(&mut self) {}
/// # }
/// let mut transport = Script(b"PING\r\nMSG orders.new 1 5\r\nhello\r\n".to_vec());
/// let mut parser = Parser::new();
/// assert_eq!(parser.next(&mut transport, None).await?, Op::Ping);
/// let Op::Msg(msg) = parser.next(&mut transport, None).await? else { unreachable!() };
/// assert_eq!((msg.subject.as_str(), msg.payload.as_slice()), ("orders.new", &b"hello"[..]));
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\NATS\Protocol\Parser;
/// use Utopia\NATS\Transport\Transport;
///
/// $transport = new class implements Transport {
///     public string $data = "PING\r\nMSG orders.new 1 5\r\nhello\r\n";
///     public function connect(string $host, int $port, float $timeout): void {}
///     public function write(string $data): int { return strlen($data); }
///     public function read(int $maxBytes, ?float $timeout = null): string { $chunk = substr($this->data, 0, $maxBytes); $this->data = substr($this->data, strlen($chunk)); return $chunk; }
///     public function readLine(?float $timeout = null): string { return ''; }
///     public function upgradeTls(array $options): void {}
///     public function isConnected(): bool { return true; }
///     public function close(): void {}
/// };
/// $parser = new Parser($transport);
/// [$op] = $parser->next();       // ServerOp::Ping
/// [$op, $msg] = $parser->next(); // ServerOp::Msg
/// echo $msg['subject'], ' ', $msg['payload'], "\n"; // orders.new hello
/// ```
///
/// PHP: `Utopia\NATS\Protocol\Parser` (which holds its transport; here the
/// caller passes it to each [`Parser::next`]).
#[derive(Debug, Default)]
pub struct Parser {
    buffer: Vec<u8>,
    /// Where unread data starts in `buffer` (consumed bytes are dropped
    /// lazily, to avoid moving the rest after every operation).
    start: usize,
    poisoned: bool,
}

impl Parser {
    /// A parser with nothing buffered.
    ///
    /// PHP: `new Parser($transport)`.
    pub fn new() -> Self {
        Self::default()
    }

    /// Whether bytes were received that are not parsed yet.
    ///
    /// PHP: `Parser::hasBufferedData()`.
    pub fn has_buffered_data(&self) -> bool {
        self.start < self.buffer.len()
    }

    /// Reads and decodes the next operation. `timeout` applies to each read
    /// of the transport; `None` keeps the transport's current timeout.
    ///
    /// # Errors
    ///
    /// [`Error::Protocol`] for an empty or unknown line, [`Error::Json`] for
    /// an `INFO` that is not JSON, [`Error::Desynced`] when a frame body
    /// fails (and [`Error::Connection`] on every call after that), and the
    /// transport's errors while waiting for a line.
    ///
    /// PHP: `Parser::next()`.
    pub async fn next(&mut self, transport: &mut (dyn Transport + '_), timeout: Option<Duration>) -> Result<Op> {
        if self.poisoned {
            return Err(Error::Connection("Parser desynced from the stream; the connection must be rebuilt".into()));
        }
        let line = self.read_line(transport, timeout).await?;
        let mut end = line.len();
        while end > 0 && matches!(line[end - 1], b'\r' | b'\n') {
            end -= 1;
        }
        let line = &line[..end];
        if line.is_empty() {
            return Err(Error::protocol("Empty protocol line received"));
        }
        match line {
            b"+OK" => return Ok(Op::Ok),
            b"PING" => return Ok(Op::Ping),
            b"PONG" => return Ok(Op::Pong),
            _ => {}
        }
        if line.starts_with(b"-ERR") {
            let message = php_std::string::trim(&line[4..], b" \t'\"");
            return Ok(Op::Err(String::from_utf8_lossy(message).into_owned()));
        }
        if let Some(json) = line.strip_prefix(b"INFO ") {
            return Ok(Op::Info(crate::json::decode(json)?));
        }
        if let Some(args) = line.strip_prefix(b"MSG ") {
            let args = args.to_vec();
            let frame = self.read_msg(transport, &args, timeout).await;
            return self.frame(frame);
        }
        if let Some(args) = line.strip_prefix(b"HMSG ") {
            let args = args.to_vec();
            let frame = self.read_hmsg(transport, &args, timeout).await;
            return self.frame(frame);
        }
        Err(Error::protocol_bytes([b"Unknown protocol operation: ".as_slice(), line].concat()))
    }

    /// A frame body's outcome: a failure poisons the parser.
    fn frame(&mut self, frame: Result<Op>) -> Result<Op> {
        frame.map_err(|e| {
            self.poisoned = true;
            self.buffer.clear();
            self.start = 0;
            Error::Desynced(Box::new(e))
        })
    }

    async fn read_msg(
        &mut self,
        transport: &mut (dyn Transport + '_),
        args: &[u8],
        timeout: Option<Duration>,
    ) -> Result<Op> {
        let parts = split_whitespace(php_std::string::trim(args, b" \t\n\r\0\x0B"));
        if parts.len() < 3 || parts.len() > 4 {
            return Err(Error::protocol_bytes([b"Invalid MSG line: MSG ".as_slice(), args].concat()));
        }
        let (subject, sid, reply_to, count) = match parts.as_slice() {
            [subject, sid, count] => (subject, sid, None, count),
            [subject, sid, reply, count] => (subject, sid, Some(reply), count),
            _ => unreachable!(),
        };
        let bytes = php_std::format::str_to_int(count);
        let payload = self.read_exactly(transport, bytes, timeout).await?;
        self.read_exactly(transport, 2, timeout).await?;
        Ok(Op::Msg(RawMessage {
            subject: text(subject),
            sid: text(sid),
            reply_to: reply_to.map(|r| text(r)),
            payload,
            headers: None,
        }))
    }

    async fn read_hmsg(
        &mut self,
        transport: &mut (dyn Transport + '_),
        args: &[u8],
        timeout: Option<Duration>,
    ) -> Result<Op> {
        let parts = split_whitespace(php_std::string::trim(args, b" \t\n\r\0\x0B"));
        if parts.len() < 4 || parts.len() > 5 {
            return Err(Error::protocol_bytes([b"Invalid HMSG line: HMSG ".as_slice(), args].concat()));
        }
        let (subject, sid, reply_to, header_bytes, total_bytes) = match parts.as_slice() {
            [subject, sid, header, total] => (subject, sid, None, header, total),
            [subject, sid, reply, header, total] => (subject, sid, Some(reply), header, total),
            _ => unreachable!(),
        };
        let header_len = php_std::format::str_to_int(header_bytes);
        let total_len = php_std::format::str_to_int(total_bytes);
        let payload_len = total_len.wrapping_sub(header_len);
        if payload_len < 0 {
            return Err(Error::protocol(format!("Invalid HMSG byte counts: header={header_len}, total={total_len}")));
        }
        let headers = self.read_exactly(transport, header_len, timeout).await?;
        let payload =
            if payload_len > 0 { self.read_exactly(transport, payload_len, timeout).await? } else { Vec::new() };
        self.read_exactly(transport, 2, timeout).await?;
        Ok(Op::HMsg(RawMessage {
            subject: text(subject),
            sid: text(sid),
            reply_to: reply_to.map(|r| text(r)),
            payload,
            headers: Some(headers),
        }))
    }

    /// The next line, `\n` included.
    async fn read_line(&mut self, transport: &mut (dyn Transport + '_), timeout: Option<Duration>) -> Result<Vec<u8>> {
        // Bytes after `start` already searched for the line end.
        let mut scanned = 0;
        loop {
            let from = self.start + scanned;
            if let Some(at) = self.buffer[from..].iter().position(|&b| b == b'\n') {
                let end = from + at + 1;
                let line = self.buffer[self.start..end].to_vec();
                self.consume(end);
                return Ok(line);
            }
            scanned = self.buffer.len() - self.start;
            self.compact();
            transport.read(&mut self.buffer, 65536, timeout).await?;
        }
    }

    /// `substr($buffer, 0, $bytes)` after reading until the buffer holds at
    /// least `bytes` bytes, with PHP's `substr` semantics for a negative
    /// count (taken from the end).
    async fn read_exactly(
        &mut self,
        transport: &mut (dyn Transport + '_),
        bytes: i64,
        timeout: Option<Duration>,
    ) -> Result<Vec<u8>> {
        loop {
            let held = (self.buffer.len() - self.start) as i64;
            if held >= bytes {
                break;
            }
            self.compact();
            let want = 65536.max(bytes - held) as usize;
            if transport.read(&mut self.buffer, want, timeout).await? == 0 {
                return Err(Error::protocol("Unexpected end of data while reading payload"));
            }
        }
        let held = &self.buffer[self.start..];
        let taken = php_std::string::substr(held, 0, Some(bytes)).to_vec();
        let rest_from = held.len() - php_std::string::substr(held, bytes, None).len();
        let end = self.start + rest_from;
        self.consume(end);
        Ok(taken)
    }

    fn consume(&mut self, end: usize) {
        self.start = end;
        if self.start == self.buffer.len() {
            self.buffer.clear();
            self.start = 0;
        }
    }

    /// Drops consumed bytes before the buffer grows.
    fn compact(&mut self) {
        if self.start > 0 {
            self.buffer.drain(..self.start);
            self.start = 0;
        }
    }
}

fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

/// `preg_split('/\s+/', $s)`: pieces between runs of ASCII whitespace,
/// empty pieces kept at the ends.
fn split_whitespace(s: &[u8]) -> Vec<&[u8]> {
    let space = |b: u8| matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r');
    let mut parts = Vec::with_capacity(5);
    let mut from = 0;
    let mut i = 0;
    while i < s.len() {
        if space(s[i]) {
            parts.push(&s[from..i]);
            while i < s.len() && space(s[i]) {
                i += 1;
            }
            from = i;
        } else {
            i += 1;
        }
    }
    parts.push(&s[from..]);
    parts
}
