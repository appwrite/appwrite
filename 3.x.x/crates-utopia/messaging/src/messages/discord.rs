//! PHP `Utopia\Messaging\Messages\Discord`.

use serde_json::Value;

use crate::message::{Message, MessageKind};

/// PHP `Utopia\Messaging\Messages\Discord`.
#[derive(Debug, Clone)]
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

impl Discord {
    /// PHP `__construct`.
    #[allow(clippy::too_many_arguments)]
    #[must_use]
    pub fn new(
        content: impl Into<String>,
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
    ) -> Self {
        Self {
            content: content.into(),
            username,
            avatar_url,
            tts,
            embeds,
            allowed_mentions,
            components,
            attachments,
            flags,
            thread_name,
            wait,
            thread_id,
            origin: None,
        }
    }

    /// PHP `getContent`.
    #[must_use]
    pub fn get_content(&self) -> &str {
        &self.content
    }

    /// PHP `getUsername`.
    #[must_use]
    pub fn get_username(&self) -> Option<&str> {
        self.username.as_deref()
    }

    /// PHP `getAvatarUrl`.
    #[must_use]
    pub fn get_avatar_url(&self) -> Option<&str> {
        self.avatar_url.as_deref()
    }

    /// PHP `getTts` / `getTTS` (PHP method names are case-insensitive).
    #[must_use]
    pub fn get_tts(&self) -> Option<bool> {
        self.tts
    }

    /// PHP `getEmbeds`.
    #[must_use]
    pub fn get_embeds(&self) -> Option<&Value> {
        self.embeds.as_ref()
    }

    /// PHP `getAllowedMentions`.
    #[must_use]
    pub fn get_allowed_mentions(&self) -> Option<&Value> {
        self.allowed_mentions.as_ref()
    }

    /// PHP `getComponents`.
    #[must_use]
    pub fn get_components(&self) -> Option<&Value> {
        self.components.as_ref()
    }

    /// PHP `getAttachments`.
    #[must_use]
    pub fn get_attachments(&self) -> Option<&Value> {
        self.attachments.as_ref()
    }

    /// PHP `getFlags`.
    #[must_use]
    pub fn get_flags(&self) -> Option<&str> {
        self.flags.as_deref()
    }

    /// PHP `getThreadName`.
    #[must_use]
    pub fn get_thread_name(&self) -> Option<&str> {
        self.thread_name.as_deref()
    }

    /// PHP `getWait`.
    #[must_use]
    pub fn get_wait(&self) -> Option<bool> {
        self.wait
    }

    /// PHP `getThreadId`.
    #[must_use]
    pub fn get_thread_id(&self) -> Option<&str> {
        self.thread_id.as_deref()
    }

    /// Fluent origin setter (PHP `setOrigin` chain).
    #[must_use]
    pub fn with_origin(mut self, origin: Option<String>) -> Self {
        self.origin = origin;
        self
    }
}

impl Message for Discord {
    fn set_origin(&mut self, origin: Option<String>) {
        self.origin = origin;
    }

    fn get_origin(&self) -> Option<&str> {
        self.origin.as_deref()
    }

    fn kind(&self) -> MessageKind {
        MessageKind::Discord
    }

    fn to_count(&self) -> Option<usize> {
        None
    }

    fn as_discord(&self) -> Option<&Discord> {
        Some(self)
    }
}
