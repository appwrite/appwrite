//! PHP `Utopia\Messaging\Adapter\Email\Mailgun`.

use serde_json::{json, Map, Value};

use super::{MAX_ATTACHMENT_BYTES, TYPE};
use crate::adapter::{expect_email, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::Email;
use crate::php::{basic_auth, php_empty};
use crate::response::{Response, ResponseData};

/// PHP `Adapter\Email\Mailgun`.
#[derive(Debug)]
pub struct Mailgun {
    base: AdapterBase,
    api_key: String,
    domain: String,
    is_eu: bool,
}

impl Mailgun {
    /// PHP `__construct($apiKey, $domain, $isEU = false)`.
    #[must_use]
    pub fn new(api_key: impl Into<String>, domain: impl Into<String>, is_eu: bool) -> Self {
        Self {
            base: AdapterBase::default(),
            api_key: api_key.into(),
            domain: domain.into(),
            is_eu,
        }
    }

    fn process_email(&self, message: &Email) -> Result<ResponseData, MessagingError> {
        let host = if self.is_eu {
            "api.eu.mailgun.net"
        } else {
            "api.mailgun.net"
        };
        let recipients = message.get_to();
        let to_field = recipients
            .iter()
            .map(|to| {
                if php_empty(to.name.as_deref()) {
                    to.email.clone()
                } else {
                    format!("{} <{}>", to.name.as_deref().unwrap_or(""), to.email)
                }
            })
            .collect::<Vec<_>>()
            .join(",");

        let mut body = Map::new();
        body.insert("to".into(), json!(to_field));
        body.insert(
            "from".into(),
            json!(format!(
                "{} <{}>",
                message.get_from_name(),
                message.get_from_email()
            )),
        );
        body.insert("subject".into(), json!(message.get_subject()));
        if message.is_html() {
            body.insert("text".into(), Value::Null);
            body.insert("html".into(), json!(message.get_content()));
        } else {
            body.insert("text".into(), json!(message.get_content()));
            body.insert("html".into(), Value::Null);
        }
        // PHP uses the *key* `h:Reply-To: {name} <{email}>`.
        body.insert(
            format!(
                "h:Reply-To: {} <{}>",
                message.get_reply_to_name(),
                message.get_reply_to_email()
            ),
            json!(null),
        );

        if recipients.len() > 1 {
            let mut vars = Map::new();
            for to in recipients {
                vars.insert(to.email.clone(), json!({}));
            }
            body.insert("recipient-variables".into(), json!(vars));
        }

        if let Some(cc) = message.get_cc() {
            let mut cc_acc = String::new();
            for c in cc {
                if php_empty(Some(c.email.as_str())) {
                    continue;
                }
                let piece = if php_empty(c.name.as_deref()) {
                    c.email.clone()
                } else {
                    format!("{} <{}>", c.name.as_deref().unwrap_or(""), c.email)
                };
                cc_acc = if cc_acc.is_empty() {
                    piece
                } else {
                    format!("{cc_acc},{piece}")
                };
            }
            if !cc_acc.is_empty() {
                body.insert("cc".into(), json!(cc_acc));
            }
        }
        if let Some(bcc) = message.get_bcc() {
            let mut bcc_acc = String::new();
            for c in bcc {
                if php_empty(Some(c.email.as_str())) {
                    continue;
                }
                let piece = if php_empty(c.name.as_deref()) {
                    c.email.clone()
                } else {
                    format!("{} <{}>", c.name.as_deref().unwrap_or(""), c.email)
                };
                bcc_acc = if bcc_acc.is_empty() {
                    piece
                } else {
                    format!("{bcc_acc},{piece}")
                };
            }
            if !bcc_acc.is_empty() {
                body.insert("bcc".into(), json!(bcc_acc));
            }
        }

        let mut is_multipart = false;
        if let Some(attachments) = message.get_attachments() {
            let mut size = 0u64;
            for attachment in attachments {
                size += std::fs::metadata(attachment.get_path())
                    .map(|m| m.len())
                    .unwrap_or(0);
            }
            if size > MAX_ATTACHMENT_BYTES {
                return Err(MessagingError::message(
                    "Attachments size exceeds the maximum allowed size of ",
                ));
            }
            for (index, attachment) in attachments.iter().enumerate() {
                is_multipart = true;
                let data = if let Some(content) = attachment.get_content() {
                    content.to_vec()
                } else {
                    std::fs::read(attachment.get_path()).unwrap_or_default()
                };
                use base64::engine::general_purpose::STANDARD;
                use base64::Engine;
                body.insert(format!("attachment[{index}]"), json!(STANDARD.encode(data)));
            }
        }

        let mut headers = vec![format!(
            "Authorization: Basic {}",
            basic_auth("api", &self.api_key)
        )];
        headers.push(if is_multipart {
            "Content-Type: multipart/form-data".into()
        } else {
            "Content-Type: application/x-www-form-urlencoded".into()
        });

        let result = self.request_default(
            "POST",
            &format!("https://{host}/v3/{}/messages", self.domain),
            &headers,
            Some(Value::Object(body)),
        );

        let mut response = Response::new(TYPE);
        if (200..300).contains(&result.status_code) {
            response.set_delivered_to(message.get_to().len() as i64);
            for to in message.get_to() {
                response.add_result(&to.email, "");
            }
        } else if (400..500).contains(&result.status_code) {
            let error = if let Some(s) = result.response.as_str() {
                s.to_string()
            } else if let Some(m) = result.response.get("message").and_then(|v| v.as_str()) {
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

impl Adapter for Mailgun {
    fn get_name(&self) -> &'static str {
        "Mailgun"
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
