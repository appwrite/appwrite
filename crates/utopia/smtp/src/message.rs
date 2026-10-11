//! RFC 5322 messages.

use crate::address::spans_lines;
use crate::mime::part::{Chunks, Segment};
use crate::mime::{Part, encoding, header};
use crate::{Address, Attachment, Error};

/// Headers the message owns, which a caller must not set by hand.
const RESERVED: &[&str] = &[
    "date",
    "from",
    "to",
    "cc",
    "bcc",
    "reply-to",
    "subject",
    "message-id",
    "mime-version",
    "content-type",
    "content-transfer-encoding",
];

/// A point in time with the UTC offset it is written in, for the `Date`
/// header.
///
/// PHP: the `\DateTimeImmutable` a `Message` is given.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Date {
    timestamp: i64,
    offset: i32,
}

impl Date {
    /// `timestamp` (seconds since the Unix epoch) written at `offset`
    /// seconds east of UTC.
    pub fn new(timestamp: i64, offset: i32) -> Date {
        Date { timestamp, offset }
    }

    /// Now, in UTC.
    pub fn now() -> Date {
        Date::new(php_std::datetime::time(), 0)
    }

    /// Seconds since the Unix epoch.
    pub fn timestamp(&self) -> i64 {
        self.timestamp
    }

    /// Seconds east of UTC.
    pub fn offset(&self) -> i32 {
        self.offset
    }

    /// RFC 5322 form: `Thu, 13 Aug 2026 09:30:00 +0000`.
    ///
    /// PHP: `format(DateTimeInterface::RFC2822)`.
    pub fn rfc2822(&self) -> String {
        let local = php_std::datetime::gmdate(b"D, d M Y H:i:s", self.timestamp + i64::from(self.offset));
        let sign = if self.offset < 0 { '-' } else { '+' };
        let minutes = self.offset.unsigned_abs() / 60;
        format!("{} {sign}{:02}{:02}", String::from_utf8_lossy(&local), minutes / 60, minutes % 60)
    }
}

/// An RFC 5322 message, with the MIME structure chosen from what was set:
/// one text part, or `multipart/alternative` for text and HTML, inside
/// `multipart/related` with inline attachments, inside `multipart/mixed`
/// with the others.
///
/// Blind recipients are carried in the envelope ([`crate::Envelope::from_message`]),
/// never written to a header.
///
/// PHP: `Utopia\SMTP\Message`.
///
/// ```
/// use utopia_smtp::{Address, Date, Message};
///
/// let message = Message::builder(Address::new("jane@example.test", "Jane Doe")?, "Hello")
///     .to(Address::new("john@example.test", "")?)
///     .text("Body")
///     .date(Date::new(1_786_613_400, 0))
///     .message_id("fixed@example.test")
///     .build()?;
///
/// let rendered = String::from_utf8(message.to_bytes()?).unwrap();
/// assert!(rendered.starts_with("Date: Thu, 13 Aug 2026 09:30:00 +0000\r\nFrom: \"Jane Doe\" <jane@example.test>\r\n"));
/// # Ok::<(), utopia_smtp::Error>(())
/// ```
///
/// ```php
/// use Utopia\SMTP\Address;
/// use Utopia\SMTP\Message;
///
/// $message = new Message(
///     from: new Address('jane@example.test', 'Jane Doe'),
///     to: [new Address('john@example.test')],
///     subject: 'Hello',
///     text: 'Body',
///     date: new \DateTimeImmutable('@1786613400'),
///     messageId: 'fixed@example.test',
/// );
/// echo substr((string) $message, 0, 37), "\n"; // Date: Thu, 13 Aug 2026 09:30:00 +0000
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Message {
    from: Address,
    to: Vec<Address>,
    cc: Vec<Address>,
    bcc: Vec<Address>,
    reply_to: Vec<Address>,
    subject: String,
    headers: Vec<(String, String)>,
    date: Date,
    message_id: String,
    root: Part,
}

/// Collects what a [`Message`] carries; [`MessageBuilder::build`] checks it.
///
/// PHP: the named arguments of `new Message(...)`.
#[derive(Debug, Clone)]
pub struct MessageBuilder {
    from: Address,
    to: Vec<Address>,
    cc: Vec<Address>,
    bcc: Vec<Address>,
    reply_to: Vec<Address>,
    subject: String,
    text: Option<Vec<u8>>,
    html: Option<Vec<u8>>,
    attachments: Vec<Attachment>,
    headers: Vec<(String, String)>,
    date: Option<Date>,
    message_id: Option<String>,
}

impl MessageBuilder {
    /// Adds a `To` recipient.
    pub fn to(mut self, address: Address) -> Self {
        self.to.push(address);
        self
    }

    /// Adds a `Cc` recipient.
    pub fn cc(mut self, address: Address) -> Self {
        self.cc.push(address);
        self
    }

    /// Adds a blind recipient: in the envelope, never in a header.
    pub fn bcc(mut self, address: Address) -> Self {
        self.bcc.push(address);
        self
    }

    /// Adds a `Reply-To` address.
    pub fn reply_to(mut self, address: Address) -> Self {
        self.reply_to.push(address);
        self
    }

    /// The plain text body.
    pub fn text(mut self, text: impl Into<Vec<u8>>) -> Self {
        self.text = Some(text.into());
        self
    }

    /// The HTML body.
    pub fn html(mut self, html: impl Into<Vec<u8>>) -> Self {
        self.html = Some(html.into());
        self
    }

    /// Adds an attachment; an inline one (with a content id) goes in a
    /// `multipart/related` tree with the body.
    pub fn attachment(mut self, attachment: Attachment) -> Self {
        self.attachments.push(attachment);
        self
    }

    /// Adds a header of the caller's own. Setting a name twice keeps its
    /// first position and its last value.
    pub fn header(mut self, name: impl Into<String>, value: impl Into<String>) -> Self {
        let (name, value) = (name.into(), value.into());
        match self.headers.iter_mut().find(|(n, _)| *n == name) {
            Some(entry) => entry.1 = value,
            None => self.headers.push((name, value)),
        }
        self
    }

    /// The `Date` header; now when unset.
    pub fn date(mut self, date: Date) -> Self {
        self.date = Some(date);
        self
    }

    /// The `Message-ID`, without angle brackets; random at the sender's
    /// domain when unset.
    pub fn message_id(mut self, id: impl Into<String>) -> Self {
        self.message_id = Some(id.into());
        self
    }

    /// The message. Refuses one without recipients, a subject or custom
    /// header that spans lines, and a custom header the message owns.
    pub fn build(self) -> Result<Message, Error> {
        if self.to.is_empty() && self.cc.is_empty() && self.bcc.is_empty() {
            return Err(Error::InvalidArgument("A message needs at least one recipient".into()));
        }
        if self.subject.bytes().any(|b| b == b'\r' || b == b'\n') {
            return Err(Error::InvalidArgument("A subject must not span lines".into()));
        }
        for (name, value) in &self.headers {
            if RESERVED.contains(&name.to_ascii_lowercase().as_str()) {
                return Err(Error::InvalidArgument(format!("The message owns the {name} header")));
            }
            if spans_lines(name) || spans_lines(value) {
                return Err(Error::InvalidArgument(format!("The {name} header must not span lines")));
            }
        }
        let date = self.date.unwrap_or_else(Date::now);
        let message_id = self.message_id.unwrap_or_else(|| {
            let email = self.from.email();
            format!("{}{}", encoding::random_hex(), &email[email.rfind('@').unwrap_or(0)..])
        });
        let root = build(self.text, self.html, self.attachments);
        Ok(Message {
            from: self.from,
            to: self.to,
            cc: self.cc,
            bcc: self.bcc,
            reply_to: self.reply_to,
            subject: self.subject,
            headers: self.headers,
            date,
            message_id,
            root,
        })
    }
}

fn build(text: Option<Vec<u8>>, html: Option<Vec<u8>>, attachments: Vec<Attachment>) -> Part {
    let mut parts = Vec::with_capacity(2);
    if let Some(text) = text {
        parts.push(Part::text("text/plain", &text));
    }
    if let Some(html) = html {
        parts.push(Part::text("text/html", &html));
    }
    let mut body = match parts.len() {
        0 => Part::text("text/plain", b""),
        1 => parts.remove(0),
        _ => Part::multipart("alternative", parts),
    };
    let (inline, files): (Vec<Attachment>, Vec<Attachment>) = attachments.into_iter().partition(|a| a.cid().is_some());
    if !inline.is_empty() {
        let mut parts = vec![body];
        parts.extend(inline.into_iter().map(Part::attachment));
        body = Part::multipart("related", parts);
    }
    if !files.is_empty() {
        let mut parts = vec![body];
        parts.extend(files.into_iter().map(Part::attachment));
        return Part::multipart("mixed", parts);
    }
    body
}

impl Message {
    /// Starts a message from `from` with `subject`.
    pub fn builder(from: Address, subject: impl Into<String>) -> MessageBuilder {
        MessageBuilder {
            from,
            to: Vec::new(),
            cc: Vec::new(),
            bcc: Vec::new(),
            reply_to: Vec::new(),
            subject: subject.into(),
            text: None,
            html: None,
            attachments: Vec::new(),
            headers: Vec::new(),
            date: None,
            message_id: None,
        }
    }

    /// The sender.
    ///
    /// PHP: `$message->from`.
    pub fn from(&self) -> &Address {
        &self.from
    }

    /// The `To` recipients.
    pub fn to(&self) -> &[Address] {
        &self.to
    }

    /// The `Cc` recipients.
    pub fn cc(&self) -> &[Address] {
        &self.cc
    }

    /// The blind recipients.
    pub fn bcc(&self) -> &[Address] {
        &self.bcc
    }

    /// The `Reply-To` addresses.
    pub fn reply_to(&self) -> &[Address] {
        &self.reply_to
    }

    /// The subject.
    pub fn subject(&self) -> &str {
        &self.subject
    }

    /// The caller's own headers.
    pub fn headers(&self) -> &[(String, String)] {
        &self.headers
    }

    /// The `Date` header.
    ///
    /// PHP: `$message->date`.
    pub fn date(&self) -> Date {
        self.date
    }

    /// The `Message-ID`, without angle brackets.
    ///
    /// PHP: `$message->messageId`.
    pub fn message_id(&self) -> &str {
        &self.message_id
    }

    /// The root of the MIME tree.
    pub fn root(&self) -> &Part {
        &self.root
    }

    /// Whether sending needs the SMTPUTF8 extension of RFC 6531: some
    /// address, `Reply-To` included, is not ASCII.
    ///
    /// PHP: `isInternational()`.
    pub fn is_international(&self) -> bool {
        std::iter::once(&self.from)
            .chain(&self.to)
            .chain(&self.cc)
            .chain(&self.bcc)
            .chain(&self.reply_to)
            .any(Address::is_international)
    }

    /// The message in the pieces it is written in: the headers, then the
    /// MIME tree, a file read block by block.
    ///
    /// PHP: `toIterable()`.
    pub fn chunks(&self) -> Chunks<'_> {
        Chunks::new(self.segments())
    }

    pub(crate) fn segments(&self) -> Vec<Segment<'_>> {
        let mut segments = vec![Segment::MessageHead(self)];
        self.root.segments(&mut segments);
        segments
    }

    /// The whole message.
    ///
    /// PHP: `__toString()`.
    pub fn to_bytes(&self) -> Result<Vec<u8>, Error> {
        let mut out = Vec::with_capacity(1024);
        for chunk in self.chunks() {
            out.extend_from_slice(&chunk?);
        }
        Ok(out)
    }

    /// The message's headers followed by the root part's.
    pub(crate) fn head(&self) -> Result<String, Error> {
        let mut out = String::with_capacity(512);
        let field = |name: &str, value: &str, out: &mut String| {
            if value.is_empty() { Ok(()) } else { header::line_into(name, value, out) }
        };
        field("Date", &self.date.rfc2822(), &mut out)?;
        field("From", &self.from.to_string(), &mut out)?;
        field("To", &list(&self.to), &mut out)?;
        field("Cc", &list(&self.cc), &mut out)?;
        field("Reply-To", &list(&self.reply_to), &mut out)?;
        field("Subject", &self.subject, &mut out)?;
        field("Message-ID", &format!("<{}>", self.message_id), &mut out)?;
        field("MIME-Version", "1.0", &mut out)?;
        for (name, value) in &self.headers {
            field(name, value, &mut out)?;
        }
        self.root.head_into(&mut out)?;
        Ok(out)
    }
}

fn list(addresses: &[Address]) -> String {
    let mut out = String::new();
    for (i, address) in addresses.iter().enumerate() {
        if i > 0 {
            out.push_str(", ");
        }
        use std::fmt::Write;
        let _ = write!(out, "{address}");
    }
    out
}
