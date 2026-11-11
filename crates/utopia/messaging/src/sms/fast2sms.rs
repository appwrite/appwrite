use php_std::Value;

use super::{TYPE, all, calling_code, sms_adapter};
use crate::adapter::Base;
use crate::error::Error;
use crate::http::{Call, Http};
use crate::message::Sms;
use crate::response::Response;
use crate::values::{K, Site, at, interpolate, map, result_text, text};

/// Fast2SMS's bulk API for Indian numbers: the Quick SMS route, or the
/// DLT route with an approved sender and template.
///
/// PHP: `Utopia\Messaging\Adapter\SMS\Fast2SMS`.
#[derive(Debug)]
pub struct Fast2Sms {
    api_key: String,
    sender_id: String,
    message_id: String,
    use_dlt: bool,
    base: Base,
}

const ENDPOINT: &str = "https://www.fast2sms.com/dev/bulkV2";

impl Fast2Sms {
    /// The Quick SMS route.
    ///
    /// PHP: `new Fast2SMS($apiKey)`.
    pub fn new(api_key: impl Into<String>) -> Fast2Sms {
        Fast2Sms {
            api_key: api_key.into(),
            sender_id: String::new(),
            message_id: String::new(),
            use_dlt: false,
            base: Base::new(Http::new("Fast2SMS")),
        }
    }

    /// The DLT route: an approved sender ID and message template, the
    /// message content being the template's variables.
    ///
    /// PHP: `new Fast2SMS($apiKey, $senderId, $messageId, $useDLT)`.
    pub fn with_dlt(mut self, sender_id: impl Into<String>, message_id: impl Into<String>, use_dlt: bool) -> Fast2Sms {
        self.sender_id = sender_id.into();
        self.message_id = message_id.into();
        self.use_dlt = use_dlt;
        self
    }

    async fn send_sms(&self, sms: &Sms) -> Result<Response, Error> {
        let numbers = sms.to().iter().map(|n| remove_country_code(n)).collect::<Vec<_>>().join(",");
        let mut payload = vec![("numbers", text(&numbers)), ("flash", Value::Int(0))];
        if self.use_dlt {
            payload.push(("route", text("dlt")));
            payload.push(("sender_id", text(&self.sender_id)));
            payload.push(("message", text(&self.message_id)));
            payload.push(("variables_values", text(sms.content())));
        } else {
            payload.push(("route", text("q")));
            payload.push(("message", text(sms.content())));
        }
        let result = self
            .base
            .request(Call::new(
                "POST",
                ENDPOINT,
                vec![
                    format!("authorization: {}", self.api_key),
                    "Content-Type: application/json".into(),
                    "Accept: application/json".into(),
                ],
                Some(map(payload)),
            ))
            .await?;
        let res = &result.response;
        if result.status_code == 200 && matches!(at(res, &[K::S("return")]), Some(Value::Bool(true))) {
            return Ok(all(sms.to(), true, ""));
        }
        let error = match at(res, &[K::S("message")]) {
            Some(message) => result_text(&message, Site { argument: 2, file: "Adapter/SMS/Fast2SMS.php", line: 107 })?,
            None => format!(
                "Unknown error Status Code: {}",
                interpolate(&at(res, &[K::S("status_code")]).unwrap_or_else(|| text("Unknown")))
            ),
        };
        let mut response = Response::new(TYPE);
        for to in sms.to() {
            response.add_failure(to, error.clone());
        }
        Ok(response)
    }
}

/// The number's digits without its calling code (found on the number as
/// written, so an `00` prefix stays in the digits).
fn remove_country_code(number: &str) -> String {
    let digits: String = number.chars().filter(char::is_ascii_digit).collect();
    match calling_code::from_phone_number(number) {
        Some(code) => {
            String::from_utf8_lossy(php_std::string::substr(digits.as_bytes(), code.len() as i64, None)).into_owned()
        }
        None => digits,
    }
}

sms_adapter!(Fast2Sms, "Fast2SMS", 1000);
