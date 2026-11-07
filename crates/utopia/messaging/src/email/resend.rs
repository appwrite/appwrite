use php_std::types::ArrayKey;
use php_std::{Array, Str, Value};

use super::{TYPE, email, file_contents, filesize, format_address};
use crate::adapter::{Adapter, Base, configure};
use crate::error::{Error, Reason};
use crate::http::{BoxFuture, Call, Http, Reply};
use crate::message::{Email, Message, MessageType};
use crate::response::{Response, Sent};
use crate::values::{Site, list, map, result_text, scalar_text, text};

/// Resend's email API: one batch request, or one request per recipient
/// when there are attachments (batches cannot carry them).
///
/// PHP: `Utopia\Messaging\Adapter\Email\Resend`.
#[derive(Debug)]
pub struct Resend {
    api_key: String,
    base: Base,
}

const NAME: &str = "Resend";

/// Resend's attachment limit.
///
/// PHP: `Resend::MAX_ATTACHMENT_BYTES`.
pub const MAX_ATTACHMENT_BYTES: u64 = 40 * 1024 * 1024;

impl Resend {
    /// Sends with `api_key`.
    ///
    /// PHP: `new Resend($apiKey)`.
    pub fn new(api_key: impl Into<String>) -> Resend {
        Resend { api_key: api_key.into(), base: Base::new(Http::new(NAME)) }
    }

    async fn send_email(&self, message: &Message) -> Result<Sent, Error> {
        let email = email(message);
        let mut attachments = Vec::new();
        if let Some(files) = email.attachments().filter(|a| !a.is_empty()) {
            let mut size = 0u64;
            for attachment in files {
                size += match attachment.content() {
                    Some(content) => content.len() as u64,
                    None => filesize(attachment.path()).ok_or_else(|| {
                        Error::Exception(format!("Failed to read attachment file: {}", attachment.path()))
                    })?,
                };
            }
            if size > MAX_ATTACHMENT_BYTES {
                return Err(Error::Exception(format!("Total attachment size exceeds {MAX_ATTACHMENT_BYTES} bytes")));
            }
            for attachment in files {
                let content = match attachment.content() {
                    Some(content) => content.to_vec(),
                    None => file_contents(attachment.path())?.ok_or_else(|| {
                        Error::Exception(format!("Failed to read attachment file: {}", attachment.path()))
                    })?,
                };
                attachments.push(map([
                    ("filename", text(attachment.name())),
                    ("content", text(&php_std::encoding::base64_encode(&content))),
                    ("content_type", text(attachment.mime_type())),
                ]));
            }
        }

        let emails: Vec<Value> = email
            .to()
            .iter()
            .map(|to| {
                let mut entries = vec![
                    ("from", text(&format_address(email.from_email(), Some(email.from_name())))),
                    ("to", list([text(&format_address(&to.email, to.name.as_deref()))])),
                    ("subject", text(email.subject())),
                ];
                entries.push(if email.is_html() {
                    ("html", text(email.content()))
                } else {
                    ("text", text(email.content()))
                });
                if email.has_reply_to() {
                    entries.push((
                        "reply_to",
                        list([text(&format_address(email.reply_to_email(), Some(email.reply_to_name())))]),
                    ));
                }
                if let Some(cc) = email.cc().filter(|c| !c.is_empty()) {
                    entries.push(("cc", list(cc.iter().map(|c| text(&format_address(&c.email, c.name.as_deref()))))));
                }
                if !attachments.is_empty() {
                    entries.push(("attachments", list(attachments.iter().cloned())));
                }
                if let Some(bcc) = email.bcc().filter(|c| !c.is_empty()) {
                    entries.push(("bcc", list(bcc.iter().map(|c| text(&format_address(&c.email, c.name.as_deref()))))));
                }
                if !email.headers().is_empty() {
                    entries.push(("headers", map(email.headers().iter().map(|(n, v)| (n.as_str(), text(v))))));
                }
                map(entries)
            })
            .collect();

        let headers =
            vec![format!("Authorization: Bearer {}", self.api_key), "Content-Type: application/json".to_owned()];
        if attachments.is_empty() {
            self.send_batch(email, emails, headers).await
        } else {
            self.send_individually(email, emails, headers).await
        }
    }

    async fn send_batch(&self, email: &Email, emails: Vec<Value>, headers: Vec<String>) -> Result<Sent, Error> {
        let result = self
            .base
            .request(Call::new("POST", "https://api.resend.com/emails/batch", headers, Some(list(emails))))
            .await?;
        let recipients = email.to();
        let mut response = Response::new(TYPE);
        let status = result.status_code;

        if status == 422 {
            return Err(Error::invalid(
                Reason::ProviderRejected,
                error_message(&result, "Unprocessable request"),
                (recipients.len() == 1).then(|| recipients[0].email.clone()),
            ));
        }

        if status == 200 {
            let errors = match &result.response {
                Value::Array(body) => body.get_str("errors").filter(|e| e.truthy()).cloned(),
                _ => None,
            };
            if let Some(errors) = errors {
                let mut failed = Array::new();
                for (_, error) in crate::values::entries(&errors) {
                    let index = key(crate::values::at(&error, &[crate::values::K::S("index")]).as_ref());
                    let message = crate::values::at(&error, &[crate::values::K::S("message")]).unwrap_or(Value::Null);
                    failed.set(index, message);
                }
                for (index, to) in recipients.iter().enumerate() {
                    match failed.get(&ArrayKey::Int(index as i64)) {
                        Some(message) if !matches!(message, Value::Null) => {
                            let error = result_text(
                                message,
                                Site { argument: 2, file: "Adapter/Email/Resend.php", line: 174 },
                            )?;
                            response.add_failure(&to.email, error);
                        }
                        _ => response.add_success(&to.email),
                    }
                }
                response.set_delivered_to(recipients.len() as i64 - failed.len() as i64);
            } else {
                response.set_delivered_to(recipients.len() as i64);
                for to in recipients {
                    response.add_success(&to.email);
                }
            }
        } else if (400..500).contains(&status) {
            let error = error_message(&result, "Unknown error");
            for to in recipients {
                response.add_failure(&to.email, error.clone());
            }
        } else if status >= 500 {
            let error = error_message(&result, "Server error");
            for to in recipients {
                response.add_failure(&to.email, error.clone());
            }
        }
        Ok(Sent::Response(response))
    }

    async fn send_individually(&self, email: &Email, emails: Vec<Value>, headers: Vec<String>) -> Result<Sent, Error> {
        let recipients = email.to();
        let count = emails.len();
        let mut delivered = 0;
        let mut response = Response::new(TYPE);
        for (index, body) in emails.into_iter().enumerate() {
            let to = &recipients[index];
            let result = self
                .base
                .request(Call::new("POST", "https://api.resend.com/emails", headers.clone(), Some(body)))
                .await?;
            let status = result.status_code;
            if status == 422 && count == 1 {
                return Err(Error::invalid(
                    Reason::ProviderRejected,
                    error_message(&result, "Unprocessable request"),
                    Some(to.email.clone()),
                ));
            }
            if (200..300).contains(&status) {
                response.add_success(&to.email);
                delivered += 1;
            } else if (400..500).contains(&status) {
                response.add_failure(&to.email, error_message(&result, "Unknown error"));
            } else {
                response.add_failure(&to.email, error_message(&result, "Server error"));
            }
        }
        response.set_delivered_to(delivered);
        Ok(Sent::Response(response))
    }
}

/// `$failedIndices[$error['index']]`: the array key an index value becomes.
fn key(index: Option<&Value>) -> ArrayKey {
    match index {
        None | Some(Value::Null) => ArrayKey::normalize(Str::from("")),
        Some(Value::Int(i)) => ArrayKey::Int(*i),
        Some(Value::Bool(b)) => ArrayKey::Int(i64::from(*b)),
        Some(Value::Float(f)) => ArrayKey::Int(*f as i64),
        Some(Value::Str(s)) => ArrayKey::normalize(s.clone()),
        Some(other) => ArrayKey::normalize(Str::from(scalar_text(other).unwrap_or_default())),
    }
}

/// PHP: `Resend::extractErrorMessage()`, whose `array|string|null`
/// parameter takes a scalar body as its string.
fn error_message(result: &Reply, default: &str) -> String {
    match &result.response {
        Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        Value::Array(body) => {
            for field in ["message", "error"] {
                if let Some(Value::Str(s)) = body.get_str(field) {
                    return String::from_utf8_lossy(s.as_bytes()).into_owned();
                }
            }
            default.to_owned()
        }
        Value::Null => default.to_owned(),
        other => scalar_text(other).unwrap_or_else(|| default.to_owned()),
    }
}

configure!(Resend, |_| Http::new(NAME));

impl Adapter for Resend {
    fn name(&self) -> &str {
        NAME
    }

    fn kind(&self) -> &str {
        TYPE
    }

    fn message_type(&self) -> MessageType {
        MessageType::Email
    }

    fn max_messages_per_request(&self) -> i64 {
        100
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(self.send_email(message))
    }
}
