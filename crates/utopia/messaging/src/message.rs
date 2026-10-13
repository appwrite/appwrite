//! The messages adapters send: [`Email`], [`Sms`], [`Push`] and [`Discord`].

use php_std::Value;
use php_std::filter::{self, FILTER_FLAG_EMAIL_UNICODE, FILTER_VALIDATE_EMAIL, Options};

use crate::error::{Error, Reason};

/// The PHP class name of each message kind, which error messages quote.
pub(crate) mod class {
    pub const EMAIL: &str = "Utopia\\Messaging\\Messages\\Email";
    pub const SMS: &str = "Utopia\\Messaging\\Messages\\SMS";
    pub const PUSH: &str = "Utopia\\Messaging\\Messages\\Push";
    pub const DISCORD: &str = "Utopia\\Messaging\\Messages\\Discord";
}

/// Any message, as `send()` takes it.
///
/// PHP: `Utopia\Messaging\Message`, the interface the message classes
/// implement.
#[derive(Debug, Clone, PartialEq)]
pub enum Message {
    /// An email.
    Email(Email),
    /// A text message.
    Sms(Sms),
    /// A push notification.
    Push(Push),
    /// A Discord webhook message.
    Discord(Discord),
}

/// Which kind of message an adapter sends.
///
/// PHP: the class name `getMessageType()` returns.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum MessageType {
    /// [`Email`].
    Email,
    /// [`Sms`].
    Sms,
    /// [`Push`].
    Push,
    /// [`Discord`].
    Discord,
}

impl MessageType {
    /// The PHP class name (`Utopia\Messaging\Messages\SMS`).
    pub fn class(self) -> &'static str {
        match self {
            MessageType::Email => class::EMAIL,
            MessageType::Sms => class::SMS,
            MessageType::Push => class::PUSH,
            MessageType::Discord => class::DISCORD,
        }
    }
}

impl Message {
    /// The kind of message.
    pub fn message_type(&self) -> MessageType {
        match self {
            Message::Email(_) => MessageType::Email,
            Message::Sms(_) => MessageType::Sms,
            Message::Push(_) => MessageType::Push,
            Message::Discord(_) => MessageType::Discord,
        }
    }

    /// Who sent the message, for telemetry (`internal`, `external`).
    ///
    /// PHP: `getOrigin()`.
    pub fn origin(&self) -> Option<&str> {
        match self {
            Message::Email(m) => m.origin(),
            Message::Sms(m) => m.origin(),
            Message::Push(m) => m.origin(),
            Message::Discord(m) => m.origin(),
        }
    }

    /// How many recipients the message names, for messages that name them
    /// (Discord messages do not).
    ///
    /// PHP: `count($message->getTo())` where the message has `getTo()`.
    pub fn recipient_count(&self) -> Option<usize> {
        match self {
            Message::Email(m) => Some(m.to().len()),
            Message::Sms(m) => Some(m.to().len()),
            Message::Push(m) => Some(m.to().len()),
            Message::Discord(_) => None,
        }
    }
}

impl From<Email> for Message {
    fn from(m: Email) -> Message {
        Message::Email(m)
    }
}

impl From<Sms> for Message {
    fn from(m: Sms) -> Message {
        Message::Sms(m)
    }
}

impl From<Push> for Message {
    fn from(m: Push) -> Message {
        Message::Push(m)
    }
}

impl From<Discord> for Message {
    fn from(m: Discord) -> Message {
        Message::Discord(m)
    }
}

/// A recipient as given to [`EmailBuilder`]: a bare address, or an entry
/// with an address and an optional display name.
///
/// PHP: an entry of the `$to`, `$cc` and `$bcc` arrays: a string, or
/// `['email' => ..., 'name' => ...]`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Recipient {
    /// `'john@example.com'`.
    Address(String),
    /// `['email' => ..., 'name' => ...]`; an entry without `email` is
    /// refused.
    Entry {
        /// The address.
        email: Option<String>,
        /// The display name.
        name: Option<String>,
    },
}

impl Recipient {
    /// An entry with an address and a display name.
    pub fn named(email: impl Into<String>, name: impl Into<String>) -> Recipient {
        Recipient::Entry { email: Some(email.into()), name: Some(name.into()) }
    }

    /// An entry with only an address.
    pub fn entry(email: impl Into<String>) -> Recipient {
        Recipient::Entry { email: Some(email.into()), name: None }
    }
}

impl From<&str> for Recipient {
    fn from(email: &str) -> Recipient {
        Recipient::Address(email.to_owned())
    }
}

impl From<String> for Recipient {
    fn from(email: String) -> Recipient {
        Recipient::Address(email)
    }
}

/// A validated recipient: an address and its display name, if any.
///
/// PHP: a normalized entry of `getTo()`, `getCC()` and `getBCC()`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Mailbox {
    /// The address.
    pub email: String,
    /// The display name, when one was given.
    pub name: Option<String>,
}

impl Mailbox {
    /// The display name, or `None` when it is absent or one PHP's
    /// `empty()` reads as empty (`""`, `"0"`).
    pub fn display_name(&self) -> Option<&str> {
        self.name.as_deref().filter(|n| !n.is_empty() && *n != "0")
    }
}

/// A file attached to an email: read from `path`, or `content` when given.
///
/// PHP: `Utopia\Messaging\Messages\Email\Attachment`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Attachment {
    name: String,
    path: String,
    mime_type: String,
    content: Option<Vec<u8>>,
}

impl Attachment {
    /// A file on disk.
    ///
    /// PHP: `new Attachment($name, $path, $type)`.
    pub fn new(name: impl Into<String>, path: impl Into<String>, mime_type: impl Into<String>) -> Attachment {
        Attachment { name: name.into(), path: path.into(), mime_type: mime_type.into(), content: None }
    }

    /// With its bytes given instead of read from the path.
    ///
    /// PHP: the constructor's `$content`.
    pub fn with_content(mut self, content: impl Into<Vec<u8>>) -> Attachment {
        self.content = Some(content.into());
        self
    }

    /// The file name recipients see.
    ///
    /// PHP: `getName()`.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// Where the file is read from.
    ///
    /// PHP: `getPath()`.
    pub fn path(&self) -> &str {
        &self.path
    }

    /// The MIME type.
    ///
    /// PHP: `getType()`.
    pub fn mime_type(&self) -> &str {
        &self.mime_type
    }

    /// The bytes, when given instead of a path.
    ///
    /// PHP: `getContent()`.
    pub fn content(&self) -> Option<&[u8]> {
        self.content.as_deref()
    }
}

/// Headers the message writes itself, which a caller may not set.
const RESERVED_HEADERS: [&str; 11] = [
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

/// An email, validated when built: every address must be deliverable,
/// display names single-line, and extra headers well formed.
///
/// PHP: `Utopia\Messaging\Messages\Email`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Email {
    to: Vec<Mailbox>,
    subject: String,
    content: String,
    from_name: String,
    from_email: String,
    reply_to_name: String,
    reply_to_email: String,
    cc: Option<Vec<Mailbox>>,
    bcc: Option<Vec<Mailbox>>,
    attachments: Option<Vec<Attachment>>,
    html: bool,
    headers: Vec<(String, String)>,
    origin: Option<String>,
}

/// Builds an [`Email`].
///
/// PHP: the named arguments of `new Email(...)`.
#[derive(Debug, Clone)]
pub struct EmailBuilder {
    to: Vec<Recipient>,
    subject: String,
    content: String,
    from_name: String,
    from_email: String,
    reply_to_name: Option<String>,
    reply_to_email: Option<String>,
    cc: Option<Vec<Recipient>>,
    bcc: Option<Vec<Recipient>>,
    attachments: Option<Vec<Attachment>>,
    html: bool,
    headers: Vec<(String, String)>,
}

impl Email {
    /// Starts an email to `to`, from `from_name <from_email>`.
    pub fn builder(
        to: impl IntoIterator<Item = impl Into<Recipient>>,
        subject: impl Into<String>,
        content: impl Into<String>,
        from_name: impl Into<String>,
        from_email: impl Into<String>,
    ) -> EmailBuilder {
        EmailBuilder {
            to: to.into_iter().map(Into::into).collect(),
            subject: subject.into(),
            content: content.into(),
            from_name: from_name.into(),
            from_email: from_email.into(),
            reply_to_name: None,
            reply_to_email: None,
            cc: None,
            bcc: None,
            attachments: None,
            html: false,
            headers: Vec::new(),
        }
    }

    /// The recipients.
    ///
    /// PHP: `getTo()`.
    pub fn to(&self) -> &[Mailbox] {
        &self.to
    }

    /// The subject.
    ///
    /// PHP: `getSubject()`.
    pub fn subject(&self) -> &str {
        &self.subject
    }

    /// The body: text, or markup when [`Email::is_html`].
    ///
    /// PHP: `getContent()`.
    pub fn content(&self) -> &str {
        &self.content
    }

    /// The sender's display name.
    ///
    /// PHP: `getFromName()`.
    pub fn from_name(&self) -> &str {
        &self.from_name
    }

    /// The sender's address.
    ///
    /// PHP: `getFromEmail()`.
    pub fn from_email(&self) -> &str {
        &self.from_email
    }

    /// The reply-to display name (the sender's by default).
    ///
    /// PHP: `getReplyToName()`.
    pub fn reply_to_name(&self) -> &str {
        &self.reply_to_name
    }

    /// The reply-to address (the sender's by default); empty (or `"0"`)
    /// tells adapters to leave the header out.
    ///
    /// PHP: `getReplyToEmail()`.
    pub fn reply_to_email(&self) -> &str {
        &self.reply_to_email
    }

    /// Whether a reply-to address is set: neither empty nor `"0"`.
    pub(crate) fn has_reply_to(&self) -> bool {
        !matches!(self.reply_to_email.as_str(), "" | "0")
    }

    /// The carbon-copy recipients, when given.
    ///
    /// PHP: `getCC()`.
    pub fn cc(&self) -> Option<&[Mailbox]> {
        self.cc.as_deref()
    }

    /// The blind carbon-copy recipients, when given.
    ///
    /// PHP: `getBCC()`.
    pub fn bcc(&self) -> Option<&[Mailbox]> {
        self.bcc.as_deref()
    }

    /// The attachments, when given.
    ///
    /// PHP: `getAttachments()`.
    pub fn attachments(&self) -> Option<&[Attachment]> {
        self.attachments.as_deref()
    }

    /// Whether the content is HTML.
    ///
    /// PHP: `isHtml()`.
    pub fn is_html(&self) -> bool {
        self.html
    }

    /// Extra headers, written as given (`List-Unsubscribe`).
    ///
    /// PHP: `getHeaders()`.
    pub fn headers(&self) -> &[(String, String)] {
        &self.headers
    }

    /// Who sent the message, for telemetry.
    ///
    /// PHP: `getOrigin()`.
    pub fn origin(&self) -> Option<&str> {
        self.origin.as_deref()
    }

    /// Sets who sent the message.
    ///
    /// PHP: `setOrigin()`.
    pub fn with_origin(mut self, origin: Option<String>) -> Email {
        self.origin = origin;
        self
    }

    /// Sets who sent the message, in place.
    ///
    /// PHP: `setOrigin()`.
    pub fn set_origin(&mut self, origin: Option<String>) {
        self.origin = origin;
    }
}

impl EmailBuilder {
    /// The reply-to display name.
    pub fn reply_to_name(mut self, name: impl Into<String>) -> Self {
        self.reply_to_name = Some(name.into());
        self
    }

    /// The reply-to address; empty leaves the header out.
    pub fn reply_to_email(mut self, email: impl Into<String>) -> Self {
        self.reply_to_email = Some(email.into());
        self
    }

    /// Carbon-copy recipients.
    pub fn cc(mut self, cc: impl IntoIterator<Item = impl Into<Recipient>>) -> Self {
        self.cc = Some(cc.into_iter().map(Into::into).collect());
        self
    }

    /// Blind carbon-copy recipients.
    pub fn bcc(mut self, bcc: impl IntoIterator<Item = impl Into<Recipient>>) -> Self {
        self.bcc = Some(bcc.into_iter().map(Into::into).collect());
        self
    }

    /// Attachments.
    pub fn attachments(mut self, attachments: Vec<Attachment>) -> Self {
        self.attachments = Some(attachments);
        self
    }

    /// Whether the content is HTML.
    pub fn html(mut self, html: bool) -> Self {
        self.html = html;
        self
    }

    /// An extra header, sent to every recipient.
    pub fn header(mut self, name: impl Into<String>, value: impl Into<String>) -> Self {
        self.headers.push((name.into(), value.into()));
        self
    }

    /// Extra headers, in order.
    pub fn headers(mut self, headers: impl IntoIterator<Item = (String, String)>) -> Self {
        self.headers.extend(headers);
        self
    }

    /// Validates the email.
    ///
    /// Fails with [`Error::Invalid`] for an empty or undeliverable address
    /// (`recipient_*`, or `sender_malformed` for the sender and reply-to),
    /// a display name with control characters (`name_malformed`), or a
    /// header that is malformed, reserved, empty or repeated in another case
    /// (`header_malformed`).
    pub fn build(self) -> Result<Email, Error> {
        let normalize = |list: Vec<Recipient>| list.into_iter().map(normalize_recipient).collect::<Result<Vec<_>, _>>();
        let to = normalize(self.to)?;
        let cc = self.cc.map(normalize).transpose()?;
        let bcc = self.bcc.map(normalize).transpose()?;
        let reply_to_name = self.reply_to_name.unwrap_or_else(|| self.from_name.clone());
        let reply_to_email = self.reply_to_email.unwrap_or_else(|| self.from_email.clone());

        assert_address(&self.from_email, Reason::SenderMalformed)?;
        assert_name(&self.from_name)?;
        assert_name(&reply_to_name)?;
        if !matches!(reply_to_email.as_str(), "" | "0") {
            assert_address(&reply_to_email, Reason::SenderMalformed)?;
        }

        let mut seen: Vec<(String, &str)> = Vec::with_capacity(self.headers.len());
        for (name, value) in &self.headers {
            assert_header(name, value)?;
            let key = name.to_ascii_lowercase();
            if let Some((_, first)) = seen.iter().find(|(k, _)| *k == key) {
                return Err(Error::invalid(
                    Reason::HeaderMalformed,
                    format!("Header \"{name}\" duplicates \"{first}\" by case."),
                    Some(name.clone()),
                ));
            }
            seen.push((key, name));
        }

        Ok(Email {
            to,
            subject: self.subject,
            content: self.content,
            from_name: self.from_name,
            from_email: self.from_email,
            reply_to_name,
            reply_to_email,
            cc,
            bcc,
            attachments: self.attachments,
            html: self.html,
            headers: self.headers,
            origin: None,
        })
    }
}

fn normalize_recipient(recipient: Recipient) -> Result<Mailbox, Error> {
    match recipient {
        Recipient::Address(email) => {
            assert_address(&email, Reason::RecipientMalformed)?;
            Ok(Mailbox { email, name: None })
        }
        Recipient::Entry { email, name } => {
            let email = match email {
                Some(email) if !email.is_empty() => email,
                _ => {
                    return Err(Error::invalid(
                        Reason::RecipientEmpty,
                        "Each recipient must have a non-empty \"email\" key.",
                        None,
                    ));
                }
            };
            assert_address(&email, Reason::RecipientMalformed)?;
            assert_name(name.as_deref().unwrap_or(""))?;
            Ok(Mailbox { email, name })
        }
    }
}

/// `$` in a PCRE pattern without `/D`: the end, or before a final newline.
pub(crate) fn pcre_end(s: &[u8]) -> &[u8] {
    s.strip_suffix(b"\n").unwrap_or(s)
}

fn assert_address(email: &str, reason: Reason) -> Result<(), Error> {
    if email.is_empty() {
        let reason = if reason == Reason::SenderMalformed { reason } else { Reason::RecipientEmpty };
        return Err(Error::invalid(reason, "Email address must not be empty.", Some(String::new())));
    }
    let valid = filter::filter_var(
        &filter::Value::Str(email.as_bytes().to_vec()),
        FILTER_VALIDATE_EMAIL,
        &Options::Flags(FILTER_FLAG_EMAIL_UNICODE),
    )
    .map(|f| f.value != filter::Value::Bool(false))
    .unwrap_or(false);
    if !valid {
        return Err(Error::invalid(
            reason,
            format!("Email address \"{email}\" is not a valid address."),
            Some(email.to_owned()),
        ));
    }
    // substr($email, (int) strrpos($email, '.') + 1)
    let bytes = email.as_bytes();
    let start = bytes.iter().rposition(|&b| b == b'.').unwrap_or(0) + 1;
    let tld = pcre_end(bytes.get(start..).unwrap_or_default());
    let deliverable = match tld.get(..4) {
        Some(prefix) if prefix.eq_ignore_ascii_case(b"xn--") && tld.len() > 4 => {
            tld[4..].iter().all(|b| b.is_ascii_alphanumeric() || *b == b'-')
        }
        _ => false,
    } || (tld.len() >= 2 && tld.iter().all(u8::is_ascii_alphabetic));
    if !deliverable {
        let reason = if reason == Reason::SenderMalformed { reason } else { Reason::RecipientDomainInvalid };
        return Err(Error::invalid(
            reason,
            format!("Email address \"{email}\" has a domain that cannot receive mail."),
            Some(email.to_owned()),
        ));
    }
    Ok(())
}

fn assert_name(name: &str) -> Result<(), Error> {
    if name.bytes().any(|b| b < 0x20 || b == 0x7F) {
        return Err(Error::invalid(
            Reason::NameMalformed,
            "Display name must not contain control characters.",
            Some(name.to_owned()),
        ));
    }
    Ok(())
}

fn assert_header(name: &str, value: &str) -> Result<(), Error> {
    // A key PHP stores as an integer is not a string name.
    if php_std::types::numeric_key(name.as_bytes()).is_some() {
        return Err(Error::invalid(
            Reason::HeaderMalformed,
            "Header name must be printable ASCII without spaces or colons.",
            None,
        ));
    }
    let body = pcre_end(name.as_bytes());
    if body.is_empty() || !body.iter().all(|&b| (b'!'..=b'~').contains(&b) && b != b':') {
        return Err(Error::invalid(
            Reason::HeaderMalformed,
            "Header name must be printable ASCII without spaces or colons.",
            Some(name.to_owned()),
        ));
    }
    if RESERVED_HEADERS.contains(&name.to_ascii_lowercase().as_str()) {
        return Err(Error::invalid(
            Reason::HeaderMalformed,
            format!("The message already owns the \"{name}\" header."),
            Some(name.to_owned()),
        ));
    }
    if value.bytes().any(|b| matches!(b, b'\r' | b'\n' | 0)) {
        return Err(Error::invalid(
            Reason::HeaderMalformed,
            format!("Header \"{name}\" must be a single-line string."),
            Some(name.to_owned()),
        ));
    }
    if php_std::string::trim(value.as_bytes(), b" \t\n\r\0\x0B").is_empty() {
        return Err(Error::invalid(
            Reason::HeaderMalformed,
            format!("Header \"{name}\" must not be empty."),
            Some(name.to_owned()),
        ));
    }
    Ok(())
}

/// A text message.
///
/// PHP: `Utopia\Messaging\Messages\SMS`.
#[derive(Debug, Clone, PartialEq)]
pub struct Sms {
    to: Vec<String>,
    content: String,
    from: Option<String>,
    attachments: Option<Vec<String>>,
    metadata: Option<Value>,
    origin: Option<String>,
}

impl Sms {
    /// A message of `content` to the phone numbers in `to`.
    ///
    /// PHP: `new SMS($to, $content)`.
    pub fn new(to: impl IntoIterator<Item = impl Into<String>>, content: impl Into<String>) -> Sms {
        Sms {
            to: to.into_iter().map(Into::into).collect(),
            content: content.into(),
            from: None,
            attachments: None,
            metadata: None,
            origin: None,
        }
    }

    /// With a sender, for adapters configured without one.
    pub fn with_from(mut self, from: impl Into<String>) -> Sms {
        self.from = Some(from.into());
        self
    }

    /// With media attachments (URLs).
    pub fn with_attachments(mut self, attachments: Vec<String>) -> Sms {
        self.attachments = Some(attachments);
        self
    }

    /// With provider metadata (a PHP array): Msg91's `clientId`, `CRQID`
    /// and `UUID`, WhatsApp's `language`, `template` and `callbackData`,
    /// Bird's correlation fields.
    ///
    /// PHP: `setMetadata()`.
    pub fn with_metadata(mut self, metadata: Option<Value>) -> Sms {
        self.metadata = metadata;
        self
    }

    /// Sets the metadata in place.
    ///
    /// PHP: `setMetadata()`.
    pub fn set_metadata(&mut self, metadata: Option<Value>) {
        self.metadata = metadata;
    }

    /// Who sent the message, for telemetry.
    ///
    /// PHP: `setOrigin()`.
    pub fn with_origin(mut self, origin: Option<String>) -> Sms {
        self.origin = origin;
        self
    }

    /// The phone numbers.
    ///
    /// PHP: `getTo()`.
    pub fn to(&self) -> &[String] {
        &self.to
    }

    /// The text.
    ///
    /// PHP: `getContent()`.
    pub fn content(&self) -> &str {
        &self.content
    }

    /// The sender, when given.
    ///
    /// PHP: `getFrom()`.
    pub fn from(&self) -> Option<&str> {
        self.from.as_deref()
    }

    /// The media attachments, when given.
    ///
    /// PHP: `getAttachments()`.
    pub fn attachments(&self) -> Option<&[String]> {
        self.attachments.as_deref()
    }

    /// The metadata, when given.
    ///
    /// PHP: `getMetadata()`.
    pub fn metadata(&self) -> Option<&Value> {
        self.metadata.as_ref()
    }

    /// Who sent the message.
    ///
    /// PHP: `getOrigin()`.
    pub fn origin(&self) -> Option<&str> {
        self.origin.as_deref()
    }
}

/// How urgently a push notification is delivered.
///
/// PHP: `Utopia\Messaging\Priority`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Priority {
    /// Delivered when convenient (APNs priority 5).
    Normal,
    /// Delivered at once (APNs priority 10).
    High,
}

impl Priority {
    /// The enum's backing value (`0`, `1`).
    pub fn value(self) -> i64 {
        match self {
            Priority::Normal => 0,
            Priority::High => 1,
        }
    }

    /// The case name (`NORMAL`, `HIGH`).
    pub fn name(self) -> &'static str {
        match self {
            Priority::Normal => "NORMAL",
            Priority::High => "HIGH",
        }
    }
}

/// A push notification.
///
/// PHP: `Utopia\Messaging\Messages\Push`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Push {
    to: Vec<String>,
    title: Option<String>,
    body: Option<String>,
    data: Option<Value>,
    action: Option<String>,
    sound: Option<String>,
    image: Option<String>,
    icon: Option<String>,
    color: Option<String>,
    tag: Option<String>,
    badge: Option<i64>,
    content_available: Option<bool>,
    critical: Option<bool>,
    priority: Option<Priority>,
    channel_id: Option<String>,
    origin: Option<String>,
}

/// Builds a [`Push`].
///
/// PHP: the named arguments of `new Push(...)`.
#[derive(Debug, Clone, Default)]
pub struct PushBuilder(Push);

macro_rules! push_fields {
    ($($(#[$doc:meta])* $field:ident: $ty:ty;)*) => {
        impl PushBuilder {
            $(
                $(#[$doc])*
                pub fn $field(mut self, $field: $ty) -> Self {
                    self.0.$field = Some($field.into());
                    self
                }
            )*
        }
    };
}

push_fields! {
    /// The title.
    title: impl Into<String>;
    /// The body.
    body: impl Into<String>;
    /// Custom key-value pairs (a PHP array).
    data: Value;
    /// The action on a tap: an Android activity, an iOS category.
    action: impl Into<String>;
    /// The sound.
    sound: impl Into<String>;
    /// An image URL.
    image: impl Into<String>;
    /// Android: the notification icon.
    icon: impl Into<String>;
    /// Android: the icon color, `#rrggbb`.
    color: impl Into<String>;
    /// Android: a tag replacing an earlier notification with the same tag.
    tag: impl Into<String>;
    /// iOS: the badge count.
    badge: i64;
    /// iOS: a silent notification.
    content_available: bool;
    /// iOS: a critical alert.
    critical: bool;
    /// The priority.
    priority: Priority;
    /// Android: the notification channel.
    channel_id: impl Into<String>;
}

impl PushBuilder {
    /// Validates the notification.
    ///
    /// Fails with [`Error::Invalid`] (`message_empty`) when title, body and
    /// data are all missing.
    pub fn build(self) -> Result<Push, Error> {
        let push = self.0;
        if push.title.is_none() && push.body.is_none() && push.data.is_none() {
            return Err(Error::invalid(
                Reason::MessageEmpty,
                "At least one of the following parameters must be set: title, body, data",
                None,
            ));
        }
        Ok(push)
    }
}

impl Push {
    /// Starts a notification to the device tokens in `to`.
    pub fn builder(to: impl IntoIterator<Item = impl Into<String>>) -> PushBuilder {
        PushBuilder(Push { to: to.into_iter().map(Into::into).collect(), ..Push::default() })
    }

    /// The device tokens.
    ///
    /// PHP: `getTo()`.
    pub fn to(&self) -> &[String] {
        &self.to
    }

    /// Always `None`: notifications have no sender.
    ///
    /// PHP: `getFrom()`.
    pub fn from(&self) -> Option<&str> {
        None
    }

    /// PHP: `getTitle()`.
    pub fn title(&self) -> Option<&str> {
        self.title.as_deref()
    }

    /// PHP: `getBody()`.
    pub fn body(&self) -> Option<&str> {
        self.body.as_deref()
    }

    /// PHP: `getData()`.
    pub fn data(&self) -> Option<&Value> {
        self.data.as_ref()
    }

    /// PHP: `getAction()`.
    pub fn action(&self) -> Option<&str> {
        self.action.as_deref()
    }

    /// PHP: `getSound()`.
    pub fn sound(&self) -> Option<&str> {
        self.sound.as_deref()
    }

    /// PHP: `getImage()`.
    pub fn image(&self) -> Option<&str> {
        self.image.as_deref()
    }

    /// PHP: `getIcon()`.
    pub fn icon(&self) -> Option<&str> {
        self.icon.as_deref()
    }

    /// PHP: `getColor()`.
    pub fn color(&self) -> Option<&str> {
        self.color.as_deref()
    }

    /// PHP: `getTag()`.
    pub fn tag(&self) -> Option<&str> {
        self.tag.as_deref()
    }

    /// PHP: `getBadge()`.
    pub fn badge(&self) -> Option<i64> {
        self.badge
    }

    /// PHP: `getContentAvailable()`.
    pub fn content_available(&self) -> Option<bool> {
        self.content_available
    }

    /// PHP: `getCritical()`.
    pub fn critical(&self) -> Option<bool> {
        self.critical
    }

    /// PHP: `getPriority()`.
    pub fn priority(&self) -> Option<Priority> {
        self.priority
    }

    /// PHP: `getChannelId()`.
    pub fn channel_id(&self) -> Option<&str> {
        self.channel_id.as_deref()
    }

    /// PHP: `getOrigin()`.
    pub fn origin(&self) -> Option<&str> {
        self.origin.as_deref()
    }

    /// Who sent the message, for telemetry.
    ///
    /// PHP: `setOrigin()`.
    pub fn with_origin(mut self, origin: Option<String>) -> Push {
        self.origin = origin;
        self
    }
}

/// A message posted to a Discord webhook.
///
/// PHP: `Utopia\Messaging\Messages\Discord`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Discord {
    content: String,
    username: Option<String>,
    avatar_url: Option<String>,
    tts: Option<bool>,
    embeds: Option<Value>,
    allowed_mentions: Option<Value>,
    components: Option<Value>,
    attachments: Option<Value>,
    flags: Option<String>,
    thread_name: Option<String>,
    wait: Option<bool>,
    thread_id: Option<String>,
    origin: Option<String>,
}

macro_rules! discord_fields {
    ($($(#[$doc:meta])* $with:ident, $field:ident: $ty:ty;)*) => {
        impl Discord {
            $(
                $(#[$doc])*
                pub fn $with(mut self, $field: $ty) -> Self {
                    self.$field = Some($field.into());
                    self
                }
            )*
        }
    };
}

discord_fields! {
    /// The name the webhook posts as.
    with_username, username: impl Into<String>;
    /// The avatar the webhook posts with.
    with_avatar_url, avatar_url: impl Into<String>;
    /// Whether the message is read aloud.
    with_tts, tts: bool;
    /// Embeds (a PHP array).
    with_embeds, embeds: Value;
    /// Allowed mentions (a PHP array).
    with_allowed_mentions, allowed_mentions: Value;
    /// Components (a PHP array).
    with_components, components: Value;
    /// Attachments (a PHP array).
    with_attachments, attachments: Value;
    /// Message flags.
    with_flags, flags: impl Into<String>;
    /// The name of a thread to create (forum channels).
    with_thread_name, thread_name: impl Into<String>;
    /// Whether Discord answers with the created message (`?wait=`).
    with_wait, wait: bool;
    /// The thread to post in (`?thread_id=`).
    with_thread_id, thread_id: impl Into<String>;
}

impl Discord {
    /// A message of `content`.
    ///
    /// PHP: `new Discord($content)`.
    pub fn new(content: impl Into<String>) -> Discord {
        Discord { content: content.into(), ..Discord::default() }
    }

    /// PHP: `getContent()`.
    pub fn content(&self) -> &str {
        &self.content
    }

    /// PHP: `getUsername()`.
    pub fn username(&self) -> Option<&str> {
        self.username.as_deref()
    }

    /// PHP: `getAvatarUrl()`.
    pub fn avatar_url(&self) -> Option<&str> {
        self.avatar_url.as_deref()
    }

    /// PHP: `getTts()`.
    pub fn tts(&self) -> Option<bool> {
        self.tts
    }

    /// PHP: `getEmbeds()`.
    pub fn embeds(&self) -> Option<&Value> {
        self.embeds.as_ref()
    }

    /// PHP: `getAllowedMentions()`.
    pub fn allowed_mentions(&self) -> Option<&Value> {
        self.allowed_mentions.as_ref()
    }

    /// PHP: `getComponents()`.
    pub fn components(&self) -> Option<&Value> {
        self.components.as_ref()
    }

    /// PHP: `getAttachments()`.
    pub fn attachments(&self) -> Option<&Value> {
        self.attachments.as_ref()
    }

    /// PHP: `getFlags()`.
    pub fn flags(&self) -> Option<&str> {
        self.flags.as_deref()
    }

    /// PHP: `getThreadName()`.
    pub fn thread_name(&self) -> Option<&str> {
        self.thread_name.as_deref()
    }

    /// PHP: `getWait()`.
    pub fn wait(&self) -> Option<bool> {
        self.wait
    }

    /// PHP: `getThreadId()`.
    pub fn thread_id(&self) -> Option<&str> {
        self.thread_id.as_deref()
    }

    /// PHP: `getOrigin()`.
    pub fn origin(&self) -> Option<&str> {
        self.origin.as_deref()
    }

    /// Who sent the message, for telemetry.
    ///
    /// PHP: `setOrigin()`.
    pub fn with_origin(mut self, origin: Option<String>) -> Discord {
        self.origin = origin;
        self
    }
}
