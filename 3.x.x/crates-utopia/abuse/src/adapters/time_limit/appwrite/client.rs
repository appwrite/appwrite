use bytes::Bytes;
use http::{Method, Request};
use serde_json::{json, Value};
use utopia_client::adapter::curl;
use utopia_client::{Client as HttpClient, StreamingClient};

use crate::error::AbuseError;

/// PHP `Appwrite\Client` subset used by `TablesDB`.
#[derive(Clone)]
pub struct Client {
    endpoint: String,
    project: String,
    key: String,
    http: HttpClient<curl::Client>,
}

impl std::fmt::Debug for Client {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("Client")
            .field("endpoint", &self.endpoint)
            .field("project", &self.project)
            .finish_non_exhaustive()
    }
}

impl Default for Client {
    fn default() -> Self {
        Self::new()
    }
}

impl Client {
    /// PHP `new Client()`.
    #[must_use]
    pub fn new() -> Self {
        Self {
            endpoint: String::new(),
            project: String::new(),
            key: String::new(),
            http: HttpClient::new(curl::Client::new()).with_connection_reuse(true),
        }
    }

    /// PHP `setEndpoint`.
    pub fn set_endpoint(&mut self, endpoint: impl Into<String>) -> &mut Self {
        self.endpoint = endpoint.into();
        self
    }

    /// PHP `setProject`.
    pub fn set_project(&mut self, project: impl Into<String>) -> &mut Self {
        self.project = project.into();
        self
    }

    /// PHP `setKey`.
    pub fn set_key(&mut self, key: impl Into<String>) -> &mut Self {
        self.key = key.into();
        self
    }

    /// Consume a builder-style client.
    #[must_use]
    pub fn clone_client(&self) -> Self {
        self.clone()
    }

    fn url(&self, path: &str) -> String {
        format!("{}{}", self.endpoint.trim_end_matches('/'), path)
    }

    /// Perform a JSON HTTP call.
    ///
    /// # Errors
    ///
    /// Transport or Appwrite API errors.
    pub fn request(
        &self,
        method: Method,
        path: &str,
        body: Option<Value>,
        queries: &[String],
    ) -> Result<Value, AbuseError> {
        let mut url = self.url(path);
        if !queries.is_empty() {
            let encoded: Vec<String> = queries
                .iter()
                .map(|query| format!("queries={}", urlencoding_minimal(query)))
                .collect();
            url.push('?');
            url.push_str(&encoded.join("&"));
        }
        let payload = match body {
            Some(body) => serde_json::to_vec(&body).map_err(|_| AbuseError::InvalidJson)?,
            None => Vec::new(),
        };
        let request = Request::builder()
            .method(method)
            .uri(&url)
            .header("Content-Type", "application/json")
            .header("X-Appwrite-Project", &self.project)
            .header("X-Appwrite-Key", &self.key)
            .header("X-Appwrite-Response-Format", "1.8.0")
            .body(Bytes::from(payload))
            .map_err(|error| AbuseError::Message(error.to_string()))?;
        let response = self
            .http
            .send_request(request)
            .map_err(|error| AbuseError::Message(error.to_string()))?;
        let status = response.status();
        let text = String::from_utf8_lossy(response.body()).into_owned();
        if status.is_success() {
            if text.is_empty() {
                return Ok(Value::Null);
            }
            return serde_json::from_str(&text).map_err(|_| AbuseError::InvalidJson);
        }
        let parsed: Value = serde_json::from_str(&text).unwrap_or(Value::Null);
        let error_type = parsed
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        let message = parsed
            .get("message")
            .and_then(Value::as_str)
            .unwrap_or(status.as_str())
            .to_owned();
        Err(AbuseError::Appwrite {
            message,
            error_type,
            code: status.as_u16(),
        })
    }
}

/// PHP `ID::unique()` (uniqid + 7 hex chars).
#[must_use]
pub fn unique_id() -> String {
    let now = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|duration| duration.as_micros())
        .unwrap_or(0);
    format!("{now:013x}{:07x}", now % 0x00FF_FFFF)
}

/// Appwrite query strings matching the PHP SDK helpers used by `TablesDB`.
#[derive(Debug, Clone, Copy)]
pub struct Query;

impl Query {
    /// `Query::equal($attr, [$value])`.
    #[must_use]
    pub fn equal(attribute: &str, values: &[&str]) -> String {
        json!({
            "method": "equal",
            "attribute": attribute,
            "values": values,
        })
        .to_string()
    }

    /// `Query::lessThan($attr, $value)`.
    #[must_use]
    pub fn less_than(attribute: &str, value: &str) -> String {
        json!({
            "method": "lessThan",
            "attribute": attribute,
            "values": [value],
        })
        .to_string()
    }

    /// `Query::orderDesc($attr)`.
    #[must_use]
    pub fn order_desc(attribute: &str) -> String {
        json!({
            "method": "orderDesc",
            "attribute": attribute,
        })
        .to_string()
    }

    /// `Query::offset($n)`.
    #[must_use]
    pub fn offset(offset: i64) -> String {
        json!({ "method": "offset", "values": [offset] }).to_string()
    }

    /// `Query::limit($n)`.
    #[must_use]
    pub fn limit(limit: i64) -> String {
        json!({ "method": "limit", "values": [limit] }).to_string()
    }

    /// `Query::notEqual($attr, $value)`.
    #[must_use]
    pub fn not_equal(attribute: &str, value: &str) -> String {
        json!({
            "method": "notEqual",
            "attribute": attribute,
            "values": [value],
        })
        .to_string()
    }
}

fn urlencoding_minimal(value: &str) -> String {
    let mut out = String::new();
    for byte in value.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(byte as char);
            }
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
