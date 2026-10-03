use std::sync::Arc;

use bytes::Bytes;
use http::{Method, Request};
use serde_json::Value;
use url::form_urlencoded;
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};

/// Result of one HTTP round trip.
#[derive(Debug, Clone)]
pub struct HttpResponse {
    pub status: u16,
    pub body: String,
    pub content_type: String,
    pub error: Option<String>,
}

/// Pluggable HTTP backend.
///
/// Production code uses [`UtopiaClient`] (`utopia-client` cURL adapter). Tests may
/// inject a double behind this same trait.
pub trait HttpClient: Send + Sync {
    fn send(
        &self,
        method: &str,
        url: &str,
        headers: &[(String, String)],
        body: Option<&str>,
    ) -> HttpResponse;
}

/// Default transport: PHP `new Client(new Curl)->withConnectionReuse()`.
#[derive(Clone, Debug)]
pub struct UtopiaClient {
    inner: Client<curl::Client>,
}

impl Default for UtopiaClient {
    fn default() -> Self {
        let inner = Client::new(curl::Client::new())
            .with_connection_reuse(true)
            .with_headers([(
                "user-agent",
                format!(
                    "{}-{}:rust-{}",
                    std::env::consts::OS,
                    std::env::consts::ARCH,
                    env!("CARGO_PKG_VERSION")
                ),
            )]);
        Self { inner }
    }
}

impl HttpClient for UtopiaClient {
    fn send(
        &self,
        method: &str,
        url: &str,
        headers: &[(String, String)],
        body: Option<&str>,
    ) -> HttpResponse {
        let parsed_method = method.parse::<Method>().unwrap_or(Method::GET);
        let mut builder = Request::builder().method(parsed_method).uri(url);
        for (key, value) in headers {
            builder = builder.header(key.as_str(), value.as_str());
        }
        let payload = Bytes::from(body.unwrap_or("").to_owned());
        let request = match builder.body(payload) {
            Ok(request) => request,
            Err(err) => {
                return HttpResponse {
                    status: 0,
                    body: String::new(),
                    content_type: String::new(),
                    error: Some(err.to_string()),
                };
            }
        };
        match self.inner.send_request(request) {
            Ok(resp) => {
                let status = resp.status().as_u16();
                let content_type = resp
                    .headers()
                    .get(http::header::CONTENT_TYPE)
                    .and_then(|v| v.to_str().ok())
                    .unwrap_or("")
                    .to_owned();
                let body = String::from_utf8_lossy(resp.body()).into_owned();
                HttpResponse {
                    status,
                    body,
                    content_type,
                    error: None,
                }
            }
            Err(err) => HttpResponse {
                status: 0,
                body: String::new(),
                content_type: String::new(),
                error: Some(err.to_string()),
            },
        }
    }
}

impl HttpClient for Arc<dyn HttpClient> {
    fn send(
        &self,
        method: &str,
        url: &str,
        headers: &[(String, String)],
        body: Option<&str>,
    ) -> HttpResponse {
        (**self).send(method, url, headers, body)
    }
}

/// PHP bracket-style `application/x-www-form-urlencoded` (nested maps/arrays).
pub fn form_encode(value: &Value) -> String {
    let mut pairs = Vec::new();
    encode_value(&mut pairs, None, value);
    form_urlencoded::Serializer::new(String::new())
        .extend_pairs(pairs)
        .finish()
}

fn encode_value(pairs: &mut Vec<(String, String)>, prefix: Option<String>, value: &Value) {
    match value {
        Value::Null => {
            if let Some(key) = prefix {
                pairs.push((key, String::new()));
            }
        }
        Value::Bool(b) => {
            if let Some(key) = prefix {
                pairs.push((key, if *b { "1".into() } else { "0".into() }));
            }
        }
        Value::Number(n) => {
            if let Some(key) = prefix {
                pairs.push((key, n.to_string()));
            }
        }
        Value::String(s) => {
            if let Some(key) = prefix {
                pairs.push((key, s.clone()));
            }
        }
        Value::Array(items) => {
            for (i, item) in items.iter().enumerate() {
                let key = match &prefix {
                    Some(p) => format!("{p}[{i}]"),
                    None => i.to_string(),
                };
                encode_value(pairs, Some(key), item);
            }
        }
        Value::Object(map) => {
            for (k, v) in map {
                let key = match &prefix {
                    Some(p) => format!("{p}[{k}]"),
                    None => k.clone(),
                };
                encode_value(pairs, Some(key), v);
            }
        }
    }
}

/// PHP `empty()` for optional string IDs.
#[must_use]
pub fn php_empty_str(value: Option<&str>) -> bool {
    match value {
        None => true,
        Some(s) => s.is_empty() || s == "0",
    }
}
