//! PHP `Utopia\Messaging\Adapter\Email\Sendgrid`.

use serde_json::{json, Value};

use super::{MAX_ATTACHMENT_BYTES, TYPE};
use crate::adapter::{expect_email, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::Email;
use crate::php::php_empty;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\Email\Sendgrid`.
#[derive(Debug)]
pub struct Sendgrid {
    base: AdapterBase,
    api_key: String,
}

impl Sendgrid {
    /// PHP `__construct($apiKey)`.
    #[must_use]
    pub fn new(api_key: impl Into<String>) -> Self {
        Self {
            base: AdapterBase::default(),
            api_key: api_key.into(),
        }
    }

    fn process_email(&self, message: &Email) -> Result<ResponseData, MessagingError> {
        let mut personalizations: Vec<Value> = message
            .get_to()
            .iter()
            .map(|to| {
                let dest = if php_empty(to.name.as_deref()) {
                    json!({"email": to.email})
                } else {
                    json!({"email": to.email, "name": to.name})
                };
                json!({
                    "to": [dest],
                    "subject": message.get_subject(),
                })
            })
            .collect();

        if let Some(cc) = message.get_cc() {
            if !cc.is_empty() {
                for personalization in &mut personalizations {
                    let mut list = Vec::new();
                    for c in cc {
                        let mut entry = serde_json::Map::new();
                        entry.insert("email".into(), json!(c.email));
                        if !php_empty(c.name.as_deref()) {
                            entry.insert("name".into(), json!(c.name));
                        }
                        list.push(Value::Object(entry));
                    }
                    personalization["cc"] = Value::Array(list);
                }
            }
        }
        if let Some(bcc) = message.get_bcc() {
            if !bcc.is_empty() {
                for personalization in &mut personalizations {
                    let mut list = Vec::new();
                    for c in bcc {
                        let mut entry = serde_json::Map::new();
                        entry.insert("email".into(), json!(c.email));
                        if !php_empty(c.name.as_deref()) {
                            entry.insert("name".into(), json!(c.name));
                        }
                        list.push(Value::Object(entry));
                    }
                    personalization["bcc"] = Value::Array(list);
                }
            }
        }

        let mut attachments = Vec::new();
        if let Some(list) = message.get_attachments() {
            let mut size = 0u64;
            for attachment in list {
                size += std::fs::metadata(attachment.get_path())
                    .map(|m| m.len())
                    .unwrap_or(0);
            }
            if size > MAX_ATTACHMENT_BYTES {
                return Err(MessagingError::message(
                    "Attachments size exceeds the maximum allowed size of 25MB",
                ));
            }
            use base64::engine::general_purpose::STANDARD;
            use base64::Engine;
            for attachment in list {
                let data = std::fs::read(attachment.get_path()).unwrap_or_default();
                attachments.push(json!({
                    "content": STANDARD.encode(data),
                    "filename": attachment.get_name(),
                    "type": attachment.get_type(),
                    "disposition": "attachment",
                }));
            }
        }

        let mut body = json!({
            "personalizations": personalizations,
            "reply_to": {
                "name": message.get_reply_to_name(),
                "email": message.get_reply_to_email(),
            },
            "from": {
                "name": message.get_from_name(),
                "email": message.get_from_email(),
            },
            "content": [{
                "type": if message.is_html() { "text/html" } else { "text/plain" },
                "value": message.get_content(),
            }],
        });
        if !attachments.is_empty() {
            body["attachments"] = Value::Array(attachments);
        }

        let result = self.request_default(
            "POST",
            "https://api.sendgrid.com/v3/mail/send",
            &[
                format!("Authorization: Bearer {}", self.api_key),
                "Content-Type: application/json".into(),
            ],
            Some(body),
        );

        let mut response = Response::new(TYPE);
        if result.status_code == 202 {
            response.set_delivered_to(message.get_to().len() as i64);
            for to in message.get_to() {
                response.add_result(&to.email, "");
            }
        } else {
            let error = if let Some(s) = result.response.as_str() {
                s.to_string()
            } else if let Some(m) = result
                .response
                .get("errors")
                .and_then(|e| e.as_array())
                .and_then(|e| e.first())
                .and_then(|e| e.get("message"))
                .and_then(|v| v.as_str())
            {
                m.to_string()
            } else {
                "Unknown error".into()
            };
            for to in message.get_to() {
                response.add_result(&to.email, error.clone());
            }
        }
        Ok(response.to_array())
    }
}

impl Adapter for Sendgrid {
    fn get_name(&self) -> &'static str {
        "Sendgrid"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::Email
    }
    fn get_max_messages_per_request(&self) -> usize {
        1000
    }
    fn base(&self) -> &AdapterBase {
        &self.base
    }
    fn process(&self, message: &dyn Message) -> Result<SendResult, MessagingError> {
        Ok(SendResult::Response(
            self.process_email(expect_email(message)?)?,
        ))
    }
}
