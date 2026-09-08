//! PHP `Utopia\Messaging\Adapter\SMS\Twilio`.

use serde_json::json;

use super::TYPE;
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::basic_auth;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\SMS\Twilio`.
#[derive(Debug)]
pub struct Twilio {
    base: AdapterBase,
    account_sid: String,
    auth_token: String,
    from: Option<String>,
    messaging_service_sid: Option<String>,
}

impl Twilio {
    /// PHP `__construct($accountSid, $authToken, $from = null, $messagingServiceSid = null)`.
    #[must_use]
    pub fn new(
        account_sid: impl Into<String>,
        auth_token: impl Into<String>,
        from: Option<String>,
        messaging_service_sid: Option<String>,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            account_sid: account_sid.into(),
            auth_token: auth_token.into(),
            from,
            messaging_service_sid,
        }
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let from = self.from.as_deref().or(message.get_from()).unwrap_or("");
        let to = message.get_to().first().cloned().unwrap_or_default();
        let result = self.request_default(
            "POST",
            &format!(
                "https://api.twilio.com/2010-04-01/Accounts/{}/Messages.json",
                self.account_sid
            ),
            &[
                "Content-Type: application/x-www-form-urlencoded".into(),
                format!(
                    "Authorization: Basic {}",
                    basic_auth(&self.account_sid, &self.auth_token)
                ),
            ],
            Some(json!({
                "Body": message.get_content(),
                "From": from,
                "MessagingServiceSid": self.messaging_service_sid,
                "To": to,
            })),
        );
        let mut response = Response::new(TYPE);
        if (200..300).contains(&result.status_code) {
            response.set_delivered_to(1);
            response.add_result(to, "");
        } else if let Some(msg) = result.response.get("message").and_then(|v| v.as_str()) {
            response.add_result(to, msg);
        } else {
            response.add_result(to, "Unknown error");
        }
        response.to_array()
    }
}

impl Adapter for Twilio {
    fn get_name(&self) -> &'static str {
        "Twilio"
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
