//! PHP `Utopia\Messaging\Adapter\Email\SES`.

use std::collections::HashMap;
use std::fmt::Write;

use base64::engine::general_purpose::STANDARD;
use base64::Engine;
use hmac::{Hmac, Mac};
use serde_json::{json, Value};
use sha2::{Digest, Sha256};

use super::mime::Mime;
use super::TYPE;
use crate::adapter::{expect_email, Adapter, AdapterBase, SendResult};
use crate::error::MessagingError;
use crate::http::HttpResult;
use crate::message::{Message, MessageKind};
use crate::messages::{Email, Recipient};
use crate::php::php_empty;
use crate::response::{Response, ResponseData};

type HmacSha256 = Hmac<Sha256>;

const ALGORITHM: &str = "AWS4-HMAC-SHA256";
const MAX_DESTINATIONS: usize = 50;
const MAX_ATTACHMENT_BYTES: u64 = 10 * 1024 * 1024;
const STATUS_SUCCESS: &str = "SUCCESS";
const TEMPLATE_NAME_PREFIX: &str = "utopia-";
const TEMPLATE_NAME_MAX_LENGTH: usize = 64;

/// PHP `Adapter\Email\SES`.
#[derive(Debug)]
pub struct SES {
    base: AdapterBase,
    access_key: String,
    secret_key: String,
    region: String,
    session_token: Option<String>,
    /// `SigV4` service name (PHP `$service`, default `ses`; tests pin `service`).
    service: String,
    ensured_templates: parking_lot::Mutex<HashMap<String, bool>>,
}

impl SES {
    /// PHP `__construct($accessKey, $secretKey, $region, $sessionToken = null)`.
    #[must_use]
    pub fn new(
        access_key: impl Into<String>,
        secret_key: impl Into<String>,
        region: impl Into<String>,
        session_token: Option<String>,
    ) -> Self {
        Self {
            base: AdapterBase::default(),
            access_key: access_key.into(),
            secret_key: secret_key.into(),
            region: region.into(),
            session_token,
            service: "ses".into(),
            ensured_templates: parking_lot::Mutex::new(HashMap::new()),
        }
    }

    /// Pin the `SigV4` service name (PHP protected `$service`).
    #[must_use]
    pub fn with_service(mut self, service: impl Into<String>) -> Self {
        self.service = service.into();
        self
    }

    /// PHP protected `sign()` - exposed so `SESSigningTest` can check AWS vectors.
    #[must_use]
    pub fn sign(
        &self,
        method: &str,
        path: &str,
        payload: &str,
        signed_headers: &HashMap<String, String>,
        amz_date: &str,
    ) -> String {
        let mut headers: Vec<(String, String)> = signed_headers
            .iter()
            .map(|(k, v)| (k.clone(), v.clone()))
            .collect();
        headers.sort_by(|a, b| a.0.cmp(&b.0));

        let mut canonical_headers = String::new();
        for (name, value) in &headers {
            let _ = writeln!(canonical_headers, "{name}:{}", value.trim());
        }
        let signed_header_list = headers
            .iter()
            .map(|(k, _)| k.as_str())
            .collect::<Vec<_>>()
            .join(";");

        let canonical_request = format!(
            "{method}\n{path}\n\n{canonical_headers}\n{signed_header_list}\n{}",
            sha256_hex(payload.as_bytes())
        );
        let date_stamp = &amz_date[..8];
        let credential_scope =
            format!("{date_stamp}/{}/{}/aws4_request", self.region, self.service);
        let string_to_sign = format!(
            "{ALGORITHM}\n{amz_date}\n{credential_scope}\n{}",
            sha256_hex(canonical_request.as_bytes())
        );
        let signing_key = self.signing_key(date_stamp);
        let signature = hmac_hex(&signing_key, string_to_sign.as_bytes());
        format!(
            "{ALGORITHM} Credential={}/{}, SignedHeaders={signed_header_list}, Signature={signature}",
            self.access_key, credential_scope
        )
    }

    fn signing_key(&self, date_stamp: &str) -> Vec<u8> {
        let k_date = hmac_raw(
            format!("AWS4{}", self.secret_key).as_bytes(),
            date_stamp.as_bytes(),
        );
        let k_region = hmac_raw(&k_date, self.region.as_bytes());
        let k_service = hmac_raw(&k_region, self.service.as_bytes());
        hmac_raw(&k_service, b"aws4_request")
    }

    fn process_email(&self, message: &Email) -> Result<ResponseData, MessagingError> {
        let mut response = Response::new(TYPE);
        let has_attachments = message.get_attachments().is_some_and(|a| !a.is_empty());
        if has_attachments {
            self.send_raw(message, &mut response)
        } else {
            self.send_bulk(message, &mut response)
        }
    }

    fn send_bulk(
        &self,
        message: &Email,
        response: &mut Response,
    ) -> Result<ResponseData, MessagingError> {
        let template_name = self.template_name(message);
        let cc: Vec<String> = message
            .get_cc()
            .unwrap_or(&[])
            .iter()
            .map(|r| format_address(&r.email, r.name.as_deref()))
            .collect();
        let bcc: Vec<String> = message
            .get_bcc()
            .unwrap_or(&[])
            .iter()
            .map(|r| format_address(&r.email, r.name.as_deref()))
            .collect();

        let entries: Vec<Value> = message
            .get_to()
            .iter()
            .map(|to| {
                let mut destination = json!({"ToAddresses": [to.email.clone()]});
                if !cc.is_empty() {
                    destination["CcAddresses"] = json!(cc);
                }
                if !bcc.is_empty() {
                    destination["BccAddresses"] = json!(bcc);
                }
                json!({
                    "Destination": destination,
                    "ReplacementEmailContent": {
                        "ReplacementTemplate": { "ReplacementTemplateData": "{}" }
                    }
                })
            })
            .collect();

        let mut body = json!({
            "FromEmailAddress": format_address(message.get_from_email(), Some(message.get_from_name())),
            "DefaultContent": {
                "Template": {
                    "TemplateName": template_name,
                    "TemplateData": "{}",
                }
            },
            "BulkEmailEntries": entries,
        });
        if !php_empty(Some(message.get_reply_to_email())) {
            body["ReplyToAddresses"] = json!([format_address(
                message.get_reply_to_email(),
                Some(message.get_reply_to_name())
            )]);
        }

        let mut result = self.dispatch("POST", "/v2/email/outbound-bulk-emails", &body)?;
        if self.is_template_missing(&result) {
            self.ensure_template(message, &template_name)?;
            result = self.dispatch("POST", "/v2/email/outbound-bulk-emails", &body)?;
        }
        Ok(self.parse_bulk_result(message, &result, response))
    }

    fn send_raw(
        &self,
        message: &Email,
        response: &mut Response,
    ) -> Result<ResponseData, MessagingError> {
        self.assert_attachment_size(message)?;
        let mut delivered = 0i64;
        for to in message.get_to() {
            let mime = self.build_mime(message, to);
            if mime.len() as u64 > MAX_ATTACHMENT_BYTES {
                return Err(MessagingError::message(format!(
                    "MIME message size exceeds SES limit of {MAX_ATTACHMENT_BYTES} bytes"
                )));
            }
            let mut body = json!({
                "FromEmailAddress": format_address(message.get_from_email(), Some(message.get_from_name())),
                "Destination": { "ToAddresses": [to.email.clone()] },
                "Content": { "Raw": { "Data": STANDARD.encode(mime.as_bytes()) } },
            });
            if !php_empty(Some(message.get_reply_to_email())) {
                body["ReplyToAddresses"] = json!([format_address(
                    message.get_reply_to_email(),
                    Some(message.get_reply_to_name())
                )]);
            }
            let result = self.dispatch("POST", "/v2/email/outbound-emails", &body)?;
            if (200..300).contains(&result.status_code) {
                response.add_result(&to.email, "");
                delivered += 1;
            } else {
                response.add_result(&to.email, error_message(&result));
            }
        }
        response.set_delivered_to(delivered);
        Ok(response.to_array())
    }

    fn parse_bulk_result(
        &self,
        message: &Email,
        result: &HttpResult,
        response: &mut Response,
    ) -> ResponseData {
        let recipients = message.get_to();
        if !(200..300).contains(&result.status_code) {
            let error = error_message(result);
            for to in recipients {
                response.add_result(&to.email, error.clone());
            }
            return response.to_array();
        }
        let entry_results = result.response.get("BulkEmailEntryResults");
        let Some(entries) = entry_results.and_then(Value::as_array) else {
            for to in recipients {
                response.add_result(
                    &to.email,
                    "SES returned a success status without per-recipient results",
                );
            }
            return response.to_array();
        };
        let mut delivered = 0i64;
        for (index, to) in recipients.iter().enumerate() {
            let entry = entries.get(index);
            let status = entry.and_then(|e| e.get("Status")).and_then(Value::as_str);
            if status == Some(STATUS_SUCCESS) {
                response.add_result(&to.email, "");
                delivered += 1;
            } else {
                let error = entry
                    .and_then(|e| e.get("Error"))
                    .and_then(Value::as_str)
                    .filter(|s| !s.is_empty())
                    .map(str::to_owned)
                    .or_else(|| status.map(str::to_owned))
                    .unwrap_or_else(|| "Unknown error".into());
                response.add_result(&to.email, error);
            }
        }
        response.set_delivered_to(delivered);
        response.to_array()
    }

    fn ensure_template(&self, message: &Email, template_name: &str) -> Result<(), MessagingError> {
        if self.ensured_templates.lock().contains_key(template_name) {
            return Ok(());
        }
        let content = if message.is_html() {
            json!({"Subject": message.get_subject(), "Html": message.get_content()})
        } else {
            json!({"Subject": message.get_subject(), "Text": message.get_content()})
        };
        let result = self.dispatch(
            "POST",
            "/v2/email/templates",
            &json!({
                "TemplateName": template_name,
                "TemplateContent": content,
            }),
        )?;
        let created = (200..300).contains(&result.status_code);
        let already = error_type(&result).as_deref() == Some("AlreadyExistsException");
        if !created && !already {
            return Err(MessagingError::message(format!(
                "SES failed to create email template: {}",
                error_message(&result)
            )));
        }
        self.ensured_templates
            .lock()
            .insert(template_name.to_string(), true);
        Ok(())
    }

    fn template_name(&self, message: &Email) -> String {
        let mut hasher = Sha256::new();
        hasher.update(message.get_subject().as_bytes());
        hasher.update([0]);
        hasher.update(message.get_content().as_bytes());
        hasher.update([0]);
        hasher.update(if message.is_html() { b"1" } else { b"0" });
        let hash = hex::encode(hasher.finalize());
        let hash_len = TEMPLATE_NAME_MAX_LENGTH - TEMPLATE_NAME_PREFIX.len();
        format!(
            "{TEMPLATE_NAME_PREFIX}{}",
            &hash[..hash_len.min(hash.len())]
        )
    }

    fn is_template_missing(&self, result: &HttpResult) -> bool {
        let err = error_type(result);
        if err.as_deref() == Some("NotFoundException")
            || err.as_deref() == Some("BadRequestException")
        {
            let message = error_message(result).to_ascii_lowercase();
            if message.contains("template")
                && (message.contains("does not exist") || message.contains("not found"))
            {
                return true;
            }
        }
        if let Some(entries) = result
            .response
            .get("BulkEmailEntryResults")
            .and_then(Value::as_array)
        {
            for entry in entries {
                let status = entry.get("Status").and_then(Value::as_str);
                if status == Some("TEMPLATE_NOT_FOUND") || status == Some("TEMPLATE_DOES_NOT_EXIST")
                {
                    return true;
                }
            }
        }
        false
    }

    fn build_mime(&self, message: &Email, to: &Recipient) -> String {
        Mime::message(
            message,
            std::slice::from_ref(to),
            message.get_cc().unwrap_or(&[]),
            &[],
            &[],
        )
        .to_string()
    }

    fn assert_attachment_size(&self, message: &Email) -> Result<(), MessagingError> {
        if Mime::size(message)? > MAX_ATTACHMENT_BYTES {
            return Err(MessagingError::message(format!(
                "Total attachment size exceeds {MAX_ATTACHMENT_BYTES} bytes"
            )));
        }
        Ok(())
    }

    fn dispatch(
        &self,
        method: &str,
        path: &str,
        body: &Value,
    ) -> Result<HttpResult, MessagingError> {
        let host = format!("email.{}.amazonaws.com", self.region);
        let payload =
            serde_json::to_string(body).map_err(|e| MessagingError::message(e.to_string()))?;
        let mut headers = self.signature(method, &host, path, &payload);
        headers.push("Content-Type: application/json".into());
        Ok(self.request_default(
            method,
            &format!("https://{host}{path}"),
            &headers,
            Some(body.clone()),
        ))
    }

    fn signature(&self, method: &str, host: &str, path: &str, payload: &str) -> Vec<String> {
        let amz_date = {
            let now = time::OffsetDateTime::now_utc();
            format!(
                "{:04}{:02}{:02}T{:02}{:02}{:02}Z",
                now.year(),
                now.month() as u8,
                now.day(),
                now.hour(),
                now.minute(),
                now.second()
            )
        };
        let mut signed = HashMap::new();
        signed.insert("content-type".into(), "application/json".into());
        signed.insert("host".into(), host.to_string());
        signed.insert("x-amz-date".into(), amz_date.clone());
        if !php_empty(self.session_token.as_deref()) {
            signed.insert(
                "x-amz-security-token".into(),
                self.session_token.clone().unwrap_or_default(),
            );
        }
        let authorization = self.sign(method, path, payload, &signed, &amz_date);
        let mut headers = vec![
            format!("Host: {host}"),
            format!("X-Amz-Date: {amz_date}"),
            format!("Authorization: {authorization}"),
        ];
        if !php_empty(self.session_token.as_deref()) {
            headers.push(format!(
                "X-Amz-Security-Token: {}",
                self.session_token.as_deref().unwrap_or("")
            ));
        }
        headers
    }
}

fn format_address(email: &str, name: Option<&str>) -> String {
    if php_empty(name) {
        return email.to_string();
    }
    let name = name.unwrap_or("");
    let quoted = if name.chars().any(|c| {
        matches!(
            c,
            ',' | ';' | ':' | '@' | '<' | '>' | '(' | ')' | '[' | ']' | '\\' | '"' | '.'
        )
    }) {
        let escaped = name.replace('\\', "\\\\").replace('"', "\\\"");
        format!("\"{escaped}\"")
    } else {
        name.to_string()
    };
    format!("{quoted} <{email}>")
}

fn error_message(result: &HttpResult) -> String {
    let body = &result.response;
    if let Some(m) = body.get("message").and_then(Value::as_str) {
        return m.to_string();
    }
    if let Some(m) = body.get("Message").and_then(Value::as_str) {
        return m.to_string();
    }
    if let Some(s) = body.as_str() {
        if !s.is_empty() {
            return s.to_string();
        }
    }
    if !result.error.is_empty() {
        return result.error.clone();
    }
    "Unknown error".into()
}

fn error_type(result: &HttpResult) -> Option<String> {
    if let Some(header) = result.headers.get("x-amzn-errortype") {
        if !header.is_empty() {
            return Some(
                header
                    .split(':')
                    .next()
                    .unwrap_or(header)
                    .trim()
                    .to_string(),
            );
        }
    }
    let body = &result.response;
    if let Some(t) = body
        .get("__type")
        .or_else(|| body.get("code"))
        .and_then(Value::as_str)
    {
        return Some(t.split('#').next_back().unwrap_or(t).to_string());
    }
    None
}

fn sha256_hex(data: &[u8]) -> String {
    hex::encode(Sha256::digest(data))
}

fn hmac_raw(key: &[u8], data: &[u8]) -> Vec<u8> {
    let mut mac = HmacSha256::new_from_slice(key).expect("hmac");
    mac.update(data);
    mac.finalize().into_bytes().to_vec()
}

fn hmac_hex(key: &[u8], data: &[u8]) -> String {
    hex::encode(hmac_raw(key, data))
}

impl Adapter for SES {
    fn get_name(&self) -> &'static str {
        "SES"
    }
    fn get_type(&self) -> &'static str {
        TYPE
    }
    fn get_message_type(&self) -> MessageKind {
        MessageKind::Email
    }
    fn get_max_messages_per_request(&self) -> usize {
        MAX_DESTINATIONS
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
