use bytes::Bytes;
use http::{Method, Request};
use serde_json::Value;
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};

use crate::adapter::{Adapter, AdapterState};
use crate::error::AbuseError;
use crate::logs::Logs;

/// Google reCAPTCHA siteverify endpoint.
pub const SITEVERIFY_URL: &str = "https://www.google.com/recaptcha/api/siteverify";

/// PHP `Utopia\Abuse\Adapters\ReCaptcha`.
///
/// `check()` matches PHP: `true` when `success && score >= threshold` (human),
/// unlike limiter adapters where `true` means abuse.
#[derive(Clone)]
pub struct ReCaptcha {
    state: AdapterState,
    secret: String,
    response: String,
    remote_ip: String,
    siteverify_url: String,
    http: Client<curl::Client>,
}

impl std::fmt::Debug for ReCaptcha {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("ReCaptcha")
            .field("siteverify_url", &self.siteverify_url)
            .finish_non_exhaustive()
    }
}

impl ReCaptcha {
    /// PHP `new ReCaptcha($secret, $response, $remoteIP)`.
    #[must_use]
    pub fn new(
        secret: impl Into<String>,
        response: impl Into<String>,
        remote_ip: impl Into<String>,
    ) -> Self {
        Self {
            state: AdapterState::new(""),
            secret: secret.into(),
            response: response.into(),
            remote_ip: remote_ip.into(),
            siteverify_url: SITEVERIFY_URL.to_owned(),
            http: Client::new(curl::Client::new()).with_connection_reuse(true),
        }
    }

    /// Override the siteverify URL (tests / wiremock).
    #[must_use]
    pub fn with_siteverify_url(mut self, url: impl Into<String>) -> Self {
        self.siteverify_url = url.into();
        self
    }

    /// Inject an HTTP client (`utopia-client` cURL adapter).
    #[must_use]
    pub fn with_http_client(mut self, http: Client<curl::Client>) -> Self {
        self.http = http;
        self
    }

    /// PHP `check(float $score = 0.5)`.
    ///
    /// # Errors
    ///
    /// HTTP or JSON failures.
    pub fn check_with_score(&self, score: f64) -> Result<bool, AbuseError> {
        let fields = [
            ("secret", php_urlencode(&self.secret)),
            ("response", php_urlencode(&self.response)),
            ("remoteip", php_urlencode(&self.remote_ip)),
        ];
        // PHP urlencodes values, then `http_build_query` encodes again.
        let body = fields
            .iter()
            .map(|(key, value)| format!("{}={}", php_urlencode(key), php_urlencode(value)))
            .collect::<Vec<_>>()
            .join("&");
        let request = Request::builder()
            .method(Method::POST)
            .uri(&self.siteverify_url)
            .header("Content-Type", "application/x-www-form-urlencoded")
            .body(Bytes::from(body))
            .map_err(|error| AbuseError::Http(error.to_string()))?;
        let response = self
            .http
            .send_request(request)
            .map_err(|error| AbuseError::Http(error.to_string()))?;
        let text = String::from_utf8_lossy(response.body()).into_owned();
        let result: Value = serde_json::from_str(&text).map_err(|_| AbuseError::InvalidJson)?;
        let success = result
            .get("success")
            .and_then(Value::as_bool)
            .unwrap_or(false);
        let result_score = result.get("score").and_then(Value::as_f64).unwrap_or(0.0);
        Ok(success && result_score >= score)
    }
}

impl Adapter for ReCaptcha {
    fn check(&mut self) -> Result<bool, AbuseError> {
        self.check_with_score(0.5)
    }

    fn set_param(&mut self, key: &str, value: &str) -> &mut Self {
        self.state.set_param(key, value);
        self
    }

    fn parse_key(&mut self) -> String {
        self.state.parse_key()
    }

    fn get_logs(&mut self, _offset: Option<i64>, _limit: Option<i64>) -> Result<Logs, AbuseError> {
        Err(AbuseError::MethodNotSupported)
    }

    fn cleanup(&mut self, _timestamp: i64) -> Result<bool, AbuseError> {
        Err(AbuseError::MethodNotSupported)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        Err(AbuseError::MethodNotSupported)
    }
}

/// PHP `urlencode()`: spaces as `+`, other non-unreserved bytes as `%XX`.
fn php_urlencode(input: &str) -> String {
    let mut out = String::new();
    for byte in input.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => out.push(byte as char),
            b' ' => out.push('+'),
            _ => push_percent_hex(&mut out, byte),
        }
    }
    out
}

fn push_percent_hex(out: &mut String, byte: u8) {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    out.push('%');
    out.push(char::from(HEX[(byte >> 4) as usize]));
    out.push(char::from(HEX[(byte & 0x0f) as usize]));
}
