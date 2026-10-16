//! PHP `Utopia\Messaging\Adapter\SMS\TextMagic`.

use serde_json::json;

use super::TYPE;
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::ltrim_plus;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\SMS\TextMagic` (`NAME = 'Textmagic'`).
#[derive(Debug)]
pub struct TextMagic {
    base: AdapterBase,
    username: String,
    api_key: String,
    from: Option<String>,
}

impl TextMagic {
    /// PHP `__construct($username, $apiKey, $from = null)`.
    #[must_use]
    pub fn new(
        username: impl Into<String>,
        api_key: impl Into<String>,
        from: Option<String>,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            username: username.into(),
            api_key: api_key.into(),
            from,
        }
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let to: Vec<&str> = message.get_to().iter().map(|n| ltrim_plus(n)).collect();
        let from = ltrim_plus(self.from.as_deref().or(message.get_from()).unwrap_or(""));
        let result = self.request_default(
            "POST",
            "https://rest.textmagic.com/api/v2/messages",
            &[
                "Content-Type: application/x-www-form-urlencoded".into(),
                format!("X-TM-Username: {}", self.username),
                format!("X-TM-Key: {}", self.api_key),
            ],
            Some(json!({
                "text": message.get_content(),
                "from": from,
                "phones": to.join(","),
            })),
        );
        let mut response = Response::new(TYPE);
        if (200..300).contains(&result.status_code) {
            response.set_delivered_to(message.get_to().len() as i64);
            for dest in message.get_to() {
                response.add_result(dest, "");
            }
        } else {
            let msg = result.response.get("message").and_then(|v| v.as_str());
            for dest in message.get_to() {
                response.add_result(dest, msg.unwrap_or("Unknown error"));
            }
        }
        response.to_array()
    }
}

impl Adapter for TextMagic {
    fn get_name(&self) -> &'static str {
        "Textmagic"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::SMS
    }
    fn get_max_messages_per_request(&self) -> usize {
        1000
    }
    fn base(&self) -> &AdapterBase {
        &self.base
    }
    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        Ok(SendResult::Response(self.process_sms(expect_sms(message)?)))
    }
}
