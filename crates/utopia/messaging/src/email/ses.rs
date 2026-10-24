use std::collections::HashSet;
use std::sync::Mutex;

use hmac::{Hmac, Mac};
use php_std::Value;
use sha2::{Digest, Sha256};

use super::{TYPE, email, format_address, mime};
use crate::adapter::{Adapter, Base, configure};
use crate::error::Error;
use crate::http::{BoxFuture, Call, Http, Reply};
use crate::message::{Email, Message, MessageType};
use crate::response::{Response, Sent};
use crate::values::{K, Site, at, list, map, result_text, text};

/// Amazon SES over the v2 API, signed with AWS Signature Version 4.
///
/// Without attachments, one `SendBulkEmail` request carries every
/// recipient, with the content registered once as a template named after
/// its hash (created on first use, never deleted). With attachments, one
/// `SendEmail` request per recipient carries a raw MIME message.
///
/// PHP: `Utopia\Messaging\Adapter\Email\SES`.
#[derive(Debug)]
pub struct Ses {
    access_key: String,
    secret_key: String,
    region: String,
    session_token: Option<String>,
    service: String,
    ensured: Mutex<HashSet<String>>,
    base: Base,
}

const NAME: &str = "SES";
const ALGORITHM: &str = "AWS4-HMAC-SHA256";
const STATUS_SUCCESS: &str = "SUCCESS";
const TEMPLATE_NAME_PREFIX: &str = "utopia-";
const TEMPLATE_NAME_MAX_LENGTH: usize = 64;

/// SES's cap on destinations per bulk request.
///
/// PHP: `SES::MAX_DESTINATIONS`.
pub const MAX_DESTINATIONS: i64 = 50;

/// SES's limit on a whole MIME message.
///
/// PHP: `SES::MAX_ATTACHMENT_BYTES`.
pub const MAX_ATTACHMENT_BYTES: u64 = 10 * 1024 * 1024;

impl Ses {
    /// Sends in `region` with long-lived credentials.
    ///
    /// PHP: `new SES($accessKey, $secretKey, $region)`.
    pub fn new(access_key: impl Into<String>, secret_key: impl Into<String>, region: impl Into<String>) -> Ses {
        Ses {
            access_key: access_key.into(),
            secret_key: secret_key.into(),
            region: region.into(),
            session_token: None,
            service: "ses".into(),
            ensured: Mutex::new(HashSet::new()),
            base: Base::new(Http::new(NAME)),
        }
    }

    /// With the session token of temporary credentials.
    ///
    /// PHP: the constructor's `$sessionToken`.
    pub fn with_session_token(mut self, token: Option<String>) -> Ses {
        self.session_token = token;
        self
    }

    /// Signs for another SigV4 service name than `ses` (AWS's test
    /// vectors use `service`).
    ///
    /// PHP: the protected `$service` property.
    pub fn with_service(mut self, service: impl Into<String>) -> Ses {
        self.service = service.into();
        self
    }

    fn token(&self) -> Option<&str> {
        self.session_token.as_deref().filter(|t| !matches!(*t, "" | "0"))
    }

    async fn send_email(&self, message: &Message) -> Result<Sent, Error> {
        let email = email(message);
        if email.attachments().is_some_and(|a| !a.is_empty()) {
            self.send_raw(email).await
        } else {
            self.send_bulk(email).await
        }
    }

    async fn send_bulk(&self, email: &Email) -> Result<Sent, Error> {
        let template = template_name(email);
        let formatted = |list: Option<&[crate::message::Mailbox]>| -> Vec<String> {
            list.unwrap_or_default().iter().map(|r| format_address(&r.email, r.name.as_deref())).collect()
        };
        let (cc, bcc) = (formatted(email.cc()), formatted(email.bcc()));
        let entries = list(email.to().iter().map(|to| {
            let mut destination = vec![("ToAddresses", list([text(&to.email)]))];
            if !cc.is_empty() {
                destination.push(("CcAddresses", list(cc.iter().map(|c| text(c)))));
            }
            if !bcc.is_empty() {
                destination.push(("BccAddresses", list(bcc.iter().map(|c| text(c)))));
            }
            map([
                ("Destination", map(destination)),
                (
                    "ReplacementEmailContent",
                    map([("ReplacementTemplate", map([("ReplacementTemplateData", text("{}"))]))]),
                ),
            ])
        }));

        let mut template_entries = vec![("TemplateName", text(&template)), ("TemplateData", text("{}"))];
        if !email.headers().is_empty() {
            template_entries.push((
                "Headers",
                list(email.headers().iter().map(|(n, v)| map([("Name", text(n)), ("Value", text(v))]))),
            ));
        }
        let mut body = vec![
            ("FromEmailAddress", text(&format_address(email.from_email(), Some(email.from_name())))),
            ("DefaultContent", map([("Template", map(template_entries))])),
            ("BulkEmailEntries", entries),
        ];
        if email.has_reply_to() {
            body.push((
                "ReplyToAddresses",
                list([text(&format_address(email.reply_to_email(), Some(email.reply_to_name())))]),
            ));
        }
        let body = map(body);

        let mut result = self.dispatch("POST", "/v2/email/outbound-bulk-emails", &body).await?;
        if is_template_missing(&result) {
            self.ensure_template(email, &template).await?;
            result = self.dispatch("POST", "/v2/email/outbound-bulk-emails", &body).await?;
        }
        self.parse_bulk(email, &result)
    }

    async fn send_raw(&self, email: &Email) -> Result<Sent, Error> {
        if mime::size(email)? > MAX_ATTACHMENT_BYTES {
            return Err(Error::Exception(format!("Total attachment size exceeds {MAX_ATTACHMENT_BYTES} bytes")));
        }
        let mut response = Response::new(TYPE);
        let mut delivered = 0;
        for to in email.to() {
            let raw =
                mime::message(email, std::slice::from_ref(to), email.cc().unwrap_or_default(), &[], &[])?.to_bytes()?;
            if raw.len() as u64 > MAX_ATTACHMENT_BYTES {
                return Err(Error::Exception(format!(
                    "MIME message size exceeds SES limit of {MAX_ATTACHMENT_BYTES} bytes"
                )));
            }
            let mut body = vec![
                ("FromEmailAddress", text(&format_address(email.from_email(), Some(email.from_name())))),
                ("Destination", map([("ToAddresses", list([text(&to.email)]))])),
                ("Content", map([("Raw", map([("Data", text(&php_std::encoding::base64_encode(&raw)))]))])),
            ];
            if email.has_reply_to() {
                body.push((
                    "ReplyToAddresses",
                    list([text(&format_address(email.reply_to_email(), Some(email.reply_to_name())))]),
                ));
            }
            let result = self.dispatch("POST", "/v2/email/outbound-emails", &map(body)).await?;
            if result.is_success() {
                response.add_success(&to.email);
                delivered += 1;
            } else {
                response.add_failure(&to.email, error_message(&result));
            }
        }
        response.set_delivered_to(delivered);
        Ok(Sent::Response(response))
    }

    fn parse_bulk(&self, email: &Email, result: &Reply) -> Result<Sent, Error> {
        let mut response = Response::new(TYPE);
        let recipients = email.to();
        if !result.is_success() {
            let error = error_message(result);
            for to in recipients {
                response.add_failure(&to.email, error.clone());
            }
            return Ok(Sent::Response(response));
        }
        let entries = match &result.response {
            Value::Array(body) => {
                body.get_str("BulkEmailEntryResults").filter(|v| matches!(v, Value::Array(_))).cloned()
            }
            _ => None,
        };
        let Some(entries) = entries else {
            for to in recipients {
                response.add_failure(&to.email, "SES returned a success status without per-recipient results");
            }
            return Ok(Sent::Response(response));
        };
        let mut delivered = 0;
        for (index, to) in recipients.iter().enumerate() {
            let entry = at(&entries, &[K::I(index as i64)]).filter(|e| matches!(e, Value::Array(_)));
            let status = entry.as_ref().and_then(|e| at(e, &[K::S("Status")]));
            if matches!(&status, Some(Value::Str(s)) if s.as_bytes() == STATUS_SUCCESS.as_bytes()) {
                response.add_success(&to.email);
                delivered += 1;
            } else {
                // ($entry['Error'] ?? null) ?: ($status ?? 'Unknown error')
                let error = entry.as_ref().and_then(|e| at(e, &[K::S("Error")])).filter(Value::truthy);
                let error = error.or(status).unwrap_or_else(|| text("Unknown error"));
                let error = result_text(&error, Site { argument: 2, file: "Adapter/Email/SES.php", line: 322 })?;
                response.add_failure(&to.email, error);
            }
        }
        response.set_delivered_to(delivered);
        Ok(Sent::Response(response))
    }

    async fn ensure_template(&self, email: &Email, name: &str) -> Result<(), Error> {
        if self.ensured.lock().unwrap_or_else(|e| e.into_inner()).contains(name) {
            return Ok(());
        }
        let content = if email.is_html() {
            map([("Subject", text(email.subject())), ("Html", text(email.content()))])
        } else {
            map([("Subject", text(email.subject())), ("Text", text(email.content()))])
        };
        let result = self
            .dispatch("POST", "/v2/email/templates", &map([("TemplateName", text(name)), ("TemplateContent", content)]))
            .await?;
        let created = result.is_success();
        let exists = error_type(&result).as_deref() == Some("AlreadyExistsException");
        if !created && !exists {
            return Err(Error::Exception(format!("SES failed to create email template: {}", error_message(&result))));
        }
        self.ensured.lock().unwrap_or_else(|e| e.into_inner()).insert(name.to_owned());
        Ok(())
    }

    async fn dispatch(&self, method: &str, path: &str, body: &Value) -> Result<Reply, Error> {
        let host = format!("email.{}.amazonaws.com", self.region);
        let payload = php_std::json::encode(body, php_std::json::Flags::THROW_ON_ERROR, php_std::json::DEFAULT_DEPTH)
            .map_err(|e| Error::Json(e.message().to_owned()))?;
        let mut headers = self.signature(method, &host, path, &payload);
        headers.push("Content-Type: application/json".into());
        self.base.request(Call::new(method, format!("https://{host}{path}"), headers, Some(body.clone()))).await
    }

    fn signature(&self, method: &str, host: &str, path: &str, payload: &str) -> Vec<String> {
        let amz_date =
            String::from_utf8_lossy(&php_std::datetime::gmdate(br"Ymd\THis\Z", php_std::datetime::time())).into_owned();
        let mut signed = vec![
            ("content-type".to_owned(), "application/json".to_owned()),
            ("host".to_owned(), host.to_owned()),
            ("x-amz-date".to_owned(), amz_date.clone()),
        ];
        if let Some(token) = self.token() {
            signed.push(("x-amz-security-token".to_owned(), token.to_owned()));
        }
        let authorization = self.sign(method, path, payload, &signed, &amz_date);
        let mut headers =
            vec![format!("Host: {host}"), format!("X-Amz-Date: {amz_date}"), format!("Authorization: {authorization}")];
        if let Some(token) = self.token() {
            headers.push(format!("X-Amz-Security-Token: {token}"));
        }
        headers
    }

    /// The SigV4 `Authorization` value for a request: canonical request,
    /// string to sign, signing key, signature. `signed_headers` are
    /// lower-case names with their values, in any order.
    ///
    /// PHP: the protected `sign()`.
    pub fn sign(
        &self,
        method: &str,
        path: &str,
        payload: &str,
        signed_headers: &[(String, String)],
        amz_date: &str,
    ) -> String {
        let mut sorted: Vec<&(String, String)> = signed_headers.iter().collect();
        sorted.sort_by(|a, b| a.0.as_bytes().cmp(b.0.as_bytes()));
        sorted.dedup_by(|a, b| a.0 == b.0);
        let mut canonical_headers = String::new();
        for (name, value) in &sorted {
            canonical_headers.push_str(name);
            canonical_headers.push(':');
            canonical_headers
                .push_str(&String::from_utf8_lossy(php_std::string::trim(value.as_bytes(), b" \t\n\r\0\x0B")));
            canonical_headers.push('\n');
        }
        let signed_list = sorted.iter().map(|(n, _)| n.as_str()).collect::<Vec<_>>().join(";");
        let canonical_request =
            [method, path, "", &canonical_headers, &signed_list, &hex::encode(Sha256::digest(payload.as_bytes()))]
                .join("\n");
        let date_stamp = amz_date.get(..8).unwrap_or(amz_date);
        let scope = format!("{date_stamp}/{}/{}/aws4_request", self.region, self.service);
        let string_to_sign =
            [ALGORITHM, amz_date, &scope, &hex::encode(Sha256::digest(canonical_request.as_bytes()))].join("\n");
        let key = hmac(
            &hmac(&hmac(&hmac(format!("AWS4{}", self.secret_key).as_bytes(), date_stamp), &self.region), &self.service),
            "aws4_request",
        );
        let signature = hex::encode(hmac(&key, &string_to_sign));
        format!(
            "{ALGORITHM} Credential={}/{scope}, SignedHeaders={signed_list}, Signature={signature}",
            self.access_key
        )
    }
}

fn hmac(key: &[u8], data: &str) -> Vec<u8> {
    let mut mac = Hmac::<Sha256>::new_from_slice(key).expect("HMAC takes any key length");
    mac.update(data.as_bytes());
    mac.finalize().into_bytes().to_vec()
}

/// `utopia-` and the start of the SHA-256 of subject, content and kind.
fn template_name(email: &Email) -> String {
    let mut hasher = Sha256::new();
    hasher.update(email.subject().as_bytes());
    hasher.update(b"\0");
    hasher.update(email.content().as_bytes());
    hasher.update(b"\0");
    hasher.update(if email.is_html() { b"1" } else { b"0" });
    let hash = hex::encode(hasher.finalize());
    format!("{TEMPLATE_NAME_PREFIX}{}", &hash[..TEMPLATE_NAME_MAX_LENGTH - TEMPLATE_NAME_PREFIX.len()])
}

fn is_template_missing(result: &Reply) -> bool {
    if matches!(error_type(result).as_deref(), Some("NotFoundException" | "BadRequestException")) {
        let message =
            String::from_utf8_lossy(&php_std::string::strtolower(error_message(result).as_bytes())).into_owned();
        if message.contains("template") && (message.contains("does not exist") || message.contains("not found")) {
            return true;
        }
    }
    if let Some(Value::Array(entries)) = at(&result.response, &[K::S("BulkEmailEntryResults")]) {
        for (_, entry) in entries.iter() {
            let status = match entry {
                Value::Array(_) => at(entry, &[K::S("Status")]),
                _ => None,
            };
            if matches!(&status, Some(Value::Str(s)) if matches!(s.as_bytes(), b"TEMPLATE_NOT_FOUND" | b"TEMPLATE_DOES_NOT_EXIST"))
            {
                return true;
            }
        }
    }
    false
}

/// PHP: `SES::errorMessage()`.
fn error_message(result: &Reply) -> String {
    if let Value::Array(body) = &result.response {
        for field in ["message", "Message"] {
            if let Some(Value::Str(s)) = body.get_str(field) {
                return String::from_utf8_lossy(s.as_bytes()).into_owned();
            }
        }
    }
    if let Value::Str(s) = &result.response
        && !s.is_empty()
    {
        return String::from_utf8_lossy(s.as_bytes()).into_owned();
    }
    if !result.error.is_empty() && result.error != "0" {
        return result.error.clone();
    }
    "Unknown error".into()
}

/// PHP: `SES::errorType()`.
fn error_type(result: &Reply) -> Option<String> {
    if let Some(header) = result.headers.get("x-amzn-errortype").filter(|h| !h.is_empty()) {
        let first = header.split(':').next().unwrap_or_default();
        return Some(String::from_utf8_lossy(php_std::string::trim(first.as_bytes(), b" \t\n\r\0\x0B")).into_owned());
    }
    if let Value::Array(body) = &result.response {
        let kind = match body.get_str("__type") {
            Some(Value::Null) | None => body.get_str("code"),
            found => found,
        };
        if let Some(Value::Str(s)) = kind {
            let text = String::from_utf8_lossy(s.as_bytes()).into_owned();
            return Some(text.rsplit('#').next().unwrap_or_default().to_owned());
        }
    }
    None
}

configure!(Ses, |_| Http::new(NAME));

impl Adapter for Ses {
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
        MAX_DESTINATIONS
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(self.send_email(message))
    }
}
