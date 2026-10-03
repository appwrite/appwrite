//! PHP `Utopia\Messaging\Adapter\Chat\Discord`.

use serde_json::json;
use url::Url;

use crate::adapter::{expect_discord, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::Discord as DiscordMessage;
use crate::php::php_empty_str;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\Chat\Discord`.
#[derive(Debug)]
pub struct Discord {
    base: AdapterBase,
    webhook_url: String,
    webhook_id: String,
}

impl Discord {
    /// PHP `__construct($webhookURL)`.
    pub fn new(webhook_url: impl Into<String>) -> Result<Self, MessagingError> {
        let webhook_url = webhook_url.into();
        let parsed = Url::parse(&webhook_url)
            .map_err(|_| MessagingError::invalid_argument("Invalid Discord webhook URL format."))?;
        if parsed.scheme() != "https" {
            return Err(MessagingError::invalid_argument(
                "Discord webhook URL must use HTTPS scheme.",
            ));
        }
        if parsed.host_str() != Some("discord.com") {
            return Err(MessagingError::invalid_argument(
                "Discord webhook URL must use discord.com as host.",
            ));
        }
        let path = parsed.path();
        let webhook_id = path
            .split("/webhooks/")
            .nth(1)
            .and_then(|rest| rest.split('/').next())
            .unwrap_or("")
            .to_string();
        if php_empty_str(&webhook_id) {
            return Err(MessagingError::invalid_argument(
                "Discord webhook ID cannot be empty.",
            ));
        }
        Ok(Self {
            base: AdapterBase::default(),
            webhook_url,
            webhook_id,
        })
    }

    fn process_discord(&self, message: &DiscordMessage) -> ResponseData {
        let mut query = Vec::new();
        if let Some(wait) = message.get_wait() {
            query.push(("wait", if wait { "1".into() } else { "0".into() }));
        }
        if let Some(thread_id) = message.get_thread_id() {
            query.push(("thread_id", thread_id.to_string()));
        }
        let qs = if query.is_empty() {
            String::new()
        } else {
            let mut ser = url::form_urlencoded::Serializer::new(String::new());
            for (k, v) in &query {
                ser.append_pair(k, v);
            }
            format!("?{}", ser.finish())
        };

        let result = self.request_default(
            "POST",
            &format!("{}{qs}", self.webhook_url),
            &["Content-Type: application/json".into()],
            Some(json!({
                "content": message.get_content(),
                "username": message.get_username(),
                "avatar_url": message.get_avatar_url(),
                "tts": message.get_tts(),
                "embeds": message.get_embeds(),
                "allowed_mentions": message.get_allowed_mentions(),
                "components": message.get_components(),
                "attachments": message.get_attachments(),
                "flags": message.get_flags(),
                "thread_name": message.get_thread_name(),
            })),
        );

        let mut response = Response::new("chat");
        if (200..300).contains(&result.status_code) {
            response.set_delivered_to(1);
            response.add_result(&self.webhook_id, "");
        } else if (400..500).contains(&result.status_code) {
            response.add_result(&self.webhook_id, "Bad Request.");
        } else {
            response.add_result(&self.webhook_id, "Unknown Error.");
        }
        response.to_array()
    }
}

impl Adapter for Discord {
    fn get_name(&self) -> &'static str {
        "Discord"
    }
    fn get_type(&self) -> &'static str {
        "chat"
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::Discord
    }
    fn get_max_messages_per_request(&self) -> usize {
        1
    }
    fn base(&self) -> &AdapterBase {
        &self.base
    }
    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        Ok(SendResult::Response(
            self.process_discord(expect_discord(message)?),
        ))
    }
}
