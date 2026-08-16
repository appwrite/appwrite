//! PHP `Utopia\Messaging\Messages\Email`.

use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
pub mod attachment;
pub use attachment::Attachment;

/// One To/Cc/Bcc entry after PHP `normalizeRecipient`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Recipient {
    /// Required non-empty email.
    pub email: String,
    /// Optional display name (omitted when the PHP value was a plain string).
    pub name: Option<String>,
}

impl Recipient {
    /// Build from a plain email string (PHP string recipient).
    pub fn from_email(email: impl Into<String>) -> Result<Self, MessagingError> {
        let email = email.into();
        if email.is_empty() {
            return Err(MessagingError::invalid_argument(
                "Recipient email must not be empty.",
            ));
        }
        Ok(Self { email, name: None })
    }

    /// Build from PHP `['email' => ..., 'name' => ...]`.
    pub fn from_parts(
        email: impl Into<String>,
        name: Option<String>,
    ) -> Result<Self, MessagingError> {
        let email = email.into();
        if email.is_empty() {
            return Err(MessagingError::invalid_argument(
                "Each recipient must have a non-empty \"email\" key.",
            ));
        }
        Ok(Self { email, name })
    }
}

/// Input for [`Email::new`] recipients (string or `{email, name}`).
#[derive(Debug, Clone)]
pub enum RecipientInput {
    /// PHP string email.
    Email(String),
    /// PHP associative array with `email` and optional `name`.
    Named {
        /// Recipient address.
        email: String,
        /// Optional display name.
        name: Option<String>,
    },
}

impl From<&str> for RecipientInput {
    fn from(value: &str) -> Self {
        Self::Email(value.to_string())
    }
}

impl From<String> for RecipientInput {
    fn from(value: String) -> Self {
        Self::Email(value)
    }
}

impl RecipientInput {
    /// PHP associative recipient with a display name.
    #[must_use]
    pub fn named(email: impl Into<String>, name: impl Into<String>) -> Self {
        Self::Named {
            email: email.into(),
            name: Some(name.into()),
        }
    }

    /// PHP associative recipient with only `email`.
    #[must_use]
    pub fn email_only(email: impl Into<String>) -> Self {
        Self::Named {
            email: email.into(),
            name: None,
        }
    }

    fn normalize(self) -> Result<Recipient, MessagingError> {
        match self {
            Self::Email(email) => Recipient::from_email(email),
            Self::Named { email, name } => {
                if email.is_empty() {
                    return Err(MessagingError::invalid_argument(
                        "Each recipient must have a non-empty \"email\" key.",
                    ));
                }
                Recipient::from_parts(email, name)
            }
        }
    }
}

/// PHP `Utopia\Messaging\Messages\Email`.
#[derive(Debug, Clone)]
pub struct Email {
    to: Vec<Recipient>,
    subject: String,
    content: String,
    from_name: String,
    from_email: String,
    reply_to_name: String,
    reply_to_email: String,
    cc: Option<Vec<Recipient>>,
    bcc: Option<Vec<Recipient>>,
    attachments: Option<Vec<Attachment>>,
    html: bool,
    origin: Option<String>,
}

impl Email {
    /// PHP `__construct`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        to: Vec<RecipientInput>,
        subject: impl Into<String>,
        content: impl Into<String>,
        from_name: impl Into<String>,
        from_email: impl Into<String>,
        reply_to_name: Option<String>,
        reply_to_email: Option<String>,
        cc: Option<Vec<RecipientInput>>,
        bcc: Option<Vec<RecipientInput>>,
        attachments: Option<Vec<Attachment>>,
        html: bool,
    ) -> Result<Self, MessagingError> {
        let from_name = from_name.into();
        let from_email = from_email.into();
        let to = to
            .into_iter()
            .map(RecipientInput::normalize)
            .collect::<Result<Vec<_>, _>>()?;
        let cc = match cc {
            None => None,
            Some(list) => Some(
                list.into_iter()
                    .map(RecipientInput::normalize)
                    .collect::<Result<Vec<_>, _>>()?,
            ),
        };
        let bcc = match bcc {
            None => None,
            Some(list) => Some(
                list.into_iter()
                    .map(RecipientInput::normalize)
                    .collect::<Result<Vec<_>, _>>()?,
            ),
        };
        let reply_to_name = reply_to_name.unwrap_or_else(|| from_name.clone());
        let reply_to_email = reply_to_email.unwrap_or_else(|| from_email.clone());
        Ok(Self {
            to,
            subject: subject.into(),
            content: content.into(),
            from_name,
            from_email,
            reply_to_name,
            reply_to_email,
            cc,
            bcc,
            attachments,
            html,
            origin: None,
        })
    }

    /// PHP `getTo`.
    #[must_use]
    pub fn get_to(&self) -> &[Recipient] {
        &self.to
    }

    /// PHP `getSubject`.
    #[must_use]
    pub fn get_subject(&self) -> &str {
        &self.subject
    }

    /// PHP `getContent`.
    #[must_use]
    pub fn get_content(&self) -> &str {
        &self.content
    }

    /// PHP `getFromName`.
    #[must_use]
    pub fn get_from_name(&self) -> &str {
        &self.from_name
    }

    /// PHP `getFromEmail`.
    #[must_use]
    pub fn get_from_email(&self) -> &str {
        &self.from_email
    }

    /// PHP `getReplyToName`.
    #[must_use]
    pub fn get_reply_to_name(&self) -> &str {
        &self.reply_to_name
    }

    /// PHP `getReplyToEmail`.
    #[must_use]
    pub fn get_reply_to_email(&self) -> &str {
        &self.reply_to_email
    }

    /// PHP `getCC`.
    #[must_use]
    pub fn get_cc(&self) -> Option<&[Recipient]> {
        self.cc.as_deref()
    }

    /// PHP `getBCC`.
    #[must_use]
    pub fn get_bcc(&self) -> Option<&[Recipient]> {
        self.bcc.as_deref()
    }

    /// PHP `getAttachments`.
    #[must_use]
    pub fn get_attachments(&self) -> Option<&[Attachment]> {
        self.attachments.as_deref()
    }

    /// PHP `isHtml`.
    #[must_use]
    pub fn is_html(&self) -> bool {
        self.html
    }

    /// Fluent origin setter (PHP `setOrigin` chain).
    #[must_use]
    pub fn with_origin(mut self, origin: Option<String>) -> Self {
        self.origin = origin;
        self
    }
}

impl Message for Email {
    fn set_origin(&mut self, origin: Option<String>) {
        self.origin = origin;
    }

    fn get_origin(&self) -> Option<&str> {
        self.origin.as_deref()
    }

    fn kind(&self) -> MessageKind {
        MessageKind::Email
    }

    fn to_count(&self) -> Option<usize> {
        Some(self.to.len())
    }

    fn as_email(&self) -> Option<&Email> {
        Some(self)
    }
}
