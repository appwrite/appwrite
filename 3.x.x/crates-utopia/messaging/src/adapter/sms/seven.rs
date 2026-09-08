//! PHP `Utopia\Messaging\Adapter\SMS\Seven`.

use serde_json::json;

use super::{sms_response_from_status, status_2xx, unknown_error, TYPE};
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::response::ResponseData;

/// PHP `Adapter\SMS\Seven`.
#[derive(Debug)]
pub struct Seven {
    base: AdapterBase,
    api_key: String,
    from: Option<String>,
}

impl Seven {
    /// PHP `__construct($apiKey, $from = null)`.
    #[must_use]
    pub fn new(api_key: impl Into<String>, from: Option<String>) -> Self {
        Self {
            base: AdapterBase::default(),
            api_key: api_key.into(),
            from,
        }
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let from = self.from.as_deref().or(message.get_from());
        let result = self.request_default(
            "POST",
            "https://gateway.sms77.io/api/sms",
            &[
                "Content-Type: application/json".into(),
                format!("Authorization: Basic {}", self.api_key),
            ],
            Some(json!({
                "from": from,
                "to": message.get_to().join(","),
                "text": message.get_content(),
            })),
        );
        sms_response_from_status(message, &result, status_2xx, unknown_error)
    }
}

impl Adapter for Seven {
    fn get_name(&self) -> &'static str {
        "Seven"
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
