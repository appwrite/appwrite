//! Messages, requests and inbox subjects.

use bytes::Bytes;
use rand::Rng;

use crate::error::{Error, Result};
use crate::headers::Headers;

/// A message received from the server (or built to publish).
///
/// PHP: `Utopia\NATS\Message` (public readonly properties `subject`,
/// `data`, `replyTo`, `headers`, `sid`).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Message {
    /// The subject it was published to.
    pub subject: String,
    /// The payload, as bytes.
    pub data: Bytes,
    /// Where to send a reply, if the publisher asked for one.
    pub reply_to: Option<String>,
    /// Headers, for messages sent with `HPUB`.
    pub headers: Option<Headers>,
    /// The id of the subscription it was delivered to.
    pub sid: Option<String>,
}

impl Message {
    /// A message on `subject` carrying `data`, without reply subject,
    /// headers or subscription.
    ///
    /// PHP: `new Message($subject, $data)`.
    pub fn new(subject: impl Into<String>, data: impl Into<Bytes>) -> Self {
        Message { subject: subject.into(), data: data.into(), ..Default::default() }
    }

    /// The status of its headers (`"503"`, `"100"`, ...), or `""`.
    pub fn status(&self) -> &str {
        self.headers.as_ref().map_or("", Headers::status)
    }
}

/// One request of a batch (see [`crate::Connection::request_batch`]): a
/// subject that can be published to, a payload and optional headers.
///
/// ```
/// use utopia_nats::Request;
///
/// assert!(Request::new("orders.create", "{}", None).is_ok());
/// assert!(Request::new("orders.*", "", None).is_err()); // wildcards cannot be published to
/// ```
///
/// ```php
/// use Utopia\NATS\Request;
///
/// new Request('orders.create', '{}');
/// try {
///     new Request('orders.*');
/// } catch (\InvalidArgumentException $e) {
///     echo $e->getMessage(), "\n"; // Invalid request subject
/// }
/// ```
///
/// PHP: `Utopia\NATS\Request`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    subject: String,
    data: Vec<u8>,
    headers: Option<Headers>,
}

impl Request {
    /// A request, once its subject is valid: not empty, without whitespace,
    /// NUL, `*` or `>`, not starting or ending with `.`, and without `..`.
    ///
    /// # Errors
    ///
    /// [`Error::InvalidArgument`] (`Invalid request subject`).
    ///
    /// PHP: `new Request($subject, $data, $headers)`.
    pub fn new(subject: impl Into<String>, data: impl Into<Vec<u8>>, headers: Option<Headers>) -> Result<Self> {
        let subject = subject.into();
        if !valid_subject(subject.as_bytes()) {
            return Err(Error::InvalidArgument("Invalid request subject".into()));
        }
        Ok(Request { subject, data: data.into(), headers })
    }

    /// The subject to send to.
    pub fn subject(&self) -> &str {
        &self.subject
    }

    /// The payload.
    pub fn data(&self) -> &[u8] {
        &self.data
    }

    /// The headers, if any.
    pub fn headers(&self) -> Option<&Headers> {
        self.headers.as_ref()
    }
}

/// PHP's `$subject === '' || preg_match('/[\s\x00*>]/', $subject)` and the
/// dot rules, on bytes (`\s` is ASCII without `/u`).
fn valid_subject(subject: &[u8]) -> bool {
    !subject.is_empty()
        && !subject.iter().any(|&b| matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r' | 0 | b'*' | b'>'))
        && subject[0] != b'.'
        && subject[subject.len() - 1] != b'.'
        && !subject.windows(2).any(|w| w == b"..")
}

/// Unique inbox subjects for replies.
///
/// PHP: `Utopia\NATS\Inbox`.
pub struct Inbox;

impl Inbox {
    const CHARSET: &'static [u8; 62] = b"0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
    const ID_LENGTH: usize = 22;

    /// `prefix.<22 random alphanumerics>`.
    ///
    /// PHP: `Inbox::create()`.
    pub fn create(prefix: &str) -> String {
        let mut inbox = String::with_capacity(prefix.len() + 1 + Self::ID_LENGTH);
        inbox.push_str(prefix);
        inbox.push('.');
        Self::push_id(&mut inbox);
        inbox
    }

    /// 22 random characters from `[0-9A-Za-z]`.
    ///
    /// PHP: `Inbox::generateId()`.
    pub fn generate_id() -> String {
        let mut id = String::with_capacity(Self::ID_LENGTH);
        Self::push_id(&mut id);
        id
    }

    fn push_id(out: &mut String) {
        let mut bytes = [0u8; Self::ID_LENGTH];
        rand::rng().fill_bytes(&mut bytes);
        out.extend(bytes.iter().map(|b| Self::CHARSET[usize::from(*b) % Self::CHARSET.len()] as char));
    }
}
