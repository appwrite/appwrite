//! The SMS gateways with one request per send and a plain answer.

use php_std::Value;

use super::{TYPE, all, digits, sms_adapter};
use crate::adapter::Base;
use crate::error::Error;
use crate::http::{Call, Http};
use crate::message::{Sms, pcre_end};
use crate::response::Response;
use crate::values::{K, Site, at, list, map, result_text, text};

fn opt(value: Option<&str>) -> Value {
    value.map_or(Value::Null, text)
}

fn basic(user: &str, secret: &str) -> String {
    format!("Authorization: Basic {}", php_std::encoding::base64_encode(format!("{user}:{secret}").as_bytes()))
}

/// The first recipient as `Response::addResult()` receives it: an empty
/// list leaves it `null`, a `TypeError`.
fn first(to: &[String], file: &'static str, line: u32) -> Result<String, Error> {
    match to.first() {
        Some(recipient) => Ok(recipient.clone()),
        None => result_text(&Value::Null, Site { argument: 1, file, line }),
    }
}

/// Twilio's messages API, one recipient per request, from a number or a
/// messaging service.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Twilio`.
#[derive(Debug)]
pub struct Twilio {
    account_sid: String,
    auth_token: String,
    from: Option<String>,
    messaging_service_sid: Option<String>,
    base: Base,
}

impl Twilio {
    /// PHP: `new Twilio($accountSid, $authToken, $from, $messagingServiceSid)`.
    pub fn new(account_sid: impl Into<String>, auth_token: impl Into<String>) -> Twilio {
        Twilio {
            account_sid: account_sid.into(),
            auth_token: auth_token.into(),
            from: None,
            messaging_service_sid: None,
            base: Base::new(Http::new("Twilio")),
        }
    }

    /// The sender, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> Twilio {
        self.from = from;
        self
    }

    /// The messaging service to send through.
    pub fn with_messaging_service_sid(mut self, sid: Option<String>) -> Twilio {
        self.messaging_service_sid = sid;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let to = sms.to().first().map_or(Value::Null, |t| text(t));
        let result = self
            .base
            .request(Call::new(
                "POST",
                format!("https://api.twilio.com/2010-04-01/Accounts/{}/Messages.json", self.account_sid),
                vec![
                    "Content-Type: application/x-www-form-urlencoded".into(),
                    basic(&self.account_sid, &self.auth_token),
                ],
                Some(map([
                    ("Body", text(sms.content())),
                    ("From", opt(self.from.as_deref().or(sms.from()))),
                    ("MessagingServiceSid", opt(self.messaging_service_sid.as_deref())),
                    ("To", to),
                ])),
            ))
            .await?;
        let mut response = Response::new(TYPE);
        const FILE: &str = "Adapter/SMS/Twilio.php";
        if result.is_success() {
            response.set_delivered_to(1);
            response.add_success(first(sms.to(), FILE, 60)?);
        } else if let Some(message) = at(&result.response, &[K::S("message")]) {
            let recipient = first(sms.to(), FILE, 62)?;
            response.add_failure(recipient, result_text(&message, Site { argument: 2, file: FILE, line: 62 })?);
        } else {
            response.add_failure(first(sms.to(), FILE, 64)?, "Unknown error");
        }
        Ok(response)
    }
}

sms_adapter!(Twilio, "Twilio", 1);

/// Telnyx's messaging API (v2).
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Telnyx`.
#[derive(Debug)]
pub struct Telnyx {
    api_key: String,
    from: Option<String>,
    base: Base,
}

impl Telnyx {
    /// PHP: `new Telnyx($apiKey, $from)`.
    pub fn new(api_key: impl Into<String>) -> Telnyx {
        Telnyx { api_key: api_key.into(), from: None, base: Base::new(Http::new("Telnyx")) }
    }

    /// The sender, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> Telnyx {
        self.from = from;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let result = self
            .base
            .request(Call::new(
                "POST",
                "https://api.telnyx.com/v2/messages",
                vec!["Content-Type: application/json".into(), format!("Authorization: Bearer {}", self.api_key)],
                Some(map([
                    ("text", text(sms.content())),
                    ("from", opt(self.from.as_deref().or(sms.from()))),
                    ("to", opt(sms.to().first().map(String::as_str))),
                ])),
            ))
            .await?;
        Ok(all(sms.to(), result.is_success(), "Unknown error."))
    }
}

sms_adapter!(Telnyx, "Telnyx", 1);

/// Clickatell's SMS API.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Clickatell`.
#[derive(Debug)]
pub struct Clickatell {
    api_key: String,
    from: Option<String>,
    base: Base,
}

impl Clickatell {
    /// PHP: `new Clickatell($apiKey, $from)`.
    pub fn new(api_key: impl Into<String>) -> Clickatell {
        Clickatell { api_key: api_key.into(), from: None, base: Base::new(Http::new("Clickatell")) }
    }

    /// The sender, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> Clickatell {
        self.from = from;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let result = self
            .base
            .request(Call::new(
                "POST",
                "https://platform.clickatell.com/messages",
                vec!["Content-Type: application/json".into(), format!("Authorization: {}", self.api_key)],
                Some(map([
                    ("content", text(sms.content())),
                    ("from", opt(self.from.as_deref().or(sms.from()))),
                    ("to", list(sms.to().iter().map(|t| text(t)))),
                ])),
            ))
            .await?;
        Ok(all(sms.to(), result.is_success(), "Unknown error."))
    }
}

sms_adapter!(Clickatell, "Clickatell", 500);

/// seven.io's SMS gateway.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Seven`.
#[derive(Debug)]
pub struct Seven {
    api_key: String,
    from: Option<String>,
    base: Base,
}

impl Seven {
    /// PHP: `new Seven($apiKey, $from)`.
    pub fn new(api_key: impl Into<String>) -> Seven {
        Seven { api_key: api_key.into(), from: None, base: Base::new(Http::new("Seven")) }
    }

    /// The sender, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> Seven {
        self.from = from;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let result = self
            .base
            .request(Call::new(
                "POST",
                "https://gateway.sms77.io/api/sms",
                vec!["Content-Type: application/json".into(), format!("Authorization: Basic {}", self.api_key)],
                Some(map([
                    ("from", opt(self.from.as_deref().or(sms.from()))),
                    ("to", text(&sms.to().join(","))),
                    ("text", text(sms.content())),
                ])),
            ))
            .await?;
        Ok(all(sms.to(), result.is_success(), "Unknown error."))
    }
}

sms_adapter!(Seven, "Seven", 1000);

/// Plivo's message API.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Plivo`.
#[derive(Debug)]
pub struct Plivo {
    auth_id: String,
    auth_token: String,
    from: Option<String>,
    base: Base,
}

impl Plivo {
    /// PHP: `new Plivo($authId, $authToken, $from)`.
    pub fn new(auth_id: impl Into<String>, auth_token: impl Into<String>) -> Plivo {
        Plivo {
            auth_id: auth_id.into(),
            auth_token: auth_token.into(),
            from: None,
            base: Base::new(Http::new("Plivo")),
        }
    }

    /// The sender, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> Plivo {
        self.from = from;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let result = self
            .base
            .request(Call::new(
                "POST",
                format!("https://api.plivo.com/v1/Account/{}/Message/", self.auth_id),
                vec!["Content-Type: application/x-www-form-urlencoded".into(), basic(&self.auth_id, &self.auth_token)],
                Some(map([
                    ("text", text(sms.content())),
                    ("src", text(self.from.as_deref().or(sms.from()).unwrap_or("Plivo"))),
                    ("dst", text(&sms.to().join("<"))),
                ])),
            ))
            .await?;
        Ok(all(sms.to(), result.is_success(), "Unknown error."))
    }
}

sms_adapter!(Plivo, "Plivo", 1000);

/// Sinch's batches API.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Sinch`.
#[derive(Debug)]
pub struct Sinch {
    service_plan_id: String,
    api_token: String,
    from: Option<String>,
    base: Base,
}

impl Sinch {
    /// PHP: `new Sinch($servicePlanId, $apiToken, $from)`.
    pub fn new(service_plan_id: impl Into<String>, api_token: impl Into<String>) -> Sinch {
        Sinch {
            service_plan_id: service_plan_id.into(),
            api_token: api_token.into(),
            from: None,
            base: Base::new(Http::new("Sinch")),
        }
    }

    /// The sender, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> Sinch {
        self.from = from;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let result = self
            .base
            .request(Call::new(
                "POST",
                format!("https://sms.api.sinch.com/xms/v1/{}/batches", self.service_plan_id),
                vec!["Content-Type: application/json".into(), format!("Authorization: Bearer {}", self.api_token)],
                Some(map([
                    ("from", opt(self.from.as_deref().or(sms.from()))),
                    ("to", list(sms.to().iter().map(|t| text(digits(t))))),
                    ("body", text(sms.content())),
                ])),
            ))
            .await?;
        Ok(all(sms.to(), result.is_success(), "Unknown error."))
    }
}

sms_adapter!(Sinch, "Sinch", 1000);

/// Infobip's advanced text API, on the account's own base URL.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Infobip`.
#[derive(Debug)]
pub struct Infobip {
    api_base_url: String,
    api_key: String,
    from: Option<String>,
    base: Base,
}

impl Infobip {
    /// PHP: `new Infobip($apiBaseUrl, $apiKey, $from)`.
    pub fn new(api_base_url: impl Into<String>, api_key: impl Into<String>) -> Infobip {
        Infobip {
            api_base_url: api_base_url.into(),
            api_key: api_key.into(),
            from: None,
            base: Base::new(Http::new("Infobip")),
        }
    }

    /// The sender, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> Infobip {
        self.from = from;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let result = self
            .base
            .request(Call::new(
                "POST",
                format!("https://{}/sms/2/text/advanced", self.api_base_url),
                vec!["Content-Type: application/json".into(), format!("Authorization: App {}", self.api_key)],
                Some(map([(
                    "messages",
                    map([
                        ("text", text(sms.content())),
                        ("from", opt(self.from.as_deref().or(sms.from()))),
                        ("destinations", list(sms.to().iter().map(|t| map([("to", text(digits(t)))])))),
                    ]),
                )])),
            ))
            .await?;
        Ok(all(sms.to(), result.is_success(), "Unknown error."))
    }
}

sms_adapter!(Infobip, "Infobip", 1000);

/// Inforu's SMS API.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Inforu`.
#[derive(Debug)]
pub struct Inforu {
    sender_id: String,
    api_token: String,
    base: Base,
}

impl Inforu {
    /// PHP: `new Inforu($senderId, $apiToken)`.
    pub fn new(sender_id: impl Into<String>, api_token: impl Into<String>) -> Inforu {
        Inforu { sender_id: sender_id.into(), api_token: api_token.into(), base: Base::new(Http::new("Inforu")) }
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let result = self
            .base
            .request(Call::new(
                "POST",
                "https://capi.inforu.co.il/api/v2/SMS/SendSms",
                vec!["Content-Type: application/json".into(), format!("Authorization: Basic {}", self.api_token)],
                Some(map([(
                    "Data",
                    map([
                        ("Message", text(sms.content())),
                        ("Recipients", list(sms.to().iter().map(|t| map([("Phone", text(digits(t)))])))),
                        ("Settings", map([("Sender", text(&self.sender_id))])),
                    ]),
                )])),
            ))
            .await?;
        let accepted = result.status_code == 200
            && matches!(at(&result.response, &[K::S("StatusId")]).unwrap_or(Value::Int(0)), Value::Int(1));
        if accepted {
            return Ok(all(sms.to(), true, ""));
        }
        let error = at(&result.response, &[K::S("StatusDescription")]).unwrap_or_else(|| text("Unknown error"));
        let mut response = Response::new(TYPE);
        for to in sms.to() {
            response
                .add_failure(to, result_text(&error, Site { argument: 2, file: "Adapter/SMS/Inforu.php", line: 76 })?);
        }
        Ok(response)
    }
}

sms_adapter!(Inforu, "Inforu", 100);

/// The Msg91 metadata the adapter forwards.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Msg91\MetadataParameter`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Msg91Parameter {
    /// Echoed in webhook callbacks (`clientId`).
    ClientId,
    /// A request-level tracking ID (`CRQID`).
    Crqid,
    /// Another request-level tracking ID (`UUID`).
    Uuid,
}

impl Msg91Parameter {
    /// Every case, in declaration order.
    pub const ALL: [Msg91Parameter; 3] = [Msg91Parameter::ClientId, Msg91Parameter::Crqid, Msg91Parameter::Uuid];

    /// The metadata key.
    pub fn value(self) -> &'static str {
        match self {
            Msg91Parameter::ClientId => "clientId",
            Msg91Parameter::Crqid => "CRQID",
            Msg91Parameter::Uuid => "UUID",
        }
    }

    /// The case name.
    pub fn name(self) -> &'static str {
        match self {
            Msg91Parameter::ClientId => "CLIENT_ID",
            Msg91Parameter::Crqid => "CRQID",
            Msg91Parameter::Uuid => "UUID",
        }
    }
}

/// Whether a tracking ID is at most 80 characters of letters, digits, `_`,
/// `.` and `-` (a final newline passes, as PCRE's `$` lets it).
pub(crate) fn tracking_id(value: &[u8]) -> bool {
    let body = pcre_end(value);
    value.len() <= 80 && !body.is_empty() && body.iter().all(|b| b.is_ascii_alphanumeric() || b"_.-".contains(b))
}

/// Msg91's flow API with a template, the content passed as the template's
/// variables (`content` and `otp`).
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Msg91`.
#[derive(Debug)]
pub struct Msg91 {
    sender_id: String,
    auth_key: String,
    template_id: String,
    base: Base,
}

impl Msg91 {
    /// PHP: `new Msg91($senderId, $authKey, $templateId)`.
    pub fn new(sender_id: impl Into<String>, auth_key: impl Into<String>, template_id: impl Into<String>) -> Msg91 {
        Msg91 {
            sender_id: sender_id.into(),
            auth_key: auth_key.into(),
            template_id: template_id.into(),
            base: Base::new(Http::new("Msg91")),
        }
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let metadata: Vec<(String, Value)> = crate::values::entries(sms.metadata().unwrap_or(&Value::Null))
            .into_iter()
            .filter_map(|(key, value)| {
                let key = match key {
                    php_std::types::ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                    php_std::types::ArrayKey::Int(i) => i.to_string(),
                };
                Msg91Parameter::ALL.iter().any(|p| p.value() == key).then_some((key, value))
            })
            .collect();
        for (key, value) in &metadata {
            if !matches!(value, Value::Str(_)) {
                return Err(Error::InvalidArgument(format!("Msg91 {key} metadata must be a string.")));
            }
        }
        for parameter in [Msg91Parameter::Crqid, Msg91Parameter::Uuid] {
            let key = parameter.value();
            if let Some((_, Value::Str(value))) = metadata.iter().find(|(k, _)| k == key)
                && !tracking_id(value.as_bytes())
            {
                return Err(Error::InvalidArgument(format!(
                    "Msg91 {key} metadata must be 80 characters or less and contain only alphanumeric characters, underscores, dots, or hyphens."
                )));
            }
        }
        let recipients = list(sms.to().iter().map(|to| {
            map([("mobiles", text(digits(to))), ("content", text(sms.content())), ("otp", text(sms.content()))])
        }));
        let mut body = vec![
            ("sender", text(&self.sender_id)),
            ("template_id", text(&self.template_id)),
            ("recipients", recipients),
        ];
        body.extend(metadata.iter().map(|(k, v)| (k.as_str(), v.clone())));
        let result = self
            .base
            .request(Call::new(
                "POST",
                "https://api.msg91.com/api/v5/flow/",
                vec!["Content-Type: application/json".into(), format!("Authkey: {}", self.auth_key)],
                Some(map(body)),
            ))
            .await?;
        Ok(all(sms.to(), result.status_code == 200, "Unknown error"))
    }
}

sms_adapter!(Msg91, "Msg91", 100);

/// Telesign's bulk SMS API; each recipient carries its own random
/// external ID.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Telesign`.
#[derive(Debug)]
pub struct Telesign {
    customer_id: String,
    api_key: String,
    base: Base,
}

impl Telesign {
    /// PHP: `new Telesign($customerId, $apiKey)`.
    pub fn new(customer_id: impl Into<String>, api_key: impl Into<String>) -> Telesign {
        Telesign { customer_id: customer_id.into(), api_key: api_key.into(), base: Base::new(Http::new("Telesign")) }
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let recipients = sms
            .to()
            .iter()
            .map(|n| format!("{n}:{}", php_std::system::uniqid("", false)))
            .collect::<Vec<_>>()
            .join(",");
        let result = self
            .base
            .request(Call::new(
                "POST",
                "https://rest-ww.telesign.com/v1/verify/bulk_sms",
                vec!["Content-Type: application/x-www-form-urlencoded".into(), basic(&self.customer_id, &self.api_key)],
                Some(map([("template", text(sms.content())), ("recipients", text(&recipients))])),
            ))
            .await?;
        if result.status_code == 200 {
            return Ok(all(sms.to(), true, ""));
        }
        let mut response = Response::new(TYPE);
        let description = at(&result.response, &[K::S("errors"), K::I(0), K::S("description")]);
        for to in sms.to() {
            let error = match &description {
                Some(d) => result_text(d, Site { argument: 2, file: "Adapter/SMS/Telesign.php", line: 72 })?,
                None => "Unknown error".into(),
            };
            response.add_failure(to, error);
        }
        Ok(response)
    }
}

sms_adapter!(Telesign, "Telesign", 1000);

/// Textmagic's messages API.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\TextMagic`.
#[derive(Debug)]
pub struct TextMagic {
    username: String,
    api_key: String,
    from: Option<String>,
    base: Base,
}

impl TextMagic {
    /// PHP: `new TextMagic($username, $apiKey, $from)`.
    pub fn new(username: impl Into<String>, api_key: impl Into<String>) -> TextMagic {
        TextMagic {
            username: username.into(),
            api_key: api_key.into(),
            from: None,
            base: Base::new(Http::new("Textmagic")),
        }
    }

    /// The sender, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> TextMagic {
        self.from = from;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let from = self.from.as_deref().or(sms.from()).unwrap_or("");
        let result = self
            .base
            .request(Call::new(
                "POST",
                "https://rest.textmagic.com/api/v2/messages",
                vec![
                    "Content-Type: application/x-www-form-urlencoded".into(),
                    format!("X-TM-Username: {}", self.username),
                    format!("X-TM-Key: {}", self.api_key),
                ],
                Some(map([
                    ("text", text(sms.content())),
                    ("from", text(digits(from))),
                    ("phones", text(&sms.to().iter().map(|t| digits(t)).collect::<Vec<_>>().join(","))),
                ])),
            ))
            .await?;
        if result.is_success() {
            return Ok(all(sms.to(), true, ""));
        }
        let mut response = Response::new(TYPE);
        for to in sms.to() {
            let error = match at(&result.response, &[K::S("message")]) {
                Some(message) => {
                    result_text(&message, Site { argument: 2, file: "Adapter/SMS/TextMagic.php", line: 74 })?
                }
                None => "Unknown error".into(),
            };
            response.add_failure(to, error);
        }
        Ok(response)
    }
}

sms_adapter!(TextMagic, "Textmagic", 1000);

/// Vonage's (Nexmo's) SMS API, one recipient per request.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Vonage`.
#[derive(Debug)]
pub struct Vonage {
    api_key: String,
    api_secret: String,
    from: Option<String>,
    base: Base,
}

impl Vonage {
    /// PHP: `new Vonage($apiKey, $apiSecret, $from)`.
    pub fn new(api_key: impl Into<String>, api_secret: impl Into<String>) -> Vonage {
        Vonage {
            api_key: api_key.into(),
            api_secret: api_secret.into(),
            from: None,
            base: Base::new(Http::new("Vonage")),
        }
    }

    /// The sender, over the message's own.
    pub fn with_from(mut self, from: Option<String>) -> Vonage {
        self.from = from;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        // The header is given as a value without a name, as PHP passes it:
        // no header is set, but it still makes the body a form.
        let result = self
            .base
            .request(Call::new(
                "POST",
                "https://rest.nexmo.com/sms/json",
                vec!["application/x-www-form-urlencoded".into()],
                Some(map([
                    ("text", text(sms.content())),
                    ("from", opt(self.from.as_deref().or(sms.from()))),
                    ("to", opt(sms.to().first().map(|t| digits(t)))),
                    ("api_key", text(&self.api_key)),
                    ("api_secret", text(&self.api_secret)),
                ])),
            ))
            .await?;
        const FILE: &str = "Adapter/SMS/Vonage.php";
        let mut response = Response::new(TYPE);
        let message = |key| at(&result.response, &[K::S("messages"), K::I(0), K::S(key)]);
        if matches!(message("status"), Some(Value::Int(0))) {
            response.set_delivered_to(1);
            let to = message("to").unwrap_or(Value::Null);
            response.add_success(result_text(&to, Site { argument: 1, file: FILE, line: 66 })?);
        } else if let Some(error) = message("error-text") {
            let recipient = first(sms.to(), FILE, 68)?;
            response.add_failure(recipient, result_text(&error, Site { argument: 2, file: FILE, line: 68 })?);
        } else {
            response.add_failure(first(sms.to(), FILE, 70)?, "Unknown error");
        }
        Ok(response)
    }
}

sms_adapter!(Vonage, "Vonage", 1);

/// A test double posting every message to a request catcher the operator
/// runs (any address is allowed).
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Mock`.
#[derive(Debug)]
pub struct Mock {
    user: String,
    secret: String,
    url: std::sync::RwLock<String>,
    base: Base,
}

impl Mock {
    /// PHP: `new Mock($user, $secret)`.
    pub fn new(user: impl Into<String>, secret: impl Into<String>) -> Mock {
        Mock {
            user: user.into(),
            secret: secret.into(),
            url: std::sync::RwLock::new("http://request-catcher:5000/mock-sms".into()),
            base: Base::new(Http::anywhere("Mock")),
        }
    }

    /// Where messages are posted.
    ///
    /// PHP: `getEndpoint()`.
    pub fn endpoint(&self) -> String {
        self.url.read().unwrap_or_else(|e| e.into_inner()).clone()
    }

    /// Posts messages to `url` from now on.
    ///
    /// PHP: `setEndpoint()`.
    pub fn set_endpoint(&self, url: impl Into<String>) -> &Mock {
        *self.url.write().unwrap_or_else(|e| e.into_inner()) = url.into();
        self
    }

    /// Posts messages to `url`.
    pub fn with_endpoint(self, url: impl Into<String>) -> Mock {
        self.set_endpoint(url);
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let mut response = Response::new(TYPE);
        response.set_delivered_to(sms.to().len() as i64);
        let result = self
            .base
            .request(Call::new(
                "POST",
                self.endpoint(),
                vec![
                    "Content-Type: application/json".into(),
                    format!("X-Username: {}", self.user),
                    format!("X-Key: {}", self.secret),
                ],
                Some(map([
                    ("message", text(sms.content())),
                    ("from", opt(sms.from())),
                    ("to", text(&sms.to().join(","))),
                ])),
            ))
            .await?;
        if result.status_code == 200 {
            response.set_delivered_to(sms.to().len() as i64);
            for to in sms.to() {
                response.add_success(to);
            }
        } else {
            for to in sms.to() {
                response.add_failure(to, "Unknown Error.");
            }
        }
        Ok(response)
    }
}

crate::adapter::configure!(Mock, |_| Http::anywhere("Mock"));

impl crate::adapter::Adapter for Mock {
    fn name(&self) -> &str {
        "Mock"
    }

    fn kind(&self) -> &str {
        TYPE
    }

    fn message_type(&self) -> crate::message::MessageType {
        crate::message::MessageType::Sms
    }

    fn max_messages_per_request(&self) -> i64 {
        1000
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(
        &'a self,
        message: &'a crate::message::Message,
    ) -> crate::http::BoxFuture<'a, Result<crate::response::Sent, Error>> {
        Box::pin(async move { self.send_sms(super::sms(message)).await.map(crate::response::Sent::Response) })
    }
}
