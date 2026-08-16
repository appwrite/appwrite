//! PHP `Utopia\Messaging\Adapter\SMS\Inforu`.

use serde_json::{json, Value};

use super::TYPE;
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::ltrim_plus;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\SMS\Inforu`.
#[derive(Debug)]
pub struct Inforu {
    base: AdapterBase,
    sender_id: String,
    api_token: String,
}

impl Inforu {
    /// PHP `__construct($senderId, $apiToken)`.
    #[must_use]
    pub fn new(sender_id: impl Into<String>, api_token: impl Into<String>) -> Self {
        Self {
            base: AdapterBase::default(),
            sender_id: sender_id.into(),
            api_token: api_token.into(),
        }
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let recipients: Vec<Value> = message
            .get_to()
            .iter()
            .map(|n| json!({"Phone": ltrim_plus(n)}))
            .collect();
        let result = self.request_default(
            "POST",
            "https://capi.inforu.co.il/api/v2/SMS/SendSms",
            &[
                "Content-Type: application/json".into(),
                format!("Authorization: Basic {}", self.api_token),
            ],
            Some(json!({
                "Data": {
                    "Message": message.get_content(),
                    "Recipients": recipients,
                    "Settings": { "Sender": self.sender_id },
                }
            })),
        );
        let mut response = Response::new(TYPE);
        let status_id = result.response.get("StatusId").and_then(Value::as_i64);
        if result.status_code == 200 && status_id == Some(1) {
            response.set_delivered_to(message.get_to().len() as i64);
            for to in message.get_to() {
                response.add_result(to, "");
            }
        } else {
            let error = result
                .response
                .get("StatusDescription")
                .and_then(Value::as_str)
                .unwrap_or("Unknown error");
            for to in message.get_to() {
                response.add_result(to, error);
            }
        }
        response.to_array()
    }
}

impl Adapter for Inforu {
    fn get_name(&self) -> &'static str {
        "Inforu"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::SMS
    }
    fn get_max_messages_per_request(&self) -> usize {
        100
    }
    fn base(&self) -> &AdapterBase {
        &self.base
    }
    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        Ok(SendResult::Response(self.process_sms(expect_sms(message)?)))
    }
}
