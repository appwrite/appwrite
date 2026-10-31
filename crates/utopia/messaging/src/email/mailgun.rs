use php_std::Value;
use utopia_client::multipart::Part;

use super::{MAX_ATTACHMENT_BYTES, TYPE, email, filesize, format_address};
use crate::adapter::{Adapter, Base, configure};
use crate::error::Error;
use crate::http::{BoxFuture, Call, Http};
use crate::message::{Message, MessageType};
use crate::response::{Response, Sent};
use crate::values::{K, Site, at, map, result_text, text};

/// Mailgun's messages API, one batch request for every recipient
/// (recipient variables keep them from seeing each other).
///
/// PHP: `Utopia\Messaging\Adapter\Email\Mailgun`.
#[derive(Debug)]
pub struct Mailgun {
    api_key: String,
    domain: String,
    eu: bool,
    base: Base,
}

const NAME: &str = "Mailgun";

impl Mailgun {
    /// Sends from `domain` with `api_key`, through the US region.
    ///
    /// PHP: `new Mailgun($apiKey, $domain, $isEU)`.
    pub fn new(api_key: impl Into<String>, domain: impl Into<String>) -> Mailgun {
        Mailgun { api_key: api_key.into(), domain: domain.into(), eu: false, base: Base::new(Http::new(NAME)) }
    }

    /// Through the EU region (`api.eu.mailgun.net`).
    pub fn with_eu(mut self, eu: bool) -> Mailgun {
        self.eu = eu;
        self
    }

    async fn send_email(&self, message: &Message) -> Result<Sent, Error> {
        let email = email(message);
        let host = if self.eu { "api.eu.mailgun.net" } else { "api.mailgun.net" };
        let recipients = email.to();

        let to = recipients.iter().map(|r| format_address(&r.email, r.name.as_deref())).collect::<Vec<_>>().join(",");
        let mut entries: Vec<(String, Value)> = vec![
            ("to".into(), text(&to)),
            ("from".into(), text(&format_address(email.from_email(), Some(email.from_name())))),
            ("subject".into(), text(email.subject())),
            ("text".into(), if email.is_html() { Value::Null } else { text(email.content()) }),
            ("html".into(), if email.is_html() { text(email.content()) } else { Value::Null }),
            ("h:Reply-To".into(), text(&format_address(email.reply_to_email(), Some(email.reply_to_name())))),
        ];
        let put = |entries: &mut Vec<(String, Value)>, key: String, value: Value| match entries
            .iter_mut()
            .find(|(k, _)| *k == key)
        {
            Some(entry) => entry.1 = value,
            None => entries.push((key, value)),
        };
        for (name, value) in email.headers() {
            put(&mut entries, format!("h:{name}"), text(value));
        }
        if recipients.len() > 1 {
            // json_encode(array_fill_keys($toEmails, [])): addresses are object keys.
            let variables = map(recipients.iter().map(|r| (r.email.as_str(), Value::Array(php_std::Array::new()))));
            let encoded = php_std::json::encode(&variables, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH)
                .unwrap_or_default();
            put(&mut entries, "recipient-variables".into(), text(&encoded));
        }
        for (key, list) in [("cc", email.cc()), ("bcc", email.bcc())] {
            let Some(list) = list else { continue };
            for recipient in list {
                if recipient.email.is_empty() || recipient.email == "0" {
                    continue;
                }
                let formatted = format_address(&recipient.email, recipient.name.as_deref());
                let current = entries.iter().find(|(k, _)| k == key).map(|(_, v)| v.clone());
                let joined = match current {
                    Some(Value::Str(s)) if !s.is_empty() && s.as_bytes() != b"0" => {
                        format!("{},{formatted}", String::from_utf8_lossy(s.as_bytes()))
                    }
                    _ => formatted,
                };
                put(&mut entries, key.into(), text(&joined));
            }
        }

        let mut files = Vec::new();
        if let Some(attachments) = email.attachments() {
            let size: u64 = attachments.iter().map(|a| filesize(a.path()).unwrap_or(0)).sum();
            if size > MAX_ATTACHMENT_BYTES {
                return Err(Error::Exception("Attachments size exceeds the maximum allowed size of ".into()));
            }
            for (index, attachment) in attachments.iter().enumerate() {
                let name = format!("attachment[{index}]");
                let part = Part::file(
                    &name,
                    attachment.path(),
                    Some(attachment.name()),
                    Some(attachment.mime_type()),
                    Vec::new(),
                )?;
                files.push((name, part));
            }
        }

        let mut headers = vec![format!(
            "Authorization: Basic {}",
            php_std::encoding::base64_encode(format!("api:{}", self.api_key).as_bytes())
        )];
        headers.push(if files.is_empty() {
            "Content-Type: application/x-www-form-urlencoded".into()
        } else {
            "Content-Type: multipart/form-data".into()
        });

        let body = map(entries.iter().map(|(k, v)| (k.as_str(), v.clone())));
        let mut call = Call::new("POST", format!("https://{host}/v3/{}/messages", self.domain), headers, Some(body));
        call.files = files;
        let result = self.base.request(call).await?;

        let mut response = Response::new(TYPE);
        let status = result.status_code;
        if (200..300).contains(&status) {
            response.set_delivered_to(recipients.len() as i64);
            for to in recipients {
                response.add_success(&to.email);
            }
        } else if (400..500).contains(&status) {
            for to in recipients {
                let error = match &result.response {
                    Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                    other => match at(other, &[K::S("message")]) {
                        Some(message) => {
                            result_text(&message, Site { argument: 2, file: "Adapter/Email/Mailgun.php", line: 155 })?
                        }
                        None => "Unknown error".into(),
                    },
                };
                response.add_failure(&to.email, error);
            }
        }
        Ok(Sent::Response(response))
    }
}

configure!(Mailgun, |_| Http::new(NAME));

impl Adapter for Mailgun {
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
        1000
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(self.send_email(message))
    }
}
