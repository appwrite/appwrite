//! Messages and their attachments.

use std::borrow::Cow;

use crate::role::ROLE_USER;

/// One message of a conversation: who sent it (its role), its content, and
/// the messages attached to it (images, as their raw bytes).
///
/// Content is bytes, as PHP strings are: an attachment carries an image's
/// binary data. Text is UTF-8 in practice; [`Message::text`] reads it.
///
/// PHP: `Utopia\Agents\Message`.
///
/// ```
/// use utopia_agents::Message;
///
/// let png = b"\x89PNG\r\n\x1a\n\0\0\0\rIHDR".to_vec();
/// let mut message = Message::new("What is in this image?");
/// message.add_attachment(Message::new(png));
///
/// assert_eq!(message.role(), "user");
/// assert!(message.has_attachments());
/// assert_eq!(message.attachments()[0].mime_type(), Some("image/png"));
/// assert_eq!(message.with_role("assistant").role(), "assistant");
/// ```
///
/// ```php
/// use Utopia\Agents\Message;
///
/// $png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";
/// $message = new Message('What is in this image?');
/// $message->addAttachment(new Message($png));
///
/// echo $message->getRole(), "\n";                            // user
/// var_dump($message->hasAttachments());                      // bool(true)
/// echo $message->getAttachments()[0]->getMimeType(), "\n";   // image/png
/// echo $message->withRole('assistant')->getRole(), "\n";     // assistant
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Message {
    role: String,
    content: Vec<u8>,
    attachments: Vec<Message>,
}

impl Message {
    /// A message from the user ([`ROLE_USER`]) without attachments.
    ///
    /// PHP: `new Message($content)`.
    pub fn new(content: impl Into<Vec<u8>>) -> Message {
        Message { role: ROLE_USER.to_owned(), content: content.into(), attachments: Vec::new() }
    }

    /// A message with a role and attachments.
    ///
    /// PHP: `new Message($content, $role, $attachments)`; a `null` role is
    /// [`ROLE_USER`].
    pub fn with(content: impl Into<Vec<u8>>, role: Option<&str>, attachments: Vec<Message>) -> Message {
        Message { role: role.unwrap_or(ROLE_USER).to_owned(), content: content.into(), attachments }
    }

    /// The role: `user`, `assistant`, or any other a caller set.
    ///
    /// PHP: `getRole()`.
    pub fn role(&self) -> &str {
        &self.role
    }

    /// The content, as bytes.
    ///
    /// PHP: `getContent()`.
    pub fn content(&self) -> &[u8] {
        &self.content
    }

    /// The content as text (invalid UTF-8 replaced).
    pub fn text(&self) -> Cow<'_, str> {
        String::from_utf8_lossy(&self.content)
    }

    /// The content, taken.
    pub fn into_content(self) -> Vec<u8> {
        self.content
    }

    /// The MIME type of the content, as libmagic detects it; `None` for
    /// content PHP's `empty()` calls empty (`""` and `"0"`).
    ///
    /// PHP: `getMimeType()` (`finfo::buffer()`).
    pub fn mime_type(&self) -> Option<&'static str> {
        if self.content.is_empty() || self.content == b"0" {
            return None;
        }
        Some(crate::mime::detect(&self.content))
    }

    /// A copy with another role (attachments keep theirs).
    ///
    /// PHP: `withRole($role)`.
    pub fn with_role(&self, role: &str) -> Message {
        Message { role: role.to_owned(), content: self.content.clone(), attachments: self.attachments.clone() }
    }

    /// Attaches a message (an image, for example).
    ///
    /// PHP: `addAttachment($attachment)`.
    pub fn add_attachment(&mut self, attachment: Message) -> &mut Message {
        self.attachments.push(attachment);
        self
    }

    /// Replaces the attachments.
    ///
    /// PHP: `setAttachments($attachments)`.
    pub fn set_attachments(&mut self, attachments: Vec<Message>) -> &mut Message {
        self.attachments = attachments;
        self
    }

    /// The attachments, in order.
    ///
    /// PHP: `getAttachments()`.
    pub fn attachments(&self) -> &[Message] {
        &self.attachments
    }

    /// Whether there are attachments.
    ///
    /// PHP: `hasAttachments()`.
    pub fn has_attachments(&self) -> bool {
        !self.attachments.is_empty()
    }
}
