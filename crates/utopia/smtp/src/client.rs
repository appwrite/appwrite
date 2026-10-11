//! The SMTP session.

use std::borrow::Cow;
use std::time::{Duration, Instant};

use indexmap::IndexMap;
use tokio::io::AsyncReadExt;

use crate::auth::Authenticator;
use crate::mime::encoding;
use crate::mime::part::{BLOCK, Segment, failed, unreadable};
use crate::transport::Transport;
use crate::{Capabilities, Encryption, Envelope, Error, Message, Refusal, Reply, Timeouts};

const CHUNK: usize = 8192;

/// RFC 5321 section 4.5.3.1.5 says 512 octets. Servers are looser; this is not.
const MAX_LINE: usize = 4096;

const MAX_LINES: usize = 128;

/// A mechanism that keeps challenging is failing, whatever it says.
const MAX_CHALLENGES: usize = 10;

const CLOSING: u16 = 421;

/// What one send achieved. RFC 5321 lets a server refuse some recipients and
/// accept others, and the message still reaches the rest.
///
/// PHP: `Utopia\SMTP\Result`.
#[derive(Debug, Clone, PartialEq)]
pub struct Delivery {
    /// The queue identifier read out of the final reply; empty when the
    /// server offered none.
    pub message_id: String,
    /// The recipients the server took.
    pub accepted: Vec<String>,
    /// Each refused recipient and its refusal.
    pub rejected: IndexMap<String, Reply>,
}

impl Delivery {
    /// Whether every recipient was accepted.
    ///
    /// PHP: `isComplete()`.
    pub fn is_complete(&self) -> bool {
        self.rejected.is_empty()
    }
}

/// An RFC 5321 client submitting mail to one server, over one connection,
/// opened on first use and reopened after it was dropped.
///
/// PHP: `Utopia\SMTP\Client`.
///
/// ```no_run
/// use utopia_smtp::auth::Plain;
/// use utopia_smtp::{Address, Client, Encryption, Message, TcpTransport, Tls};
///
/// # async fn run() -> Result<(), utopia_smtp::Error> {
/// let mut client = Client::new(TcpTransport::new("smtp.example.test", 587, Tls::default()))
///     .with_domain("app.example.test")
///     .with_authenticator(Plain::new("jane", "secret"))
///     .with_encryption(Encryption::StartTls);
///
/// let message = Message::builder(Address::new("jane@example.test", "Jane")?, "Hello")
///     .to(Address::new("john@example.test", "")?)
///     .text("Hi John")
///     .build()?;
///
/// let delivery = client.send(&message).await?;
/// assert!(delivery.is_complete());
/// client.close().await;
/// # Ok(()) }
/// ```
///
/// ```php
/// use Utopia\SMTP\Address;
/// use Utopia\SMTP\Auth\Plain;
/// use Utopia\SMTP\Client;
/// use Utopia\SMTP\Encryption;
/// use Utopia\SMTP\Message;
/// use Utopia\SMTP\Transport\Native;
///
/// $client = new Client(new Native('smtp.example.test', 587), 'app.example.test', [new Plain('jane', 'secret')], Encryption::StartTls);
/// $message = new Message(new Address('jane@example.test', 'Jane'), [new Address('john@example.test')], 'Hello', 'Hi John');
/// // $client->send($message); $client->close();
/// ```
pub struct Client<T: Transport> {
    transport: T,
    domain: String,
    authenticators: Vec<Box<dyn Authenticator>>,
    encryption: Encryption,
    timeouts: Timeouts,
    ready: bool,
    last_reply: Option<Instant>,
    transactions: u64,
    capabilities: Capabilities,
    /// Bytes taken off the transport but not yet consumed by a reply.
    buffer: Vec<u8>,
    /// Where the search for a line ending resumes in `buffer`.
    scanned: usize,
}

impl<T: Transport> Client<T> {
    /// A client over `transport`, naming itself `localhost`, with no
    /// authenticators, opportunistic STARTTLS and the default timeouts.
    ///
    /// PHP: `new Client($transport)`.
    pub fn new(transport: T) -> Client<T> {
        Client {
            transport,
            domain: "localhost".into(),
            authenticators: Vec::new(),
            encryption: Encryption::Opportunistic,
            timeouts: Timeouts::default(),
            ready: false,
            last_reply: None,
            transactions: 0,
            capabilities: Capabilities::none(),
            buffer: Vec::new(),
            scanned: 0,
        }
    }

    /// The EHLO argument, naming this client to the server.
    ///
    /// PHP: `$domain`.
    pub fn with_domain(mut self, domain: impl Into<String>) -> Self {
        self.domain = domain.into();
        self
    }

    /// Adds an authenticator; they are tried in order against what the
    /// server advertises.
    ///
    /// PHP: `$authenticators`.
    pub fn with_authenticator(mut self, authenticator: impl Authenticator + 'static) -> Self {
        self.authenticators.push(Box::new(authenticator));
        self
    }

    /// Adds a boxed authenticator.
    pub fn with_boxed_authenticator(mut self, authenticator: Box<dyn Authenticator>) -> Self {
        self.authenticators.push(authenticator);
        self
    }

    /// How the session is encrypted.
    ///
    /// PHP: `$encryption`.
    pub fn with_encryption(mut self, encryption: Encryption) -> Self {
        self.encryption = encryption;
        self
    }

    /// The deadlines of each wait.
    ///
    /// PHP: `$timeouts`.
    pub fn with_timeouts(mut self, timeouts: Timeouts) -> Self {
        self.timeouts = timeouts;
        self
    }

    /// The transport.
    pub fn transport(&self) -> &T {
        &self.transport
    }

    /// The transport, mutably.
    pub fn transport_mut(&mut self) -> &mut T {
        &mut self.transport
    }

    /// Messages accepted over this connection.
    ///
    /// PHP: `$client->transactions`.
    pub fn transactions(&self) -> u64 {
        self.transactions
    }

    /// Sends a message to its `To`, `Cc` and blind recipients.
    ///
    /// PHP: `send()`.
    pub async fn send(&mut self, message: &Message) -> Result<Delivery, Error> {
        let envelope = Envelope::from_message(message);
        self.transaction(&envelope, Content::Message(message)).await
    }

    /// Sends RFC 5322 bytes the client does not parse. Being whole, their
    /// size is declared to a server that takes `SIZE`, and `BODY=8BITMIME`
    /// asked for when they are not ASCII.
    ///
    /// PHP: `sendRaw($envelope, $string)`.
    pub async fn send_raw(&mut self, envelope: &Envelope, content: &[u8]) -> Result<Delivery, Error> {
        self.transaction(envelope, Content::Bytes(content)).await
    }

    /// Sends RFC 5322 bytes in pieces, stuffed as though they were one.
    ///
    /// PHP: `sendRaw($envelope, $iterable)`.
    pub async fn send_chunks<I, C>(&mut self, envelope: &Envelope, chunks: I) -> Result<Delivery, Error>
    where
        I: IntoIterator<Item = C>,
        I::IntoIter: Send,
        C: AsRef<[u8]> + Send,
    {
        let chunks: Vec<C> = chunks.into_iter().collect();
        let chunks: Vec<&[u8]> = chunks.iter().map(AsRef::as_ref).collect();
        self.transaction(envelope, Content::Chunks(&chunks)).await
    }

    /// What the server advertised, connecting first if needed.
    ///
    /// PHP: `capabilities()`.
    pub async fn capabilities(&mut self) -> Result<&Capabilities, Error> {
        self.start().await?;
        Ok(&self.capabilities)
    }

    /// Sends one command and reads its reply, which must have one of the
    /// `expect`ed codes. The escape hatch for an unmodelled verb.
    ///
    /// PHP: `command()`.
    pub async fn command(&mut self, command: &str, expect: &[u16]) -> Result<Reply, Error> {
        if command.bytes().any(|b| b == b'\r' || b == b'\n') {
            return Err(Error::InvalidArgument("A command must not span lines".into()));
        }
        let mut line = Vec::with_capacity(command.len() + 2);
        line.extend_from_slice(command.as_bytes());
        line.extend_from_slice(b"\r\n");
        self.exchange(expect, &line).await
    }

    /// `NOOP`.
    ///
    /// PHP: `noop()`.
    pub async fn noop(&mut self) -> Result<(), Error> {
        self.command("NOOP", &[250]).await.map(drop)
    }

    /// `RSET`.
    ///
    /// PHP: `reset()`.
    pub async fn reset(&mut self) -> Result<(), Error> {
        self.command("RSET", &[250]).await.map(drop)
    }

    /// How long since the server last answered; `None` when there is no
    /// session.
    ///
    /// PHP: `idle()` (`INF` without a session).
    pub fn idle(&self) -> Option<Duration> {
        if !self.ready {
            return None;
        }
        Some(self.last_reply.map_or(Duration::ZERO, |at| at.elapsed()))
    }

    /// Whether the session still answers a NOOP. One that does not is dropped.
    ///
    /// PHP: `ping()`.
    pub async fn ping(&mut self) -> Result<bool, Error> {
        if !self.ready {
            return Ok(false);
        }
        match self.noop().await {
            Ok(()) => Ok(true),
            Err(e) if e.is_smtp() => {
                self.discard();
                Ok(false)
            }
            Err(e) => Err(e),
        }
    }

    /// Says QUIT when there is a session, then drops the connection.
    ///
    /// PHP: `close()`.
    pub async fn close(&mut self) {
        let _ = self.try_close().await;
    }

    /// [`Client::close`], reporting a transport used out of order.
    pub async fn try_close(&mut self) -> Result<(), Error> {
        if self.ready {
            match self.command("QUIT", &[221]).await {
                Err(e) if !e.is_smtp() => return Err(e),
                // Saying goodbye is a courtesy; the socket closes either way.
                _ => {}
            }
        }
        self.discard();
        Ok(())
    }

    /// Drops the connection without the courtesy of a QUIT.
    fn discard(&mut self) {
        self.transport.close();
        self.ready = false;
        self.buffer.clear();
        self.scanned = 0;
        self.capabilities = Capabilities::none();
        self.transactions = 0;
    }

    async fn transaction(&mut self, envelope: &Envelope, content: Content<'_>) -> Result<Delivery, Error> {
        self.start().await?;
        let parameters = self.parameters(envelope, &content)?;
        self.command(&format!("MAIL FROM:<{}>{parameters}", envelope.sender()), &[250]).await?;

        let mut accepted = Vec::new();
        let mut rejected: IndexMap<String, Reply> = IndexMap::new();
        let mut refusal: Option<Reply> = None;
        for recipient in envelope.recipients() {
            match self.command(&format!("RCPT TO:<{recipient}>"), &[250, 251, 252]).await {
                Ok(_) => accepted.push(recipient.clone()),
                Err(Error::Transaction(refused)) => {
                    // A 421 closes the channel: stop rather than write on it.
                    if !self.ready {
                        return Err(Error::Transaction(refused));
                    }
                    // Partial refusal is normal; the message still reaches the rest.
                    if refusal.is_none() {
                        refusal = Some(refused.reply.clone());
                    }
                    rejected.insert(recipient.clone(), refused.reply);
                }
                Err(e) => return Err(e),
            }
        }
        if let Some(reply) = refusal
            && accepted.is_empty()
        {
            if self.ready {
                match self.reset().await {
                    Ok(()) => {}
                    // A session that will not reset is not one to reuse.
                    Err(e) if e.is_smtp() => self.discard(),
                    Err(e) => return Err(e),
                }
            }
            return Err(Refusal::new(reply, "Every recipient was refused", rejected).into());
        }

        self.command("DATA", &[354]).await?;
        if let Err(e) = self.data(content).await {
            // The terminating dot never went out, so the server is still
            // reading message data; the connection cannot be reused.
            self.discard();
            return Err(e);
        }

        let reply = self.exchange(&[250], b"").await?;
        let delivery = Delivery { message_id: message_id(&reply.text()), accepted, rejected };
        self.transactions += 1;
        Ok(delivery)
    }

    /// Writes the content, stuffed and terminated.
    async fn data(&mut self, content: Content<'_>) -> Result<(), Error> {
        let mut stuffer = Stuffer::default();
        let mut out = Vec::new();
        match content {
            Content::Bytes(bytes) => self.stuffed(&mut stuffer, bytes, &mut out).await?,
            Content::Chunks(chunks) => {
                for chunk in chunks {
                    self.stuffed(&mut stuffer, chunk, &mut out).await?;
                }
            }
            Content::Message(message) => {
                let mut block = Vec::new();
                for segment in message.segments() {
                    if let Segment::File(path) = segment {
                        let mut file = tokio::fs::File::open(path).await.map_err(|_| unreadable(path))?;
                        loop {
                            block.resize(BLOCK, 0);
                            let mut filled = 0;
                            while filled < BLOCK {
                                match file.read(&mut block[filled..]).await {
                                    Ok(0) => break,
                                    Ok(n) => filled += n,
                                    Err(e) if e.kind() == std::io::ErrorKind::Interrupted => {}
                                    Err(_) => return Err(failed(path)),
                                }
                            }
                            if filled == 0 {
                                break;
                            }
                            let encoded = encoding::base64(&block[..filled]);
                            self.stuffed(&mut stuffer, &encoded, &mut out).await?;
                        }
                        continue;
                    }
                    let chunk: Cow<'_, [u8]> = segment.render()?;
                    self.stuffed(&mut stuffer, &chunk, &mut out).await?;
                }
            }
        }
        if stuffer.held {
            self.transport.write(b"\r\n", self.timeouts.write()).await?;
            stuffer.start = true;
        }
        let end: &[u8] = if stuffer.start { b".\r\n" } else { b"\r\n.\r\n" };
        self.transport.write(end, self.timeouts.write()).await
    }

    async fn stuffed(&mut self, stuffer: &mut Stuffer, chunk: &[u8], out: &mut Vec<u8>) -> Result<(), Error> {
        if stuffer.push(chunk, out) {
            self.transport.write(out, self.timeouts.write()).await?;
        }
        Ok(())
    }

    /// MAIL FROM parameters, decided by what the server advertised.
    fn parameters(&self, envelope: &Envelope, content: &Content<'_>) -> Result<String, Error> {
        let mut parameters = String::new();
        if let Content::Bytes(content) = content {
            let size = content.len();
            if let Some(max) = self.capabilities.max_size()
                && size as u64 > max
            {
                return Err(Error::Capability(format!(
                    "The message is {size} bytes and the server takes at most {max}"
                )));
            }
            if self.capabilities.has("SIZE") {
                parameters.push_str(&format!(" SIZE={size}"));
            }
            if !content.is_ascii() && self.capabilities.has("8BITMIME") {
                parameters.push_str(" BODY=8BITMIME");
            }
        }
        if envelope.is_international() {
            if !self.capabilities.has("SMTPUTF8") {
                return Err(Error::Capability(
                    "The server does not offer SMTPUTF8, so a non-ASCII address cannot be sent".into(),
                ));
            }
            parameters.push_str(" SMTPUTF8");
        }
        Ok(parameters)
    }

    async fn start(&mut self) -> Result<(), Error> {
        if self.ready {
            return Ok(());
        }
        self.transport.connect(self.timeouts.connect(), self.encryption == Encryption::Implicit).await?;
        self.exchange(&[220], b"").await?;
        self.hello().await?;
        self.secure().await?;
        self.authenticate().await?;
        self.ready = true;
        Ok(())
    }

    async fn hello(&mut self) -> Result<(), Error> {
        let ehlo = format!("EHLO {}", self.domain);
        match self.command(&ehlo, &[250]).await {
            Ok(reply) => {
                self.capabilities = Capabilities::from_reply(&reply);
                Ok(())
            }
            // A server too old for ESMTP refuses EHLO outright and still
            // answers HELO; a 4xx is the server busy or closing.
            Err(Error::Transaction(refusal)) if refusal.is_permanent() => {
                let helo = format!("HELO {}", self.domain);
                self.command(&helo, &[250]).await?;
                self.capabilities = Capabilities::none();
                Ok(())
            }
            Err(e) => Err(e),
        }
    }

    async fn secure(&mut self) -> Result<(), Error> {
        if self.transport.is_tls() || self.encryption == Encryption::None {
            return Ok(());
        }
        if !self.capabilities.has("STARTTLS") {
            if self.encryption == Encryption::StartTls {
                return Err(Error::Capability("The server does not offer STARTTLS".into()));
            }
            return Ok(());
        }
        self.command("STARTTLS", &[220]).await?;
        self.transport.start_tls(self.timeouts.connect()).await?;
        // Anything buffered arrived in the clear and could have been injected
        // ahead of the handshake; RFC 3207 section 4.2 drops it with the
        // capabilities.
        self.buffer.clear();
        self.scanned = 0;
        self.hello().await
    }

    async fn authenticate(&mut self) -> Result<(), Error> {
        if self.authenticators.is_empty() {
            return Ok(());
        }
        let offered = self.capabilities.mechanisms();
        let chosen =
            self.authenticators.iter().position(|a| offered.iter().any(|m| *m == a.mechanism().to_ascii_uppercase()));
        match chosen {
            Some(index) => self.attempt(index).await,
            None => Err(Error::Authentication {
                message: format!(
                    "No shared mechanism. The server offers: {}",
                    if offered.is_empty() { "none".to_owned() } else { offered.join(", ") }
                ),
                code: 0,
            }),
        }
    }

    async fn attempt(&mut self, index: usize) -> Result<(), Error> {
        let mut command = format!("AUTH {}", self.authenticators[index].mechanism().to_ascii_uppercase());
        if let Some(initial) = self.authenticators[index].initial() {
            command.push(' ');
            // RFC 4954 spells an empty initial response as a bare equals sign.
            if initial.is_empty() {
                command.push('=');
            } else {
                command.push_str(&php_std::encoding::base64_encode(&initial));
            }
        }
        let result = async {
            let mut reply = self.command(&command, &[235, 334]).await?;
            let mut challenges = 0;
            while reply.code() == 334 {
                if challenges >= MAX_CHALLENGES {
                    return Err(Error::Authentication { message: "The server kept challenging".into(), code: 0 });
                }
                let challenge = php_std::encoding::base64_decode(reply.text().as_bytes(), true).unwrap_or_default();
                let response = self.authenticators[index].respond(&challenge, challenges);
                reply = self.command(&php_std::encoding::base64_encode(&response), &[235, 334]).await?;
                challenges += 1;
            }
            Ok(())
        }
        .await;
        match result {
            Err(Error::Transaction(refusal)) => Err(Error::Authentication {
                message: format!("Authentication failed: {}", refusal.reply),
                code: refusal.reply.code(),
            }),
            other => other,
        }
    }

    /// Writes, then reads one reply, keeping the connection only when the
    /// server gave an answer: a socket that failed or a reply that cannot be
    /// parsed drops it.
    async fn exchange(&mut self, expect: &[u16], write: &[u8]) -> Result<Reply, Error> {
        let result = async {
            if !write.is_empty() {
                self.transport.write(write, self.timeouts.write()).await?;
            }
            self.expect(expect).await
        }
        .await;
        if let Err(Error::Connection(_) | Error::Timeout(_) | Error::Protocol(_)) = &result {
            self.discard();
        }
        result
    }

    async fn expect(&mut self, expect: &[u16]) -> Result<Reply, Error> {
        let reply = self.reply().await?;
        self.last_reply = Some(Instant::now());
        if expect.contains(&reply.code()) {
            return Ok(reply);
        }
        if reply.code() == CLOSING {
            self.discard();
        }
        let wanted = expect.iter().map(u16::to_string).collect::<Vec<_>>().join(" or ");
        let message = format!("Expected {wanted}, the server said: {reply}");
        // A refusal is the server's answer; anything else is it misbehaving.
        Err(match reply.outcome() {
            crate::Outcome::Transient | crate::Outcome::Permanent => {
                Refusal::new(reply, message, IndexMap::new()).into()
            }
            _ => Error::Protocol(message),
        })
    }

    async fn reply(&mut self) -> Result<Reply, Error> {
        let mut lines: Vec<String> = Vec::new();
        let mut code = 0u16;
        loop {
            let line = self.line().await?;
            let digits = line.len() >= 3 && line[..3].iter().all(u8::is_ascii_digit);
            if !digits {
                return Err(Error::Protocol(format!("Not a reply: {}", String::from_utf8_lossy(&line))));
            }
            let this = u16::from(line[0] - b'0') * 100 + u16::from(line[1] - b'0') * 10 + u16::from(line[2] - b'0');
            if !lines.is_empty() && this != code {
                return Err(Error::Protocol(format!(
                    "The reply code changed part way through: {}",
                    String::from_utf8_lossy(&line)
                )));
            }
            code = this;
            lines.push(String::from_utf8_lossy(line.get(4..).unwrap_or_default()).into_owned());
            if lines.len() > MAX_LINES {
                return Err(Error::Protocol("The reply has too many lines".into()));
            }
            if line.get(3) != Some(&b'-') {
                break;
            }
        }
        Reply::new(code, lines)
    }

    async fn line(&mut self) -> Result<Vec<u8>, Error> {
        loop {
            let from = self.scanned.saturating_sub(1);
            if let Some(at) = self.buffer[from..].windows(2).position(|w| w == b"\r\n") {
                let at = from + at;
                let line = self.buffer[..at].to_vec();
                self.buffer.drain(..at + 2);
                self.scanned = 0;
                return Ok(line);
            }
            self.scanned = self.buffer.len();
            if self.buffer.len() > MAX_LINE {
                return Err(Error::Protocol("The reply line is too long".into()));
            }
            self.transport.read(&mut self.buffer, CHUNK, self.timeouts.read()).await?;
        }
    }
}

enum Content<'a> {
    Bytes(&'a [u8]),
    Chunks(&'a [&'a [u8]]),
    Message(&'a Message),
}

/// Transparency (RFC 5321 section 4.5.2) across chunks: line endings become
/// CRLF and a dot opening a line is doubled, wherever the chunk boundaries
/// fall.
#[derive(Debug)]
struct Stuffer {
    start: bool,
    held: bool,
}

impl Default for Stuffer {
    fn default() -> Self {
        Stuffer { start: true, held: false }
    }
}

impl Stuffer {
    /// Stuffs `chunk` into `out` (cleared first); false when there is
    /// nothing to write.
    fn push(&mut self, chunk: &[u8], out: &mut Vec<u8>) -> bool {
        out.clear();
        let mut joined: Cow<'_, [u8]> = if std::mem::take(&mut self.held) {
            let mut v = Vec::with_capacity(chunk.len() + 1);
            v.push(b'\r');
            v.extend_from_slice(chunk);
            Cow::Owned(v)
        } else {
            Cow::Borrowed(chunk)
        };
        if joined.last() == Some(&b'\r') {
            self.held = true;
            joined = match joined {
                Cow::Borrowed(b) => Cow::Borrowed(&b[..b.len() - 1]),
                Cow::Owned(mut v) => {
                    v.pop();
                    Cow::Owned(v)
                }
            };
        }
        if joined.is_empty() {
            return false;
        }
        let normal = encoding::line_endings(&joined);
        out.reserve(normal.len() + 8);
        if self.start && normal.first() == Some(&b'.') {
            out.push(b'.');
        }
        let mut i = 0;
        while let Some(at) = memchr_crlf_dot(&normal[i..]) {
            out.extend_from_slice(&normal[i..i + at + 3]);
            out.push(b'.');
            i += at + 3;
        }
        out.extend_from_slice(&normal[i..]);
        self.start = out.ends_with(b"\r\n");
        true
    }
}

fn memchr_crlf_dot(s: &[u8]) -> Option<usize> {
    s.windows(3).position(|w| w == b"\r\n.")
}

/// The queue identifier servers report in the final reply, each in its own
/// way: `queued as X`, `id=X`, or `Ok <hex>`.
fn message_id(text: &str) -> String {
    let t = text.as_bytes();
    let word = |i: usize| i > 0 && (t[i - 1].is_ascii_alphanumeric() || t[i - 1] == b'_');
    let starts = |i: usize, p: &[u8]| t.len() >= i + p.len() && t[i..i + p.len()].eq_ignore_ascii_case(p);
    for i in 0..t.len() {
        if !word(i) && starts(i, b"queued as ") {
            let run = t[i + 10..].iter().take_while(|b| b.is_ascii_alphanumeric()).count();
            if run > 0 {
                return text[i + 10..i + 10 + run].to_owned();
            }
        }
    }
    let space = |b: &u8| matches!(b, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r');
    for i in 0..t.len() {
        if !word(i) && starts(i, b"id=") {
            let run = t[i + 3..].iter().take_while(|b| !space(b)).count();
            if run > 0 {
                return text[i + 3..i + 3 + run].to_owned();
            }
        }
    }
    for i in 0..t.len() {
        if !word(i) && starts(i, b"ok ") {
            let run = t[i + 3..].iter().take_while(|b| b.is_ascii_hexdigit() || **b == b'-').count();
            if run >= 8 {
                return text[i + 3..i + 3 + run].to_owned();
            }
        }
    }
    String::new()
}
