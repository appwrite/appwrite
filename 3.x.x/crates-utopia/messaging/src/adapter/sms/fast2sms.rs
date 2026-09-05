//! PHP `Utopia\Messaging\Adapter\SMS\Fast2SMS`.

use serde_json::json;

use super::geosms::CallingCode;
use super::TYPE;
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\SMS\Fast2SMS`.
#[derive(Debug)]
pub struct Fast2SMS {
    base: AdapterBase,
    api_key: String,
    sender_id: String,
    message_id: String,
    use_dlt: bool,
}

impl Fast2SMS {
    /// PHP `__construct($apiKey, $senderId = '', $messageId = '', $useDLT = false)`.
    #[must_use]
    pub fn new(
        api_key: impl Into<String>,
        sender_id: impl Into<String>,
        message_id: impl Into<String>,
        use_dlt: bool,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            api_key: api_key.into(),
            sender_id: sender_id.into(),
            message_id: message_id.into(),
            use_dlt,
        }
    }

    fn remove_country_code(number: &str) -> String {
        let digits: String = number.chars().filter(char::is_ascii_digit).collect();
        if let Some(code) = CallingCode::from_phone_number(number) {
            return digits.get(code.len()..).unwrap_or("").to_string();
        }
        digits
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let numbers: Vec<String> = message
            .get_to()
            .iter()
            .map(|n| Self::remove_country_code(n))
            .collect();
        let mut payload = json!({
            "numbers": numbers.join(","),
            "flash": 0,
        });
        if self.use_dlt {
            payload["route"] = json!("dlt");
            payload["sender_id"] = json!(self.sender_id);
            payload["message"] = json!(self.message_id);
            payload["variables_values"] = json!(message.get_content());
        } else {
            payload["route"] = json!("q");
            payload["message"] = json!(message.get_content());
        }
        let result = self.request_default(
            "POST",
            "https://www.fast2sms.com/dev/bulkV2",
            &[
                format!("authorization: {}", self.api_key),
                "Content-Type: application/json".into(),
                "Accept: application/json".into(),
            ],
            Some(payload),
        );
        let mut response = Response::new(TYPE);
        let ret = result
            .response
            .get("return")
            .and_then(serde_json::Value::as_bool);
        if result.status_code == 200 && ret == Some(true) {
            response.set_delivered_to(message.get_to().len() as i64);
            for to in message.get_to() {
                response.add_result(to, "");
            }
        } else {
            // PHP: `$res['message'] ?? 'Unknown error' . ' Status Code: ' . ...`
            // `.` binds tighter than `??`.
            let error = if let Some(msg) = result.response.get("message").and_then(|v| v.as_str()) {
                msg.to_string()
            } else {
                let status = result
                    .response
                    .get("status_code")
                    .map_or_else(|| "Unknown".into(), ToString::to_string);
                format!("Unknown error Status Code: {status}")
            };
            for to in message.get_to() {
                response.add_result(to, error.clone());
            }
        }
        response.to_array()
    }
}

impl Adapter for Fast2SMS {
    fn get_name(&self) -> &'static str {
        "Fast2SMS"
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
