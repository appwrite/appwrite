//! Chat adapters: [`DiscordWebhook`].
//!
//! PHP: `Utopia\Messaging\Adapter\Chat`.

use php_std::Value;
use php_std::encoding::{QueryEncoding, http_build_query};
use php_std::filter::{self, FILTER_VALIDATE_URL, Options};

use crate::adapter::{Adapter, Base, configure};
use crate::error::Error;
use crate::http::{BoxFuture, Call, Http};
use crate::message::{Discord, Message, MessageType};
use crate::response::{Response, Sent};
use crate::values::{map, text};

/// The kind of chat adapters.
///
/// PHP: `Chat\Discord::TYPE`.
pub const TYPE: &str = "chat";

/// A Discord channel webhook (`https://discord.com/api/webhooks/<id>/<token>`).
///
/// PHP: `Utopia\Messaging\Adapter\Chat\Discord`.
#[derive(Debug)]
pub struct DiscordWebhook {
    url: String,
    webhook_id: String,
    base: Base,
}

impl DiscordWebhook {
    /// Posts to `url`, which must be an HTTPS `discord.com` webhook URL.
    ///
    /// Fails with [`Error::InvalidArgument`] for a malformed URL, another
    /// scheme or host, or one without a webhook ID.
    ///
    /// PHP: `new Discord($webhookURL)`.
    pub fn new(url: impl Into<String>) -> Result<DiscordWebhook, Error> {
        let url = url.into();
        let invalid = |m: &str| Err(Error::InvalidArgument(m.to_owned()));
        let valid =
            filter::filter_var(&filter::Value::Str(url.as_bytes().to_vec()), FILTER_VALIDATE_URL, &Options::Flags(0))
                .map(|f| !matches!(f.value, filter::Value::Bool(false)))
                .unwrap_or(false);
        if !valid {
            return invalid("Invalid Discord webhook URL format.");
        }
        let parts = php_std::url::parse_url(url.as_bytes());
        let scheme = parts.as_ref().and_then(|p| p.scheme()).map(|v| v.into_owned());
        let host = parts.as_ref().and_then(|p| p.host()).map(|v| v.into_owned());
        let path = parts.as_ref().and_then(|p| p.path()).map(|v| v.into_owned()).unwrap_or_default();
        if scheme.as_deref() != Some(b"https".as_slice()) {
            return invalid("Discord webhook URL must use HTTPS scheme.");
        }
        if host.as_deref() != Some(b"discord.com".as_slice()) {
            return invalid("Discord webhook URL must use discord.com as host.");
        }
        let path = String::from_utf8_lossy(&path).into_owned();
        let mut webhook_id = String::new();
        let pieces: Vec<&str> = path.split("/webhooks/").collect();
        if pieces.len() >= 2 {
            webhook_id = pieces[1].split('/').next().unwrap_or_default().to_owned();
        }
        if webhook_id.is_empty() || webhook_id == "0" {
            return invalid("Discord webhook ID cannot be empty.");
        }
        Ok(DiscordWebhook { url, webhook_id, base: Base::new(Http::new("Discord")) })
    }

    async fn send_discord(&self, message: &Discord) -> Result<Sent, Error> {
        let mut query = serde_json::Map::new();
        if let Some(wait) = message.wait() {
            query.insert("wait".into(), serde_json::Value::Bool(wait));
        }
        if let Some(thread) = message.thread_id() {
            query.insert("thread_id".into(), serde_json::Value::from(thread));
        }
        let query = if query.is_empty() {
            String::new()
        } else {
            let encoded = http_build_query(&serde_json::Value::Object(query), b"", None, QueryEncoding::Rfc1738);
            format!("?{}", String::from_utf8_lossy(&encoded))
        };
        let opt = |v: Option<&str>| v.map_or(Value::Null, text);
        let body = map([
            ("content", text(message.content())),
            ("username", opt(message.username())),
            ("avatar_url", opt(message.avatar_url())),
            ("tts", message.tts().map_or(Value::Null, Value::Bool)),
            ("embeds", message.embeds().cloned().unwrap_or(Value::Null)),
            ("allowed_mentions", message.allowed_mentions().cloned().unwrap_or(Value::Null)),
            ("components", message.components().cloned().unwrap_or(Value::Null)),
            ("attachments", message.attachments().cloned().unwrap_or(Value::Null)),
            ("flags", opt(message.flags())),
            ("thread_name", opt(message.thread_name())),
        ]);
        let result = self
            .base
            .request(Call::new(
                "POST",
                format!("{}{query}", self.url),
                vec!["Content-Type: application/json".into()],
                Some(body),
            ))
            .await?;
        let mut response = Response::new(TYPE);
        if result.is_success() {
            response.set_delivered_to(1);
            response.add_success(&self.webhook_id);
        } else if (400..500).contains(&result.status_code) {
            response.add_failure(&self.webhook_id, "Bad Request.");
        } else {
            response.add_failure(&self.webhook_id, "Unknown Error.");
        }
        Ok(Sent::Response(response))
    }
}

configure!(DiscordWebhook, |_| Http::new("Discord"));

impl Adapter for DiscordWebhook {
    fn name(&self) -> &str {
        "Discord"
    }

    fn kind(&self) -> &str {
        TYPE
    }

    fn message_type(&self) -> MessageType {
        MessageType::Discord
    }

    fn max_messages_per_request(&self) -> i64 {
        1
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(async move {
            match message {
                Message::Discord(discord) => self.send_discord(discord).await,
                _ => unreachable!("send() checks the message type"),
            }
        })
    }
}
