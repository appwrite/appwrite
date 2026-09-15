//! PHP `Utopia\Messaging\Adapter\SMS\Mock`.

use serde_json::json;

use super::{sms_response_from_status, TYPE};
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::response::ResponseData;

/// PHP `Adapter\SMS\Mock`.
#[derive(Debug)]
pub struct Mock {
    base: AdapterBase,
    user: String,
    secret: String,
    url: parking_lot::Mutex<String>,
}

impl Mock {
    /// PHP `__construct($user, $secret)`.
    #[must_use]
    pub fn new(user: impl Into<String>, secret: impl Into<String>) -> Self {
        Self {
            base: AdapterBase::default(),
            user: user.into(),
            secret: secret.into(),
            url: parking_lot::Mutex::new("http://request-catcher:5000/mock-sms".into()),
        }
    }

    /// PHP `getEndpoint`.
    #[must_use]
    pub fn get_endpoint(&self) -> String {
        self.url.lock().clone()
    }

    /// PHP `setEndpoint`.
    pub fn set_endpoint(&self, url: impl Into<String>) -> &Self {
        *self.url.lock() = url.into();
        self
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let url = self.get_endpoint();
        let result = self.request_default(
            "POST",
            &url,
            &[
                "Content-Type: application/json".into(),
                format!("X-Username: {}", self.user),
                format!("X-Key: {}", self.secret),
            ],
            Some(json!({
                "message": message.get_content(),
                "from": message.get_from(),
                "to": message.get_to().join(","),
            })),
        );
        sms_response_from_status(
            message,
            &result,
            |r| r.status_code == 200,
            |_| "Unknown Error.".into(),
        )
    }
}

impl Adapter for Mock {
    fn get_name(&self) -> &'static str {
        "Mock"
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
