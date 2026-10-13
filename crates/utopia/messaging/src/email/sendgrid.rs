use php_std::Value;

use super::{MAX_ATTACHMENT_BYTES, TYPE, email, file_contents, filesize};
use crate::adapter::{Adapter, Base, configure};
use crate::error::Error;
use crate::http::{BoxFuture, Call, Http};
use crate::message::{Mailbox, Message, MessageType};
use crate::response::{Response, Sent};
use crate::values::{K, Site, at, list, map, result_text, text};

/// SendGrid's mail send API: one request, a personalization per recipient.
///
/// PHP: `Utopia\Messaging\Adapter\Email\Sendgrid`.
#[derive(Debug)]
pub struct Sendgrid {
    api_key: String,
    base: Base,
}

const NAME: &str = "Sendgrid";

fn entry(mailbox: &Mailbox) -> Value {
    match mailbox.display_name() {
        None => map([("email", text(&mailbox.email))]),
        Some(name) => map([("email", text(&mailbox.email)), ("name", text(name))]),
    }
}

impl Sendgrid {
    /// Sends with `api_key`.
    ///
    /// PHP: `new Sendgrid($apiKey)`.
    pub fn new(api_key: impl Into<String>) -> Sendgrid {
        Sendgrid { api_key: api_key.into(), base: Base::new(Http::new(NAME)) }
    }

    async fn send_email(&self, message: &Message) -> Result<Sent, Error> {
        let email = email(message);
        let cc = email.cc().filter(|c| !c.is_empty());
        let bcc = email.bcc().filter(|c| !c.is_empty());
        let personalizations = list(email.to().iter().map(|to| {
            let mut entries = vec![("to", list([entry(to)])), ("subject", text(email.subject()))];
            if let Some(cc) = cc {
                entries.push(("cc", list(cc.iter().map(entry))));
            }
            if let Some(bcc) = bcc {
                entries.push(("bcc", list(bcc.iter().map(entry))));
            }
            map(entries)
        }));

        let mut attachments = Vec::new();
        if let Some(files) = email.attachments() {
            let size: u64 = files.iter().map(|a| filesize(a.path()).unwrap_or(0)).sum();
            if size > MAX_ATTACHMENT_BYTES {
                return Err(Error::Exception("Attachments size exceeds the maximum allowed size of 25MB".into()));
            }
            for attachment in files {
                let content = file_contents(attachment.path())?.unwrap_or_default();
                attachments.push(map([
                    ("content", text(&php_std::encoding::base64_encode(&content))),
                    ("filename", text(attachment.name())),
                    ("type", text(attachment.mime_type())),
                    ("disposition", text("attachment")),
                ]));
            }
        }

        let mut body = vec![
            ("personalizations", personalizations),
            ("reply_to", map([("name", text(email.reply_to_name())), ("email", text(email.reply_to_email()))])),
            ("from", map([("name", text(email.from_name())), ("email", text(email.from_email()))])),
            (
                "content",
                list([map([
                    ("type", text(if email.is_html() { "text/html" } else { "text/plain" })),
                    ("value", text(email.content())),
                ])]),
            ),
        ];
        if !attachments.is_empty() {
            body.push(("attachments", list(attachments)));
        }
        if !email.headers().is_empty() {
            body.push(("headers", map(email.headers().iter().map(|(n, v)| (n.as_str(), text(v))))));
        }

        let result = self
            .base
            .request(Call::new(
                "POST",
                "https://api.sendgrid.com/v3/mail/send",
                vec![format!("Authorization: Bearer {}", self.api_key), "Content-Type: application/json".into()],
                Some(map(body)),
            ))
            .await?;

        let mut response = Response::new(TYPE);
        if result.status_code == 202 {
            response.set_delivered_to(email.to().len() as i64);
            for to in email.to() {
                response.add_success(&to.email);
            }
        } else {
            for to in email.to() {
                let error = match &result.response {
                    Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                    other => match at(other, &[K::S("errors"), K::I(0), K::S("message")]) {
                        Some(message) => {
                            result_text(&message, Site { argument: 2, file: "Adapter/Email/Sendgrid.php", line: 154 })?
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

configure!(Sendgrid, |_| Http::new(NAME));

impl Adapter for Sendgrid {
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
