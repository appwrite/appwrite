use php_std::Value;

use super::{all, sms_adapter};
use crate::adapter::Base;
use crate::error::Error;
use crate::http::{Call, Http, Reply};
use crate::message::Sms;
use crate::response::Response;
use crate::values::{K, at, entries, interpolate, list, map, text};

/// Why a message is sent; Bird requires it on every free-text send, and
/// carriers see it.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Bird\Category`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Category {
    /// Order confirmations, alerts and other expected messages.
    #[default]
    Transactional,
    /// Promotions.
    Marketing,
    /// One-time passcodes.
    Authentication,
    /// Account and service notices.
    Service,
}

impl Category {
    /// Every case, in declaration order.
    pub const ALL: [Category; 4] =
        [Category::Transactional, Category::Marketing, Category::Authentication, Category::Service];

    /// The value Bird takes (`transactional`).
    pub fn value(self) -> &'static str {
        match self {
            Category::Transactional => "transactional",
            Category::Marketing => "marketing",
            Category::Authentication => "authentication",
            Category::Service => "service",
        }
    }

    /// The case name (`TRANSACTIONAL`).
    pub fn name(self) -> &'static str {
        match self {
            Category::Transactional => "TRANSACTIONAL",
            Category::Marketing => "MARKETING",
            Category::Authentication => "AUTHENTICATION",
            Category::Service => "SERVICE",
        }
    }

    /// The case with this value.
    pub fn from_value(value: &str) -> Option<Category> {
        Category::ALL.into_iter().find(|c| c.value() == value)
    }
}

/// Bird's SMS API: one recipient through the message endpoint, several
/// through a batch Bird validates whole. The API key's region picks the
/// host.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Bird`.
#[derive(Debug)]
pub struct Bird {
    api_key: String,
    from: Option<String>,
    category: Category,
    endpoint: String,
    base: Base,
}

const MAX_BATCH_SIZE: i64 = 100;

impl Bird {
    /// Sends with `api_key` (`bk_{region}_...`) to its region's host.
    ///
    /// Fails with [`Error::InvalidArgument`] when the key carries no region.
    ///
    /// PHP: `new Bird($apiKey)`.
    pub fn new(api_key: impl Into<String>) -> Result<Bird, Error> {
        let api_key = api_key.into();
        let endpoint = Bird::host_for(&api_key)?;
        Ok(Bird::at(api_key, &endpoint))
    }

    /// Sends with `api_key` to `endpoint` instead of the key's regional host
    /// (for tests and proxies; a trailing `/` is dropped).
    ///
    /// PHP: `new Bird($apiKey, endpoint: $endpoint)`.
    pub fn at(api_key: impl Into<String>, endpoint: &str) -> Bird {
        Bird {
            api_key: api_key.into(),
            from: None,
            category: Category::Transactional,
            endpoint: endpoint.trim_end_matches('/').to_owned(),
            base: Base::new(Http::new("Bird")),
        }
    }

    /// The sender the workspace holds, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> Bird {
        self.from = from;
        self
    }

    /// Why the messages are sent.
    pub fn with_category(mut self, category: Category) -> Bird {
        self.category = category;
        self
    }

    /// The regional host of a key: `https://{region}.platform.bird.com`.
    ///
    /// Fails with [`Error::InvalidArgument`] unless the key starts with
    /// `bk_`, two lower-case letters, digits and `_`.
    ///
    /// PHP: `Bird::hostFor()`.
    pub fn host_for(api_key: &str) -> Result<String, Error> {
        let refused = || {
            Error::InvalidArgument("Bird API key must start with bk_{region}_, for example bk_eu1_ or bk_us1_.".into())
        };
        let rest = api_key.as_bytes().strip_prefix(b"bk_").ok_or_else(refused)?;
        if rest.len() < 3 || !rest[0].is_ascii_lowercase() || !rest[1].is_ascii_lowercase() {
            return Err(refused());
        }
        let digits = rest[2..].iter().take_while(|b| b.is_ascii_digit()).count();
        if digits == 0 || rest.get(2 + digits) != Some(&b'_') {
            return Err(refused());
        }
        Ok(format!("https://{}.platform.bird.com", String::from_utf8_lossy(&rest[..2 + digits])))
    }

    fn payload(&self, sms: &Sms, to: &str) -> Value {
        let from = self.from.as_deref().or(sms.from()).map_or(Value::Null, text);
        let mut payload = vec![
            ("to", text(to)),
            ("from", from),
            ("text", text(sms.content())),
            ("category", text(self.category.value())),
        ];
        if let Some(metadata) = sms.metadata()
            && !matches!(metadata, Value::Null)
            && !matches!(metadata, Value::Array(a) if a.is_empty())
        {
            payload.push(("metadata", metadata.clone()));
        }
        map(payload)
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let recipients: Vec<String> = sms.to().iter().map(|to| format!("+{}", to.trim_start_matches('+'))).collect();
        let payloads: Vec<Value> = recipients.iter().map(|to| self.payload(sms, to)).collect();
        let headers =
            vec!["Content-Type: application/json".to_owned(), format!("Authorization: Bearer {}", self.api_key)];
        let call = if payloads.len() == 1 {
            Call::new("POST", format!("{}/v1/sms/messages", self.endpoint), headers, payloads.into_iter().next())
        } else {
            Call::new(
                "POST",
                format!("{}/v1/sms/batches", self.endpoint),
                headers,
                Some(map([("messages", list(payloads))])),
            )
        };
        let result = self.base.request(call).await?;
        if result.is_success() {
            return Ok(all(&recipients, true, ""));
        }
        Ok(all(&recipients, false, &describe(&result)))
    }
}

/// Bird's error envelope in one line: code, message, and each detail.
fn describe(result: &Reply) -> String {
    let envelope = match &result.response {
        Value::Array(_) => at(&result.response, &[K::S("error")]).filter(|e| matches!(e, Value::Array(_))),
        _ => None,
    };
    let Some(envelope) = envelope else {
        return if result.error.is_empty() { "Unknown error.".into() } else { result.error.clone() };
    };
    let mut parts = Vec::new();
    for field in ["code", "message"] {
        if let Some(value) = at(&envelope, &[K::S(field)]) {
            parts.push(interpolate(&value));
        }
    }
    for (_, detail) in entries(&at(&envelope, &[K::S("details")]).unwrap_or(Value::Null)) {
        if let Value::Array(_) = detail
            && let (Some(param), Some(message)) = (at(&detail, &[K::S("param")]), at(&detail, &[K::S("message")]))
        {
            parts.push(format!("{}: {}", interpolate(&param), interpolate(&message)));
        }
    }
    if parts.is_empty() { "Unknown error.".into() } else { parts.join(" ") }
}

sms_adapter!(Bird, "Bird", MAX_BATCH_SIZE);
