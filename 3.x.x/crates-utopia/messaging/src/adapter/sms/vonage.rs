//! PHP `Utopia\Messaging\Adapter\SMS\Vonage`.

use serde_json::json;

use super::TYPE;
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::ltrim_plus;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\SMS\Vonage`.
#[derive(Debug)]
pub struct Vonage {
    base: AdapterBase,
    api_key: String,
    api_secret: String,
    from: Option<String>,
}

impl Vonage {
    /// PHP `__construct($apiKey, $apiSecret, $from = null)`.
    #[must_use]
    pub fn new(
        api_key: impl Into<String>,
        api_secret: impl Into<String>,
        from: Option<String>,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            api_key: api_key.into(),
            api_secret: api_secret.into(),
            from,
        }
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let to: Vec<&str> = message.get_to().iter().map(|n| ltrim_plus(n)).collect();
        let from = self.from.as_deref().or(message.get_from());
        let result = self.request_default(
            "POST",
            "https://rest.nexmo.com/sms/json",
            &["Content-Type: application/x-www-form-urlencoded".into()],
            Some(json!({
                "text": message.get_content(),
                "from": from,
                "to": to.first().copied().unwrap_or(""),
                "api_key": self.api_key,
                "api_secret": self.api_secret,
            })),
        );
        let mut response = Response::new(TYPE);
        let status = result
            .response
            .get("messages")
            .and_then(|m| m.as_array())
            .and_then(|m| m.first())
            .and_then(|m| m.get("status"));
        // PHP `=== 0` (integer). JSON numbers deserialize as i64/u64.
        let is_zero = status.and_then(serde_json::Value::as_i64) == Some(0)
            || status.and_then(serde_json::Value::as_u64) == Some(0);
        if is_zero {
            response.set_delivered_to(1);
            let dest = result
                .response
                .get("messages")
                .and_then(|m| m.as_array())
                .and_then(|m| m.first())
                .and_then(|m| m.get("to"))
                .and_then(|v| v.as_str())
                .unwrap_or("");
            response.add_result(dest, "");
        } else if let Some(err) = result
            .response
            .get("messages")
            .and_then(|m| m.as_array())
            .and_then(|m| m.first())
            .and_then(|m| m.get("error-text"))
            .and_then(|v| v.as_str())
        {
            response.add_result(message.get_to().first().cloned().unwrap_or_default(), err);
        } else {
            response.add_result(
                message.get_to().first().cloned().unwrap_or_default(),
                "Unknown error",
            );
        }
        response.to_array()
    }
}

impl Adapter for Vonage {
    fn get_name(&self) -> &'static str {
        "Vonage"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::SMS
    }
    fn get_max_messages_per_request(&self) -> usize {
        1
    }
    fn base(&self) -> &AdapterBase {
        &self.base
    }
    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        Ok(SendResult::Response(self.process_sms(expect_sms(message)?)))
    }
}
