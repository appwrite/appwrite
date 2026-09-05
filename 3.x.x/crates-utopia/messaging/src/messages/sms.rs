//! PHP `Utopia\Messaging\Messages\SMS`.

use serde_json::Value;

use crate::message::{Message, MessageKind};

/// PHP `Utopia\Messaging\Messages\SMS`.
#[derive(Debug, Clone)]
pub struct SMS {
    to: Vec<String>,
    content: String,
    from: Option<String>,
    attachments: Option<Vec<String>>,
    metadata: Option<serde_json::Map<String, Value>>,
    origin: Option<String>,
}

impl SMS {
    /// PHP `__construct($to, $content, $from = null, $attachments = null, $metadata = null)`.
    #[must_use]
    pub fn new(
        to: Vec<String>,
        content: impl Into<String>,
        from: Option<String>,
        attachments: Option<Vec<String>>,
        metadata: Option<serde_json::Map<String, Value>>,
    ) -> Self {
        Self {
            to,
            content: content.into(),
            from,
            attachments,
            metadata,
            origin: None,
        }
    }

    /// PHP `getTo`.
    #[must_use]
    pub fn get_to(&self) -> &[String] {
        &self.to
    }

    /// PHP `getContent`.
    #[must_use]
    pub fn get_content(&self) -> &str {
        &self.content
    }

    /// PHP `getFrom`.
    #[must_use]
    pub fn get_from(&self) -> Option<&str> {
        self.from.as_deref()
    }

    /// PHP `getAttachments`.
    #[must_use]
    pub fn get_attachments(&self) -> Option<&[String]> {
        self.attachments.as_deref()
    }

    /// PHP `getMetadata`.
    #[must_use]
    pub fn get_metadata(&self) -> Option<&serde_json::Map<String, Value>> {
        self.metadata.as_ref()
    }

    /// PHP `setMetadata`.
    pub fn set_metadata(&mut self, metadata: Option<serde_json::Map<String, Value>>) -> &mut Self {
        self.metadata = metadata;
        self
    }

    /// Fluent origin setter (PHP `setOrigin` chain).
    #[must_use]
    pub fn with_origin(mut self, origin: Option<String>) -> Self {
        self.origin = origin;
        self
    }
}

impl Message for SMS {
    fn set_origin(&mut self, origin: Option<String>) {
        self.origin = origin;
    }

    fn get_origin(&self) -> Option<&str> {
        self.origin.as_deref()
    }

    fn kind(&self) -> MessageKind {
        MessageKind::SMS
    }

    fn to_count(&self) -> Option<usize> {
        Some(self.to.len())
    }

    fn as_sms(&self) -> Option<&SMS> {
        Some(self)
    }
}
