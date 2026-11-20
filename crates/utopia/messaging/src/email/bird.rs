//! Bird's email API.
//!
//! PHP: `Utopia\Messaging\Adapter\Email\Bird` and `Email\Bird\Category`.

use std::collections::BTreeMap;

use php_std::Value;

use super::{TYPE, email, file_contents, filesize};
use crate::adapter::{Adapter, Base, configure};
use crate::error::{Error, Reason};
use crate::http::{BoxFuture, Call, Http, Reply};
use crate::message::{Email, Message, MessageType};
use crate::response::{Response, Sent};
use crate::values::{K, at, entries, interpolate, list, map, text};

/// Why an email is sent. Bird uses it to decide which suppressions and
/// unsubscribes may block the message.
///
/// PHP: `Utopia\Messaging\Adapter\Email\Bird\Category`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Category {
    /// Receipts, alerts and other mail the recipient's own action calls
    /// for, delivered past a spam complaint or a marketing opt-out.
    #[default]
    Transactional,
    /// Newsletters and promotions, held back for every suppression and
    /// opt-out.
    Marketing,
}

impl Category {
    /// Every case, in declaration order.
    pub const ALL: [Category; 2] = [Category::Transactional, Category::Marketing];

    /// The value Bird takes (`transactional`).
    pub fn value(self) -> &'static str {
        match self {
            Category::Transactional => "transactional",
            Category::Marketing => "marketing",
        }
    }

    /// The case name (`TRANSACTIONAL`).
    pub fn name(self) -> &'static str {
        match self {
            Category::Transactional => "TRANSACTIONAL",
            Category::Marketing => "MARKETING",
        }
    }

    /// The case with this value.
    pub fn from_value(value: &str) -> Option<Category> {
        Category::ALL.into_iter().find(|c| c.value() == value)
    }
}

/// Bird's email API: one message per To recipient, through the
/// single-message endpoint for one and batches of at most 100 messages and
/// 20 MB for several. A batch refusal that names `to` is retried one
/// message at a time, so only the refused recipient fails.
///
/// PHP: `Utopia\Messaging\Adapter\Email\Bird`.
#[derive(Debug)]
pub struct Bird {
    api_key: String,
    category: Category,
    endpoint: String,
    base: Base,
}

const NAME: &str = "Bird";

/// The body and every file after base64 encoding: the smaller of 20 MB and
/// 20 MiB.
const MAX_MESSAGE_BYTES: usize = 20 * 1000 * 1000;

const PATH_MESSAGE: &str = "/v1/email/messages";

const PATH_BATCH: &str = "/v1/email/batches";

const MAX_BATCH_SIZE: usize = 100;

const MAX_BATCH_BYTES: usize = 20 * 1000 * 1000;

/// Room kept per message for a recipient longer than the first one.
const RECIPIENT_BYTES: usize = 1024;

/// A message Bird did not accept.
#[derive(Debug, Clone)]
struct Refusal {
    status_code: i64,
    error: String,
}

impl Bird {
    /// Sends with `api_key` (`bk_{region}_...`, with the `emails:write`
    /// scope) for `category`, to `endpoint` when given (a trailing `/`
    /// dropped) and otherwise to the key's regional host.
    ///
    /// Fails with [`Error::InvalidArgument`] when the key carries no region
    /// and no endpoint is given.
    ///
    /// PHP: `new Bird($apiKey, $category = Category::TRANSACTIONAL, $endpoint = null)`.
    pub fn new(api_key: impl Into<String>, category: Category, endpoint: Option<&str>) -> Result<Bird, Error> {
        let api_key = api_key.into();
        let endpoint = match endpoint {
            Some(endpoint) => endpoint.to_owned(),
            None => crate::sms::Bird::host_for(&api_key)?,
        };
        Ok(Bird {
            api_key,
            category,
            endpoint: endpoint.trim_end_matches('/').to_owned(),
            base: Base::new(Http::new(NAME)),
        })
    }

    async fn send_email(&self, message: &Message) -> Result<Sent, Error> {
        let email = email(message);
        let recipients = email.to();
        if recipients.is_empty() {
            return Err(Error::invalid(Reason::RecipientEmpty, "Bird needs at least one To recipient.", None));
        }

        let attachments = attachments(email)?;
        let payloads: Vec<(usize, Value)> = recipients
            .iter()
            .enumerate()
            .map(|(index, to)| (index, self.payload(email, &to.email, to.name.as_deref(), &attachments)))
            .collect();

        let mut refusals = BTreeMap::new();
        for batch in batches(payloads)? {
            for (index, refusal) in self.submit(batch).await? {
                refusals.entry(index).or_insert(refusal);
            }
        }

        // 422: Bird refused the message as written, so a retry cannot help.
        if recipients.len() == 1
            && let Some(refusal) = refusals.get(&0)
            && refusal.status_code == 422
        {
            return Err(Error::invalid(
                Reason::ProviderRejected,
                refusal.error.clone(),
                Some(recipients[0].email.clone()),
            ));
        }

        let mut response = Response::new(TYPE);
        for (index, to) in recipients.iter().enumerate() {
            let error = refusals.get(&index).map(|r| r.error.clone()).unwrap_or_default();
            response.add_result(&to.email, error, false, 0);
        }
        response.set_delivered_to(recipients.len() as i64 - refusals.len() as i64);
        Ok(Sent::Response(response))
    }

    /// Sends one batch and reports the messages Bird did not accept, keyed
    /// like the messages.
    fn submit(&self, payloads: Vec<(usize, Value)>) -> BoxFuture<'_, Result<Vec<(usize, Refusal)>, Error>> {
        Box::pin(async move {
            let call = if payloads.len() == 1 {
                Call::new(
                    "POST",
                    format!("{}{PATH_MESSAGE}", self.endpoint),
                    self.headers(),
                    Some(payloads[0].1.clone()),
                )
            } else {
                Call::new(
                    "POST",
                    format!("{}{PATH_BATCH}", self.endpoint),
                    self.headers(),
                    Some(map([("messages", list(payloads.iter().map(|(_, p)| p.clone())))])),
                )
            };
            let result = self.base.request(call).await?;

            if (200..300).contains(&result.status_code) {
                return Ok(Vec::new());
            }

            if payloads.len() > 1 && result.status_code == 422 && field(&result).as_deref() == Some("to") {
                let mut refusals: Vec<(usize, Refusal)> = Vec::new();
                for payload in payloads {
                    for (index, refusal) in self.submit(vec![payload]).await? {
                        if !refusals.iter().any(|(i, _)| *i == index) {
                            refusals.push((index, refusal));
                        }
                    }
                }
                return Ok(refusals);
            }

            let refusal = Refusal { status_code: result.status_code, error: describe(&result) };
            Ok(payloads.into_iter().map(|(index, _)| (index, refusal.clone())).collect())
        })
    }

    fn headers(&self) -> Vec<String> {
        vec!["Content-Type: application/json".to_owned(), format!("Authorization: Bearer {}", self.api_key)]
    }

    fn payload(&self, email: &Email, to: &str, name: Option<&str>, attachments: &[Value]) -> Value {
        let mut payload = vec![
            ("from", address(email.from_email(), Some(email.from_name()))),
            ("to", list([address(to, name)])),
            ("subject", text(email.subject())),
            (if email.is_html() { "html" } else { "text" }, text(email.content())),
            ("category", text(self.category.value())),
        ];
        if email.has_reply_to() {
            payload.push(("reply_to", list([address(email.reply_to_email(), Some(email.reply_to_name()))])));
        }
        if let Some(cc) = email.cc().filter(|c| !c.is_empty()) {
            payload.push(("cc", list(cc.iter().map(|c| address(&c.email, c.name.as_deref())))));
        }
        if let Some(bcc) = email.bcc().filter(|c| !c.is_empty()) {
            payload.push(("bcc", list(bcc.iter().map(|c| address(&c.email, c.name.as_deref())))));
        }
        if !email.headers().is_empty() {
            payload.push(("headers", map(email.headers().iter().map(|(n, v)| (n.as_str(), text(v))))));
        }
        if !attachments.is_empty() {
            payload.push(("attachments", list(attachments.iter().cloned())));
        }
        map(payload)
    }
}

/// Splits the messages into batches Bird accepts: at most 100 messages and
/// 20 MB of JSON each, the first message standing for the size of them all.
fn batches(payloads: Vec<(usize, Value)>) -> Result<Vec<Vec<(usize, Value)>>, Error> {
    let first = payloads.first().map(|(_, p)| p.clone()).unwrap_or(Value::Bool(false));
    let encoded = php_std::json::encode(&first, php_std::json::Flags::THROW_ON_ERROR, php_std::json::DEFAULT_DEPTH)
        .map_err(|e| Error::Json(e.message().to_owned()))?;
    let size = encoded.len() + RECIPIENT_BYTES;
    let fitting = (MAX_BATCH_BYTES / size).clamp(1, MAX_BATCH_SIZE);
    let mut batches = Vec::new();
    let mut payloads = payloads.into_iter().peekable();
    while payloads.peek().is_some() {
        batches.push(payloads.by_ref().take(fitting).collect());
    }
    Ok(batches)
}

/// Bird takes the display name as a field of its own, so it needs no
/// RFC 5322 quoting.
fn address(email: &str, name: Option<&str>) -> Value {
    match name {
        None | Some("") | Some("0") => map([("email", text(email))]),
        Some(name) => map([("email", text(email)), ("name", text(name))]),
    }
}

/// The files, base64 encoded, once the body and the encoded files are
/// known to fit in a message.
fn attachments(email: &Email) -> Result<Vec<Value>, Error> {
    let files = email.attachments().unwrap_or_default();
    let mut size = email.content().len() as u64;
    for file in files {
        let bytes = match file.content() {
            Some(content) => content.len() as u64,
            None => filesize(file.path())
                .ok_or_else(|| Error::Exception(format!("Failed to read attachment file: {}", file.path())))?,
        };
        // What the file takes once it is base64 encoded.
        size += 4 * (bytes.div_ceil(3));
    }
    if size > MAX_MESSAGE_BYTES as u64 {
        return Err(Error::Exception(format!(
            "Message size exceeds {MAX_MESSAGE_BYTES} bytes: the body and the base64 encoded attachments take {size} bytes"
        )));
    }

    let mut attachments = Vec::new();
    for file in files {
        let content = match file.content() {
            Some(content) => content.to_vec(),
            None => file_contents(file.path())?
                .ok_or_else(|| Error::Exception(format!("Failed to read attachment file: {}", file.path())))?,
        };
        attachments.push(map([
            ("filename", text(file.name())),
            ("content", text(&php_std::encoding::base64_encode(&content))),
            ("content_type", text(file.mime_type())),
        ]));
    }
    Ok(attachments)
}

/// Bird's error envelope, when the answer carries one.
fn envelope(result: &Reply) -> Option<Value> {
    match &result.response {
        Value::Array(_) => at(&result.response, &[K::S("error")]).filter(|e| matches!(e, Value::Array(_))),
        _ => None,
    }
}

/// The request field Bird's refusal is about, when it names one.
fn field(result: &Reply) -> Option<String> {
    match at(&envelope(result)?, &[K::S("param")]) {
        Some(Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        _ => None,
    }
}

/// Bird's error envelope in one line: the stable code, the message, and
/// any per-field detail.
fn describe(result: &Reply) -> String {
    let unanswered = || format!("Bird answered with HTTP status {}.", result.status_code);
    let Some(envelope) = envelope(result) else {
        return if result.error.is_empty() { unanswered() } else { result.error.clone() };
    };
    let mut parts = Vec::new();
    for name in ["code", "message"] {
        if let Some(value) = at(&envelope, &[K::S(name)]).filter(|v| !matches!(v, Value::Null)) {
            parts.push(interpolate(&value));
        }
    }
    for (_, detail) in entries(&at(&envelope, &[K::S("details")]).unwrap_or(Value::Null)) {
        if let Value::Array(_) = detail
            && let (Some(param), Some(message)) = (
                at(&detail, &[K::S("param")]).filter(|v| !matches!(v, Value::Null)),
                at(&detail, &[K::S("message")]).filter(|v| !matches!(v, Value::Null)),
            )
        {
            parts.push(format!("{}: {}", interpolate(&param), interpolate(&message)));
        }
    }
    if parts.is_empty() { unanswered() } else { parts.join(" ") }
}

configure!(Bird, |_| Http::new(NAME));

impl Adapter for Bird {
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
        MAX_BATCH_SIZE as i64
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(self.send_email(message))
    }
}
