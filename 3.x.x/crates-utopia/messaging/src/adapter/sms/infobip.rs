//! PHP `Utopia\Messaging\Adapter\SMS\Infobip`.

use serde_json::{json, Value};

use super::{sms_response_from_status, status_2xx, unknown_error, TYPE};
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::ltrim_plus;
use crate::response::ResponseData;

/// PHP `Adapter\SMS\Infobip`.
#[derive(Debug)]
pub struct Infobip {
    base: AdapterBase,
    api_base_url: String,
    api_key: String,
    from: Option<String>,
}

impl Infobip {
    /// PHP `__construct($apiBaseUrl, $apiKey, $from = null)`.
    #[must_use]
    pub fn new(
        api_base_url: impl Into<String>,
        api_key: impl Into<String>,
        from: Option<String>,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            api_base_url: api_base_url.into(),
            api_key: api_key.into(),
            from,
        }
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let destinations: Vec<Value> = message
            .get_to()
            .iter()
            .map(|n| json!({"to": ltrim_plus(n)}))
            .collect();
        let from = self.from.as_deref().or(message.get_from());
        let result = self.request_default(
            "POST",
            &format!("https://{}/sms/2/text/advanced", self.api_base_url),
            &[
                "Content-Type: application/json".into(),
                format!("Authorization: App {}", self.api_key),
            ],
            Some(json!({
                "messages": {
                    "text": message.get_content(),
                    "from": from,
                    "destinations": destinations,
                }
            })),
        );
        sms_response_from_status(message, &result, status_2xx, unknown_error)
    }
}

impl Adapter for Infobip {
    fn get_name(&self) -> &'static str {
        "Infobip"
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
