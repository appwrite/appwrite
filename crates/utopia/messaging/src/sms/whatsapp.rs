use php_std::Value;
use php_std::encoding::{QueryEncoding, http_build_query};

use super::{TYPE, sms_adapter};
use crate::adapter::Base;
use crate::error::Error;
use crate::http::{Call, Http, Reply};
use crate::message::{Sms, pcre_end};
use crate::response::Response;
use crate::values::{K, at, entries, interpolate, list, map, text};

/// An Android app that may receive the code through one-tap or zero-tap
/// autofill.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\WhatsApp\App`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct App {
    /// The Android application ID (`com.example.app`).
    pub package_name: String,
    /// The eleven-character hash of the app signing certificate.
    pub signature_hash: String,
}

impl App {
    /// The longest package name Meta accepts.
    pub const PACKAGE_NAME_MAX_LENGTH: usize = 224;
    /// The exact length of a signature hash.
    pub const SIGNATURE_HASH_LENGTH: usize = 11;

    /// Fails with [`Error::InvalidArgument`] for an empty or too long
    /// package name, or a hash that is not eleven bytes.
    ///
    /// PHP: `new App($packageName, $signatureHash)`.
    pub fn new(package_name: impl Into<String>, signature_hash: impl Into<String>) -> Result<App, Error> {
        let (package_name, signature_hash) = (package_name.into(), signature_hash.into());
        if package_name.is_empty() || package_name.len() > App::PACKAGE_NAME_MAX_LENGTH {
            return Err(Error::InvalidArgument(format!(
                "WhatsApp app package name must be between 1 and {} characters.",
                App::PACKAGE_NAME_MAX_LENGTH
            )));
        }
        if signature_hash.len() != App::SIGNATURE_HASH_LENGTH {
            return Err(Error::InvalidArgument(format!(
                "WhatsApp app signature hash must be exactly {} characters.",
                App::SIGNATURE_HASH_LENGTH
            )));
        }
        Ok(App { package_name, signature_hash })
    }

    /// `{package_name, signature_hash}`, as Meta takes it.
    ///
    /// PHP: `toArray()`.
    pub fn to_array(&self) -> Value {
        map([("package_name", text(&self.package_name)), ("signature_hash", text(&self.signature_hash))])
    }
}

/// How the recipient moves the code into the app.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\WhatsApp\OtpType`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum OtpType {
    /// A button copies the code.
    #[default]
    CopyCode,
    /// A button hands the code to the Android app.
    OneTap,
    /// WhatsApp hands the code to the Android app without a tap.
    ZeroTap,
}

impl OtpType {
    /// Every case, in declaration order.
    pub const ALL: [OtpType; 3] = [OtpType::CopyCode, OtpType::OneTap, OtpType::ZeroTap];

    /// The value Meta takes (`copy_code`).
    pub fn value(self) -> &'static str {
        match self {
            OtpType::CopyCode => "copy_code",
            OtpType::OneTap => "one_tap",
            OtpType::ZeroTap => "zero_tap",
        }
    }

    /// The case name (`COPY_CODE`).
    pub fn name(self) -> &'static str {
        match self {
            OtpType::CopyCode => "COPY_CODE",
            OtpType::OneTap => "ONE_TAP",
            OtpType::ZeroTap => "ZERO_TAP",
        }
    }

    /// Whether Meta requires the template to name the apps that receive the
    /// code.
    ///
    /// PHP: `requiresApps()`.
    pub fn requires_apps(self) -> bool {
        self != OtpType::CopyCode
    }
}

/// The metadata a WhatsApp send reads.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\WhatsApp\MetadataParameter`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum WhatsAppParameter {
    /// The template language for one message (`language`).
    Language,
    /// The template for one message (`template`).
    Template,
    /// A string Meta echoes in status webhooks (`callbackData`).
    CallbackData,
}

impl WhatsAppParameter {
    /// Every case, in declaration order.
    pub const ALL: [WhatsAppParameter; 3] =
        [WhatsAppParameter::Language, WhatsAppParameter::Template, WhatsAppParameter::CallbackData];

    /// The metadata key.
    pub fn value(self) -> &'static str {
        match self {
            WhatsAppParameter::Language => "language",
            WhatsAppParameter::Template => "template",
            WhatsAppParameter::CallbackData => "callbackData",
        }
    }

    /// The case name.
    pub fn name(self) -> &'static str {
        match self {
            WhatsAppParameter::Language => "LANGUAGE",
            WhatsAppParameter::Template => "TEMPLATE",
            WhatsAppParameter::CallbackData => "CALLBACK_DATA",
        }
    }
}

/// One-time passcodes over WhatsApp, with a Meta Cloud API authentication
/// template: the message content is the code itself.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\WhatsApp`.
#[derive(Debug)]
pub struct WhatsApp {
    access_token: String,
    phone_number_id: String,
    template: String,
    language: String,
    version: String,
    base: Base,
}

impl WhatsApp {
    /// The template language unless another is given.
    pub const DEFAULT_LANGUAGE: &'static str = "en_US";
    /// The Graph API version unless another is given.
    pub const DEFAULT_VERSION: &'static str = "v26.0";
    /// The longest code a template accepts.
    pub const CODE_MAX_LENGTH: usize = 15;
    /// The longest validity a template footer states, in minutes.
    pub const EXPIRATION_MAX_MINUTES: i64 = 90;
    /// The shortest time an undelivered message may wait, in seconds.
    pub const TIME_TO_LIVE_MIN_SECONDS: i64 = 60;
    /// The longest time an undelivered message may wait, in seconds.
    pub const TIME_TO_LIVE_MAX_SECONDS: i64 = 600;
    /// Keeps an undelivered message for a day.
    pub const TIME_TO_LIVE_DAY: i64 = -1;
    /// The longest callback data Meta echoes back.
    pub const CALLBACK_DATA_MAX_LENGTH: usize = 512;
    /// The longest template name.
    pub const TEMPLATE_NAME_MAX_LENGTH: usize = 512;
    /// The most apps one template names.
    pub const APPS_MAX: usize = 5;

    /// Sends codes from `phone_number_id` with the authentication template
    /// `template`.
    ///
    /// Fails with [`Error::InvalidArgument`] for a template name that is not
    /// lower-case letters, digits and underscores.
    ///
    /// PHP: `new WhatsApp($accessToken, $phoneNumberId, $template)`.
    pub fn new(
        access_token: impl Into<String>,
        phone_number_id: impl Into<String>,
        template: impl Into<String>,
    ) -> Result<WhatsApp, Error> {
        let template = template.into();
        assert_template_name(&template)?;
        Ok(WhatsApp {
            access_token: access_token.into(),
            phone_number_id: phone_number_id.into(),
            template,
            language: WhatsApp::DEFAULT_LANGUAGE.into(),
            version: WhatsApp::DEFAULT_VERSION.into(),
            base: Base::new(Http::new("WhatsApp")),
        })
    }

    /// The template language the template was created in.
    pub fn with_language(mut self, language: impl Into<String>) -> WhatsApp {
        self.language = language.into();
        self
    }

    /// The Graph API version.
    pub fn with_version(mut self, version: impl Into<String>) -> WhatsApp {
        self.version = version.into();
        self
    }

    fn url(&self, id: &str, edge: &str) -> String {
        format!("https://graph.facebook.com/{}/{id}/{edge}", self.version)
    }

    fn headers(&self) -> Vec<String> {
        vec!["Content-Type: application/json".into(), format!("Authorization: Bearer {}", self.access_token)]
    }

    /// Creates or updates the template in every language given (every
    /// supported one when none is).
    ///
    /// Fails with [`Error::InvalidArgument`] for an option Meta refuses, and
    /// [`Error::Runtime`] when the Graph API does.
    ///
    /// PHP: `upsertTemplate()`.
    #[allow(clippy::too_many_arguments)]
    pub async fn upsert_template(
        &self,
        business_account_id: &str,
        languages: &[String],
        expiration_minutes: Option<i64>,
        security_recommendation: bool,
        time_to_live: Option<i64>,
        otp_type: OtpType,
        apps: &[App],
    ) -> Result<Value, Error> {
        if let Some(minutes) = expiration_minutes
            && !(1..=WhatsApp::EXPIRATION_MAX_MINUTES).contains(&minutes)
        {
            return Err(Error::InvalidArgument(format!(
                "WhatsApp code expiration must be between 1 and {} minutes.",
                WhatsApp::EXPIRATION_MAX_MINUTES
            )));
        }
        if let Some(ttl) = time_to_live
            && ttl != WhatsApp::TIME_TO_LIVE_DAY
            && !(WhatsApp::TIME_TO_LIVE_MIN_SECONDS..=WhatsApp::TIME_TO_LIVE_MAX_SECONDS).contains(&ttl)
        {
            return Err(Error::InvalidArgument(format!(
                "WhatsApp time to live must be between {} and {} seconds, or {} for 24 hours.",
                WhatsApp::TIME_TO_LIVE_MIN_SECONDS,
                WhatsApp::TIME_TO_LIVE_MAX_SECONDS,
                WhatsApp::TIME_TO_LIVE_DAY
            )));
        }
        if otp_type.requires_apps() && apps.is_empty() {
            return Err(Error::InvalidArgument(format!(
                "WhatsApp {} templates must name at least one app that receives the code.",
                otp_type.value()
            )));
        }
        if apps.len() > WhatsApp::APPS_MAX {
            return Err(Error::InvalidArgument(format!(
                "WhatsApp templates may name at most {} apps.",
                WhatsApp::APPS_MAX
            )));
        }

        let mut components =
            vec![map([("type", text("body")), ("add_security_recommendation", Value::Bool(security_recommendation))])];
        if let Some(minutes) = expiration_minutes {
            components.push(map([("type", text("footer")), ("code_expiration_minutes", Value::Int(minutes))]));
        }
        let mut button = vec![("type", text("otp")), ("otp_type", text(otp_type.value()))];
        if !apps.is_empty() {
            button.push(("supported_apps", list(apps.iter().map(App::to_array))));
        }
        if otp_type == OtpType::ZeroTap {
            button.push(("zero_tap_terms_accepted", Value::Bool(true)));
        }
        components.push(map([("type", text("buttons")), ("buttons", list([map(button)]))]));

        let mut body = vec![
            ("name", text(&self.template)),
            ("category", text("authentication")),
            ("components", list(components)),
        ];
        if !languages.is_empty() {
            body.push(("languages", list(languages.iter().map(|l| text(l)))));
        }
        if let Some(ttl) = time_to_live {
            body.push(("message_send_ttl_seconds", Value::Int(ttl)));
        }
        let result = self
            .base
            .request(Call::new(
                "POST",
                self.url(business_account_id, "upsert_message_templates"),
                self.headers(),
                Some(map(body)),
            ))
            .await?;
        if !result.is_success() {
            return Err(Error::Runtime(error(&result)));
        }
        Ok(match result.response {
            Value::Array(_) => result.response,
            _ => Value::Array(php_std::Array::new()),
        })
    }

    /// Every language of a template (the adapter's unless `name` is given)
    /// with its status: `{id, name, language, status, category}` each.
    ///
    /// PHP: `getTemplate()`.
    pub async fn template(&self, business_account_id: &str, name: Option<&str>) -> Result<Vec<Value>, Error> {
        let name = name.unwrap_or(&self.template);
        assert_template_name(name)?;
        let query = query(&[("name", name), ("fields", "id,name,language,status,category")]);
        let url = format!("{}?{query}", self.url(business_account_id, "message_templates"));
        let result = self.base.request(Call::new("GET", url, self.headers(), None)).await?;
        if !result.is_success() {
            return Err(Error::Runtime(error(&result)));
        }
        let data = match &result.response {
            Value::Array(_) => at(&result.response, &[K::S("data")]).unwrap_or(Value::Array(php_std::Array::new())),
            _ => Value::Array(php_std::Array::new()),
        };
        Ok(entries(&data).into_iter().map(|(_, v)| v).collect())
    }

    /// Deletes a template (the adapter's unless `name` is given) in every
    /// language.
    ///
    /// PHP: `deleteTemplate()`.
    pub async fn delete_template(&self, business_account_id: &str, name: Option<&str>) -> Result<(), Error> {
        let name = name.unwrap_or(&self.template);
        assert_template_name(name)?;
        let url = format!("{}?{}", self.url(business_account_id, "message_templates"), query(&[("name", name)]));
        let result = self.base.request(Call::new("DELETE", url, self.headers(), None)).await?;
        if !result.is_success() {
            return Err(Error::Runtime(error(&result)));
        }
        Ok(())
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let code = sms.content();
        let body = pcre_end(code.as_bytes());
        if body.is_empty() || body.len() > WhatsApp::CODE_MAX_LENGTH || !body.iter().all(u8::is_ascii_alphanumeric) {
            return Err(Error::InvalidArgument(format!(
                "WhatsApp authentication templates only accept a code of up to {} letters and digits as the message content.",
                WhatsApp::CODE_MAX_LENGTH
            )));
        }
        let mut metadata: Vec<(&'static str, String)> = Vec::new();
        for (key, value) in entries(sms.metadata().unwrap_or(&Value::Null)) {
            let key = match &key {
                php_std::types::ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                php_std::types::ArrayKey::Int(i) => i.to_string(),
            };
            let Some(parameter) = WhatsAppParameter::ALL.into_iter().find(|p| p.value() == key) else { continue };
            match value {
                Value::Str(s) if !s.is_empty() => {
                    metadata.push((parameter.value(), String::from_utf8_lossy(s.as_bytes()).into_owned()))
                }
                _ => {
                    return Err(Error::InvalidArgument(format!("WhatsApp {key} metadata must be a non-empty string.")));
                }
            }
        }
        let find = |p: WhatsAppParameter| metadata.iter().find(|(k, _)| *k == p.value()).map(|(_, v)| v.as_str());
        let language = find(WhatsAppParameter::Language).unwrap_or(&self.language);
        let template = find(WhatsAppParameter::Template).unwrap_or(&self.template);
        let callback = find(WhatsAppParameter::CallbackData);
        assert_template_name(template)?;
        if callback.is_some_and(|c| c.len() > WhatsApp::CALLBACK_DATA_MAX_LENGTH) {
            return Err(Error::InvalidArgument(format!(
                "WhatsApp callback data must be at most {} characters.",
                WhatsApp::CALLBACK_DATA_MAX_LENGTH
            )));
        }
        let to = match sms.to().first() {
            Some(to) if !to.is_empty() => to,
            _ => return Err(Error::InvalidArgument("WhatsApp requires exactly one recipient phone number.".into())),
        };
        let parameter = || list([map([("type", text("text")), ("text", text(code))])]);
        let mut body = vec![
            ("messaging_product", text("whatsapp")),
            ("recipient_type", text("individual")),
            ("to", text(&to.chars().filter(char::is_ascii_digit).collect::<String>())),
            ("type", text("template")),
            (
                "template",
                map([
                    ("name", text(template)),
                    ("language", map([("code", text(language))])),
                    (
                        "components",
                        list([
                            map([("type", text("body")), ("parameters", parameter())]),
                            map([
                                ("type", text("button")),
                                ("sub_type", text("url")),
                                ("index", text("0")),
                                ("parameters", parameter()),
                            ]),
                        ]),
                    ),
                ]),
            ),
        ];
        if let Some(callback) = callback {
            body.push(("biz_opaque_callback_data", text(callback)));
        }
        let result = self
            .base
            .request(Call::new("POST", self.url(&self.phone_number_id, "messages"), self.headers(), Some(map(body))))
            .await?;
        let mut response = Response::new(TYPE);
        if result.is_success() {
            response.set_delivered_to(1);
            response.add_success(to);
        } else {
            response.add_failure(to, error(&result));
        }
        Ok(response)
    }
}

fn assert_template_name(name: &str) -> Result<(), Error> {
    let body = pcre_end(name.as_bytes());
    if body.is_empty()
        || body.len() > WhatsApp::TEMPLATE_NAME_MAX_LENGTH
        || !body.iter().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || *b == b'_')
    {
        return Err(Error::InvalidArgument(format!(
            "WhatsApp template names must be 1 to {} lowercase letters, digits and underscores.",
            WhatsApp::TEMPLATE_NAME_MAX_LENGTH
        )));
    }
    Ok(())
}

/// `http_build_query()` of string pairs (spaces as `+`).
fn query(pairs: &[(&str, &str)]) -> String {
    let data =
        serde_json::Value::Object(pairs.iter().map(|(k, v)| ((*k).to_owned(), serde_json::Value::from(*v))).collect());
    String::from_utf8_lossy(&http_build_query(&data, b"", None, QueryEncoding::Rfc1738)).into_owned()
}

/// A Graph API error in one line, keeping the numeric code.
fn error(result: &Reply) -> String {
    if result.status_code == 0 {
        return if result.error.is_empty() { "Unknown error".into() } else { result.error.clone() };
    }
    let error = match &result.response {
        Value::Array(_) => at(&result.response, &[K::S("error")]).filter(|e| matches!(e, Value::Array(_))),
        _ => None,
    };
    let Some(error) = error else { return "Unknown error".into() };
    let mut parts: Vec<String> = Vec::new();
    if let Some(code) = at(&error, &[K::S("code")]) {
        parts.push(format!("Error {}", interpolate(&code)));
    }
    if let Some(Value::Str(message)) = at(&error, &[K::S("message")]) {
        parts.push(String::from_utf8_lossy(message.as_bytes()).into_owned());
    }
    if let Some(Value::Str(details)) = at(&error, &[K::S("error_data"), K::S("details")]) {
        let details = String::from_utf8_lossy(details.as_bytes()).into_owned();
        if !details.is_empty() && !parts.contains(&details) {
            parts.push(details);
        }
    }
    if parts.is_empty() { "Unknown error".into() } else { parts.join(": ") }
}

sms_adapter!(WhatsApp, "WhatsApp", 1);
