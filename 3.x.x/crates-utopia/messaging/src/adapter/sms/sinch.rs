//! PHP `Utopia\Messaging\Adapter\SMS\Sinch`.

use serde_json::json;

use super::{sms_response_from_status, status_2xx, unknown_error, TYPE};
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::ltrim_plus;
use crate::response::ResponseData;

/// PHP `Adapter\SMS\Sinch`.
#[derive(Debug)]
pub struct Sinch {
    base: AdapterBase,
    service_plan_id: String,
    api_token: String,
    from: Option<String>,
}

impl Sinch {
    /// PHP `__construct($servicePlanId, $apiToken, $from = null)`.
    #[must_use]
    pub fn new(
        service_plan_id: impl Into<String>,
        api_token: impl Into<String>,
        from: Option<String>,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            service_plan_id: service_plan_id.into(),
            api_token: api_token.into(),
            from,
        }
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let to: Vec<&str> = message.get_to().iter().map(|n| ltrim_plus(n)).collect();
        let from = self.from.as_deref().or(message.get_from());
        let result = self.request_default(
            "POST",
            &format!(
                "https://sms.api.sinch.com/xms/v1/{}/batches",
                self.service_plan_id
            ),
            &[
                "Content-Type: application/json".into(),
                format!("Authorization: Bearer {}", self.api_token),
            ],
            Some(json!({
                "from": from,
                "to": to,
                "body": message.get_content(),
            })),
        );
        sms_response_from_status(message, &result, status_2xx, unknown_error)
    }
}

impl Adapter for Sinch {
    fn get_name(&self) -> &'static str {
        "Sinch"
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
