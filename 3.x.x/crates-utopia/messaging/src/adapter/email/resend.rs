//! PHP `Utopia\Messaging\Adapter\Email\Resend`.

use base64::engine::general_purpose::STANDARD;
use base64::Engine;
use serde_json::{json, Value};

use super::TYPE;
use crate::adapter::{expect_email, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::message::{Message, MessageKind};
use crate::messages::Email;
use crate::php::{format_named_email, php_empty, php_empty_str};
use crate::response::{Response, ResponseData};

const MAX_ATTACHMENT_BYTES: u64 = 40 * 1024 * 1024;

/// PHP `Adapter\Email\Resend`.
#[derive(Debug)]
pub struct Resend {
    base: AdapterBase,
    api_key: String,
}

impl Resend {
    /// PHP `__construct($apiKey)`.
    #[must_use]
    pub fn new(api_key: impl Into<String>) -> Self {
        Self {
            base: AdapterBase::default(),
            api_key: api_key.into(),
        }
    }

    fn process_email(&self, message: &Email) -> Result<ResponseData, MessagingError> {
        let mut attachments = Vec::new();
        if let Some(list) = message.get_attachments() {
            if !list.is_empty() {
                let mut size = 0u64;
                for attachment in list {
                    if let Some(content) = attachment.get_content() {
                        size += content.len() as u64;
                    } else {
                        let file_size = std::fs::metadata(attachment.get_path()).map_err(|_| {
                            MessagingError::message(format!(
                                "Failed to read attachment file: {}",
                                attachment.get_path()
                            ))
                        })?;
                        size += file_size.len();
                    }
                }
                if size > MAX_ATTACHMENT_BYTES {
                    return Err(MessagingError::message(format!(
                        "Total attachment size exceeds {MAX_ATTACHMENT_BYTES} bytes"
                    )));
                }
                for attachment in list {
                    let data = if let Some(content) = attachment.get_content() {
                        content.to_vec()
                    } else {
                        std::fs::read(attachment.get_path()).map_err(|_| {
                            MessagingError::message(format!(
                                "Failed to read attachment file: {}",
                                attachment.get_path()
                            ))
                        })?
                    };
                    attachments.push(json!({
                        "filename": attachment.get_name(),
                        "content": STANDARD.encode(data),
                        "content_type": attachment.get_type(),
                    }));
                }
            }
        }

        let mut emails = Vec::new();
        for to in message.get_to() {
            let to_formatted = format_named_email(&to.email, to.name.as_deref());
            let mut email = json!({
                "from": if php_empty_str(message.get_from_name()) {
                    message.get_from_email().to_string()
                } else {
                    format!("{} <{}>", message.get_from_name(), message.get_from_email())
                },
                "to": [to_formatted],
                "subject": message.get_subject(),
            });
            if message.is_html() {
                email["html"] = json!(message.get_content());
            } else {
                email["text"] = json!(message.get_content());
            }
            if !php_empty(Some(message.get_reply_to_email())) {
                let reply = if php_empty_str(message.get_reply_to_name()) {
                    message.get_reply_to_email().to_string()
                } else {
                    format!(
                        "{} <{}>",
                        message.get_reply_to_name(),
                        message.get_reply_to_email()
                    )
                };
                email["reply_to"] = json!([reply]);
            }
            if let Some(cc) = message.get_cc() {
                if !cc.is_empty() {
                    let list: Vec<String> = cc
                        .iter()
                        .map(|c| format_named_email(&c.email, c.name.as_deref()))
                        .collect();
                    email["cc"] = json!(list);
                }
            }
            if !attachments.is_empty() {
                email["attachments"] = Value::Array(attachments.clone());
            }
            if let Some(bcc) = message.get_bcc() {
                if !bcc.is_empty() {
                    let list: Vec<String> = bcc
                        .iter()
                        .map(|c| format_named_email(&c.email, c.name.as_deref()))
                        .collect();
                    email["bcc"] = json!(list);
                }
            }
            emails.push(email);
        }

        let headers = vec![
            format!("Authorization: Bearer {}", self.api_key),
            "Content-Type: application/json".into(),
        ];
        let mut response = Response::new(TYPE);
        if attachments.is_empty() {
            self.send_batch(message, &emails, &headers, &mut response);
        } else {
            self.send_individually(message, &emails, &headers, &mut response);
        }
        Ok(response.to_array())
    }

    fn send_batch(
        &self,
        message: &Email,
        emails: &[Value],
        headers: &[String],
        response: &mut Response,
    ) {
        let result = self.request_default(
            "POST",
            "https://api.resend.com/emails/batch",
            headers,
            Some(Value::Array(emails.to_vec())),
        );
        let status = result.status_code;
        if status == 200 {
            if let Some(errors) = result.response.get("errors").and_then(|v| v.as_array()) {
                if !errors.is_empty() {
                    let mut failed = std::collections::HashMap::new();
                    for error in errors {
                        if let (Some(index), Some(msg)) = (
                            error.get("index").and_then(Value::as_u64),
                            error.get("message").and_then(Value::as_str),
                        ) {
                            failed.insert(index as usize, msg.to_string());
                        }
                    }
                    for (index, to) in message.get_to().iter().enumerate() {
                        if let Some(msg) = failed.get(&index) {
                            response.add_result(&to.email, msg.clone());
                        } else {
                            response.add_result(&to.email, "");
                        }
                    }
                    response.set_delivered_to((message.get_to().len() - failed.len()) as i64);
                    return;
                }
            }
            response.set_delivered_to(message.get_to().len() as i64);
            for to in message.get_to() {
                response.add_result(&to.email, "");
            }
        } else if (400..500).contains(&status) {
            let error = extract_error(&result.response, "Unknown error");
            for to in message.get_to() {
                response.add_result(&to.email, error.clone());
            }
        } else if status >= 500 {
            let error = extract_error(&result.response, "Server error");
            for to in message.get_to() {
                response.add_result(&to.email, error.clone());
            }
        }
    }

    fn send_individually(
        &self,
        message: &Email,
        emails: &[Value],
        headers: &[String],
        response: &mut Response,
    ) {
        let recipients = message.get_to();
        let mut delivered = 0i64;
        for (index, email) in emails.iter().enumerate() {
            let to = &recipients[index];
            let result = self.request_default(
                "POST",
                "https://api.resend.com/emails",
                headers,
                Some(email.clone()),
            );
            let status = result.status_code;
            if (200..300).contains(&status) {
                response.add_result(&to.email, "");
                delivered += 1;
            } else if (400..500).contains(&status) {
                response.add_result(&to.email, extract_error(&result.response, "Unknown error"));
            } else {
                response.add_result(&to.email, extract_error(&result.response, "Server error"));
            }
        }
        response.set_delivered_to(delivered);
    }
}

fn extract_error(body: &Value, default: &str) -> String {
    if let Some(s) = body.as_str() {
        return s.to_string();
    }
    if let Some(m) = body.get("message").and_then(Value::as_str) {
        return m.to_string();
    }
    if let Some(m) = body.get("error").and_then(Value::as_str) {
        return m.to_string();
    }
    default.to_string()
}

impl Adapter for Resend {
    fn get_name(&self) -> &'static str {
        "Resend"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::Email
    }
    fn get_max_messages_per_request(&self) -> usize {
        100
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
