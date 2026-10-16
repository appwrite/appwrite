//! PHP `Utopia\Messaging\Adapter\SMS\Telesign`.

use serde_json::json;

use super::TYPE;
use crate::adapter::{expect_sms, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::SMS;
use crate::php::basic_auth;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\SMS\Telesign`.
#[derive(Debug)]
pub struct Telesign {
    base: AdapterBase,
    customer_id: String,
    api_key: String,
}

impl Telesign {
    /// PHP `__construct($customerId, $apiKey)`.
    #[must_use]
    pub fn new(customer_id: impl Into<String>, api_key: impl Into<String>) -> Self {
        Self {
            base: AdapterBase::default(),
            customer_id: customer_id.into(),
            api_key: api_key.into(),
        }
    }

    fn format_numbers(numbers: &[String]) -> String {
        numbers
            .iter()
            .map(|n| format!("{n}:{}", uniqid()))
            .collect::<Vec<_>>()
            .join(",")
    }

    fn process_sms(&self, message: &SMS) -> ResponseData {
        let to = Self::format_numbers(message.get_to());
        let result = self.request_default(
            "POST",
            "https://rest-ww.telesign.com/v1/verify/bulk_sms",
            &[
                "Content-Type: application/x-www-form-urlencoded".into(),
                format!(
                    "Authorization: Basic {}",
                    basic_auth(&self.customer_id, &self.api_key)
                ),
            ],
            Some(json!({
                "template": message.get_content(),
                "recipients": to,
            })),
        );
        let mut response = Response::new(TYPE);
        if result.status_code == 200 {
            response.set_delivered_to(message.get_to().len() as i64);
            for dest in message.get_to() {
                response.add_result(dest, "");
            }
        } else {
            let desc = result
                .response
                .get("errors")
                .and_then(|e| e.as_array())
                .and_then(|e| e.first())
                .and_then(|e| e.get("description"))
                .and_then(|v| v.as_str());
            for dest in message.get_to() {
                response.add_result(dest, desc.unwrap_or("Unknown error"));
            }
        }
        response.to_array()
    }
}

fn uniqid() -> String {
    use rand::Rng;
    let mut rng = rand::thread_rng();
    format!("{:x}{:x}", rng.gen::<u32>(), rng.gen::<u16>())
}

impl Adapter for Telesign {
    fn get_name(&self) -> &'static str {
        "Telesign"
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
