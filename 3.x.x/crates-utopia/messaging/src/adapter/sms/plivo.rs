//! PHP `Utopia\Messaging\Adapter\SMS\Plivo`.

use serde_json::json;

use super::{sms_response_from_status, status_2xx, unknown_error, TYPE};
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::basic_auth;
use crate::response::ResponseData;

/// PHP `Adapter\SMS\Plivo`.
#[derive(Debug)]
pub struct Plivo {
    base: AdapterBase,
    auth_id: String,
    auth_token: String,
    from: Option<String>,
}

impl Plivo {
    /// PHP `__construct($authId, $authToken, $from = null)`.
    #[must_use]
    pub fn new(
        auth_id: impl Into<String>,
        auth_token: impl Into<String>,
        from: Option<String>,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            auth_id: auth_id.into(),
            auth_token: auth_token.into(),
            from,
        }
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let src = self
            .from
            .clone()
            .or_else(|| message.get_from().map(str::to_owned))
            .unwrap_or_else(|| "Plivo".into());
        let result = self.request_default(
            "POST",
            &format!("https://api.plivo.com/v1/Account/{}/Message/", self.auth_id),
            &[
                "Content-Type: application/x-www-form-urlencoded".into(),
                format!(
                    "Authorization: Basic {}",
                    basic_auth(&self.auth_id, &self.auth_token)
                ),
            ],
            Some(json!({
                "text": message.get_content(),
                "src": src,
                "dst": message.get_to().join("<"),
            })),
        );
        sms_response_from_status(message, &result, status_2xx, unknown_error)
    }
}

impl Adapter for Plivo {
    fn get_name(&self) -> &'static str {
        "Plivo"
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
