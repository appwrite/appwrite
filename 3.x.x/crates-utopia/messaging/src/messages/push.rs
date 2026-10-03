//! PHP `Utopia\Messaging\Messages\Push`.

use serde_json::Value;

use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::priority::Priority;

/// PHP `Utopia\Messaging\Messages\Push`.
#[derive(Debug, Clone)]
pub struct Push {
    to: Vec<String>,
    title: Option<String>,
    body: Option<String>,
    data: Option<serde_json::Map<String, Value>>,
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
    origin: Option<String>,
}

impl Push {
    /// PHP `__construct`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        to: Vec<String>,
        title: Option<String>,
        body: Option<String>,
        data: Option<serde_json::Map<String, Value>>,
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
    ) -> Result<Self, MessagingError> {
        if title.is_none() && body.is_none() && data.is_none() {
            return Err(MessagingError::message(
                "At least one of the following parameters must be set: title, body, data",
            ));
        }
        Ok(Self {
            to,
            title,
            body,
            data,
            action,
            sound,
            image,
            icon,
            color,
            tag,
            badge,
            content_available,
            critical,
            priority,
            origin: None,
        })
    }

    /// PHP `getTo`.
    #[must_use]
    pub fn get_to(&self) -> &[String] {
        &self.to
    }

    /// PHP `getFrom` (always `null`).
    #[must_use]
    pub fn get_from(&self) -> Option<&str> {
        None
    }

    /// PHP `getTitle`.
    #[must_use]
    pub fn get_title(&self) -> Option<&str> {
        self.title.as_deref()
    }

    /// PHP `getBody`.
    #[must_use]
    pub fn get_body(&self) -> Option<&str> {
        self.body.as_deref()
    }

    /// PHP `getData`.
    #[must_use]
    pub fn get_data(&self) -> Option<&serde_json::Map<String, Value>> {
        self.data.as_ref()
    }

    /// PHP `getAction`.
    #[must_use]
    pub fn get_action(&self) -> Option<&str> {
        self.action.as_deref()
    }

    /// PHP `getSound`.
    #[must_use]
    pub fn get_sound(&self) -> Option<&str> {
        self.sound.as_deref()
    }

    /// PHP `getImage`.
    #[must_use]
    pub fn get_image(&self) -> Option<&str> {
        self.image.as_deref()
    }

    /// PHP `getIcon`.
    #[must_use]
    pub fn get_icon(&self) -> Option<&str> {
        self.icon.as_deref()
    }

    /// PHP `getColor`.
    #[must_use]
    pub fn get_color(&self) -> Option<&str> {
        self.color.as_deref()
    }

    /// PHP `getTag`.
    #[must_use]
    pub fn get_tag(&self) -> Option<&str> {
        self.tag.as_deref()
    }

    /// PHP `getBadge`.
    #[must_use]
    pub fn get_badge(&self) -> Option<i64> {
        self.badge
    }

    /// PHP `getContentAvailable`.
    #[must_use]
    pub fn get_content_available(&self) -> Option<bool> {
        self.content_available
    }

    /// PHP `getCritical`.
    #[must_use]
    pub fn get_critical(&self) -> Option<bool> {
        self.critical
    }

    /// PHP `getPriority`.
    #[must_use]
    pub fn get_priority(&self) -> Option<Priority> {
        self.priority
    }

    /// Fluent origin setter (PHP `setOrigin` chain).
    #[must_use]
    pub fn with_origin(mut self, origin: Option<String>) -> Self {
        self.origin = origin;
        self
    }
}

impl Message for Push {
    fn set_origin(&mut self, origin: Option<String>) {
        self.origin = origin;
    }

    fn get_origin(&self) -> Option<&str> {
        self.origin.as_deref()
    }

    fn kind(&self) -> MessageKind {
        MessageKind::Push
    }

    fn to_count(&self) -> Option<usize> {
        Some(self.to.len())
    }

    fn as_push(&self) -> Option<&Push> {
        Some(self)
    }
}
