//! PHP `Adapter::call()` via [`utopia-client`] (PHP `utopia-php/fetch`).

use std::collections::HashMap;

use bytes::Bytes;
use http::{header, Method, Request};
use serde_json::{json, Map, Value};
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};

use crate::error::VcsError;
use crate::php::{http_build_query, USER_AGENT};

/// HTTP verb constants (PHP `Adapter::METHOD_*`).
pub const METHOD_GET: &str = "GET";
pub const METHOD_POST: &str = "POST";
pub const METHOD_PUT: &str = "PUT";
pub const METHOD_PATCH: &str = "PATCH";
pub const METHOD_DELETE: &str = "DELETE";
pub const METHOD_HEAD: &str = "HEAD";
pub const METHOD_OPTIONS: &str = "OPTIONS";
pub const METHOD_CONNECT: &str = "CONNECT";
pub const METHOD_TRACE: &str = "TRACE";

/// Result of [`HttpClient::call`] (PHP `['headers' => ..., 'body' => ...]`).
#[derive(Debug, Clone)]
pub struct CallResponse {
    /// Lowercased header names plus `status-code`.
    pub headers: Map<String, Value>,
    /// JSON value when `Content-Type` is `application/json` and `decode` is true.
    pub body: Value,
}

impl CallResponse {
    #[must_use]
    pub fn status_code(&self) -> i64 {
        self.headers
            .get("status-code")
            .and_then(Value::as_i64)
            .unwrap_or(0)
    }

    #[must_use]
    pub fn header(&self, name: &str) -> String {
        self.headers
            .get(&name.to_ascii_lowercase())
            .map(crate::php::strval)
            .unwrap_or_default()
    }

    #[must_use]
    pub fn body_object(&self) -> Value {
        if self.body.is_null() {
            json!({})
        } else {
            self.body.clone()
        }
    }

    /// PHP `['headers' => ..., 'body' => ...]` as JSON.
    #[must_use]
    pub fn to_value(&self) -> Value {
        json!({
            "headers": Value::Object(self.headers.clone()),
            "body": self.body.clone(),
        })
    }
}

/// Shared HTTP state for every adapter.
#[derive(Debug, Clone)]
pub struct HttpClient {
    pub endpoint: String,
    pub self_signed: bool,
    pub headers: HashMap<String, String>,
}

impl HttpClient {
    #[must_use]
    pub fn new(endpoint: impl Into<String>) -> Self {
        Self {
            endpoint: endpoint.into(),
            self_signed: true,
            headers: HashMap::from([("content-type".into(), "application/json".into())]),
        }
    }

    /// PHP `Adapter::call()`.
    pub fn call(
        &self,
        method: &str,
        path: &str,
        extra_headers: HashMap<String, String>,
        params: &Value,
        decode: bool,
        follow_redirects: bool,
    ) -> Result<CallResponse, VcsError> {
        let mut headers = self.headers.clone();
        for (key, value) in extra_headers {
            headers.insert(key, value);
        }

        let mut url = format!("{}{path}", self.endpoint);
        let method_upper = method.to_ascii_uppercase();
        let is_get = method_upper == METHOD_GET;

        if is_get && !is_php_empty_params(params) {
            let query = http_build_query(params);
            url.push('?');
            url.push_str(&query);
        }

        let content_type = headers
            .get("content-type")
            .cloned()
            .unwrap_or_else(|| "application/x-www-form-urlencoded".into());

        let body = if is_get {
            None
        } else {
            Some(encode_body(&content_type, params)?)
        };

        let client = Client::new(curl::Client::new())
            .with_timeout(15.0)
            .and_then(|client| client.with_connect_timeout(24.0 * 60.0 * 60.0))
            .map_err(|error| VcsError::with_status(format!("{error} with status code 0"), 0))?
            .with_ssl_verification(!self.self_signed);

        let http_method = method_upper.parse::<Method>().unwrap_or(Method::GET);
        let (status, response_headers, response_body_text) = send_following_redirects(
            &client,
            http_method,
            &url,
            &headers,
            body.as_deref(),
            follow_redirects,
        )?;

        let response_type = response_headers
            .get("content-type")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_string();

        let body = if decode {
            decode_body(&response_type, &response_body_text)?
        } else {
            Value::String(response_body_text.clone())
        };

        if status == 500 {
            let params_json = serde_json::to_string(params).unwrap_or_else(|_| "null".into());
            let body_json = serde_json::to_string(&body).unwrap_or_else(|_| "null".into());
            eprintln!("Server error({method}: {path}. Params: {params_json}): {body_json}");
        }

        let mut response_headers = response_headers;
        response_headers.insert("status-code".into(), json!(status));

        Ok(CallResponse {
            headers: response_headers,
            body,
        })
    }
}

fn send_following_redirects(
    client: &Client<curl::Client>,
    method: Method,
    url: &str,
    headers: &HashMap<String, String>,
    body: Option<&str>,
    follow_redirects: bool,
) -> Result<(i64, Map<String, Value>, String), VcsError> {
    let mut url = url.to_owned();
    let mut method = method;
    let mut payload = body.map(str::to_owned);
    for _ in 0..50 {
        let mut builder = Request::builder()
            .method(method.clone())
            .uri(&url)
            .header(header::USER_AGENT, USER_AGENT);
        for (name, value) in headers {
            builder = builder.header(name.as_str(), value.as_str());
        }
        let request = builder
            .body(Bytes::from(payload.clone().unwrap_or_default()))
            .map_err(|error| VcsError::with_status(format!("{error} with status code 0"), 0))?;
        let response = client
            .send_request(request)
            .map_err(|error| VcsError::with_status(format!("{error} with status code 0"), 0))?;
        let status = i64::from(response.status().as_u16());
        let mut response_headers = Map::new();
        for (name, value) in response.headers() {
            response_headers.insert(
                name.as_str().to_ascii_lowercase(),
                Value::String(value.to_str().unwrap_or_default().to_string()),
            );
        }
        if follow_redirects && (300..400).contains(&status) {
            if let Some(location) = response
                .headers()
                .get(header::LOCATION)
                .and_then(|value| value.to_str().ok())
            {
                url = resolve_redirect(&url, location);
                if status != 307 && status != 308 {
                    method = Method::GET;
                    payload = None;
                }
                continue;
            }
        }
        let response_body_text = String::from_utf8_lossy(response.body()).into_owned();
        return Ok((status, response_headers, response_body_text));
    }
    Err(VcsError::with_status(
        "too many redirects with status code 0",
        0,
    ))
}

fn resolve_redirect(current: &str, location: &str) -> String {
    if location.starts_with("http://") || location.starts_with("https://") {
        location.to_owned()
    } else if let Some(scheme_end) = current.find("://") {
        let after = &current[scheme_end + 3..];
        let host_end = after
            .find('/')
            .map_or(current.len(), |i| scheme_end + 3 + i);
        if location.starts_with('/') {
            format!("{}{location}", &current[..host_end])
        } else {
            let base = current.rsplit_once('/').map_or(current, |(h, _)| h);
            format!("{base}/{location}")
        }
    } else {
        location.to_owned()
    }
}

fn is_php_empty_params(params: &Value) -> bool {
    match params {
        Value::Null => true,
        Value::Array(items) => items.is_empty(),
        Value::Object(map) => map.is_empty(),
        Value::String(s) => s.is_empty(),
        _ => false,
    }
}

fn encode_body(content_type: &str, params: &Value) -> Result<String, VcsError> {
    match content_type {
        "application/json" => serde_json::to_string(params)
            .map_err(|error| VcsError::message(format!("Failed to encode JSON: {error}"))),
        "multipart/form-data" => {
            let flat = flatten(params, "");
            Ok(http_build_query(&Value::Object(flat)))
        }
        "application/graphql" => match params {
            Value::Array(items) => Ok(items
                .first()
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_string()),
            Value::String(s) => Ok(s.clone()),
            _ => Ok(params.to_string()),
        },
        _ => Ok(http_build_query(params)),
    }
}

fn decode_body(content_type: &str, body: &str) -> Result<Value, VcsError> {
    let mime = content_type
        .split(';')
        .next()
        .unwrap_or(content_type)
        .trim();
    if mime == "application/json" {
        serde_json::from_str(body)
            .map_err(|_| VcsError::message(format!("Failed to parse response: {body}")))
    } else {
        Ok(Value::String(body.to_string()))
    }
}

/// PHP `Adapter::flatten`.
#[must_use]
pub fn flatten(data: &Value, prefix: &str) -> Map<String, Value> {
    let mut output = Map::new();
    let entries: Vec<(String, &Value)> = match data {
        Value::Object(map) => map.iter().map(|(k, v)| (k.clone(), v)).collect(),
        Value::Array(items) => items
            .iter()
            .enumerate()
            .map(|(i, v)| (i.to_string(), v))
            .collect(),
        other => {
            if prefix.is_empty() {
                return output;
            }
            output.insert(prefix.to_string(), other.clone());
            return output;
        }
    };
    for (key, value) in entries {
        let final_key = if prefix.is_empty() {
            key
        } else {
            format!("{prefix}[{key}]")
        };
        if value.is_object() || value.is_array() {
            output.extend(flatten(value, &final_key));
        } else {
            output.insert(final_key, value.clone());
        }
    }
    output
}

/// Encode a path segment but keep `/` (PHP `str_replace('%2F', '/', rawurlencode($ref))`).
#[must_use]
pub fn encode_ref_keep_slash(value: &str) -> String {
    crate::php::php_rawurlencode(value).replace("%2F", "/")
}

/// JSON object from a list of `(key, value)` pairs, skipping PHP-empty values.
#[must_use]
pub fn filter_empty_object(pairs: Vec<(&str, Value)>) -> Map<String, Value> {
    let mut map = Map::new();
    for (key, value) in pairs {
        if !crate::php::php_empty_value(&value) {
            map.insert(key.to_string(), value);
        }
    }
    map
}
