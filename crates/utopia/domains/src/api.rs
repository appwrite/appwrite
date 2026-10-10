//! [`Api`]: a JSON API client with `sso-key` authentication, the base of
//! key-and-secret registrar APIs.

use std::sync::Arc;
use std::time::Duration;

use php_std::encoding::{QueryEncoding, http_build_query};
use php_std::json;
use serde_json::Value;

use crate::Error;
use crate::php::string;
use crate::transport::{HttpTransport, Request, Transport, normalize_url};

/// What [`Api::call`] sends: fields (encoded by the `Content-Type`) or a
/// body as is (`text/xml`).
#[derive(Debug, Clone, PartialEq)]
pub enum Params {
    /// Fields: an object or a list.
    Fields(Value),
    /// A raw body.
    Text(String),
}

impl Params {
    /// No parameters (`[]`).
    pub fn none() -> Self {
        Params::Fields(Value::Array(Vec::new()))
    }

    /// `in_array($params, ['', '0', []], true)`.
    fn is_empty(&self) -> bool {
        match self {
            Params::Text(t) => t.is_empty() || t == "0",
            Params::Fields(Value::Array(a)) => a.is_empty(),
            Params::Fields(Value::Object(o)) => o.is_empty(),
            Params::Fields(_) => false,
        }
    }

    fn query(&self) -> Result<String, Error> {
        match self {
            Params::Fields(v) => {
                Ok(String::from_utf8_lossy(&http_build_query(v, b"", None, QueryEncoding::Rfc1738)).into_owned())
            }
            Params::Text(_) => {
                Err(Error::Type("http_build_query(): Argument #1 ($data) must be of type array, string given".into()))
            }
        }
    }
}

/// What an API answered: decoded JSON, or the body as text.
#[derive(Debug, Clone, PartialEq)]
pub enum Answer {
    /// A JSON answer, decoded.
    Json(Value),
    /// Any other answer, as text.
    Text(String),
}

/// An HTTP API client (`Utopia\Domains\Adapter`): JSON by default, with
/// `Authorization: sso-key <key>:<secret>`, following redirects.
///
/// ```
/// use std::sync::Arc;
/// use utopia_domains::api::{Answer, Api, Params};
/// use utopia_domains::transport::{Request, Response, Transport, TransportError};
/// use utopia_domains::BoxFuture;
///
/// struct Echo;
///
/// impl Transport for Echo {
///     fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, TransportError>> {
///         assert_eq!(request.url, "https://api.example.com/v1/domains?limit=5");
///         assert_eq!(request.header("Authorization"), Some("sso-key key:secret"));
///         let headers = vec![("Content-Type".to_owned(), "application/json; charset=utf-8".to_owned())];
///         Box::pin(async move { Ok(Response { status: 200, headers, body: br#"{"total":1}"#.to_vec() }) })
///     }
/// }
///
/// let api = Api::new("https://api.example.com", "key", "secret").with_transport(Arc::new(Echo));
/// let params = Params::Fields(serde_json::json!({"limit": 5}));
/// let answer = tokio::runtime::Runtime::new()?.block_on(api.call("GET", "/v1/domains", &params, &[]))?;
/// assert_eq!(answer, Answer::Json(serde_json::json!({"total": 1})));
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Domains\Adapter;
///
/// // A registrar adapter extends the client and calls its API.
/// $api = new class('https://api.example.com', 'key', 'secret') extends Adapter {};
/// var_dump($api instanceof Adapter); // bool(true)
/// ```
#[derive(Clone)]
pub struct Api {
    endpoint: String,
    headers: Vec<(String, String)>,
    transport: Arc<dyn Transport>,
    connect_timeout: Duration,
    timeout: Duration,
}

impl std::fmt::Debug for Api {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Api").field("endpoint", &self.endpoint).finish()
    }
}

impl Api {
    /// A client of the API at `endpoint` (paths are appended to it).
    pub fn new(endpoint: impl Into<String>, api_key: &str, api_secret: &str) -> Self {
        Self {
            endpoint: endpoint.into(),
            headers: vec![
                ("Authorization".into(), format!("sso-key {api_key}:{api_secret}")),
                ("Accept".into(), "application/json".into()),
                ("Content-Type".into(), "application/json".into()),
            ],
            transport: Arc::new(HttpTransport::follow_redirects()),
            connect_timeout: Duration::from_secs(300),
            timeout: Duration::from_secs(86_400),
        }
    }

    /// Sends requests over another transport.
    pub fn with_transport(mut self, transport: Arc<dyn Transport>) -> Self {
        self.transport = transport;
        self
    }

    /// Timeouts of the default transport (none to speak of by default).
    pub fn with_timeouts(mut self, connect_timeout: Duration, timeout: Duration) -> Self {
        self.connect_timeout = connect_timeout;
        self.timeout = timeout;
        self
    }

    /// `call()`: one request. `path` is appended to the endpoint unless it
    /// contains `http`; fields go in the query string of a `GET`, else in
    /// the body, encoded by the `Content-Type` (`application/json`,
    /// `text/xml` as is, `multipart/form-data`, or a form). `headers`
    /// replace or add to the defaults.
    ///
    /// The answer is decoded when its `Content-Type` is
    /// `application/json; <parameters>`. A status of 400 or more is an
    /// error carrying the answer.
    pub async fn call(
        &self,
        method: &str,
        path: &str,
        params: &Params,
        headers: &[(&str, &str)],
    ) -> Result<Answer, Error> {
        let mut merged = self.headers.clone();
        for (name, value) in headers {
            match merged.iter_mut().find(|(n, _)| n == name) {
                Some(slot) => slot.1 = (*value).to_owned(),
                None => merged.push(((*name).to_owned(), (*value).to_owned())),
            }
        }
        let content_type = merged.iter().find(|(n, _)| n == "Content-Type").map(|(_, v)| v.clone()).unwrap_or_default();
        let url = if path.contains("http") {
            path.to_owned()
        } else if method == "GET" && !params.is_empty() && content_type != "text/xml" {
            format!("{}{path}?{}", self.endpoint, params.query()?)
        } else {
            format!("{}{path}", self.endpoint)
        };
        let mut query = String::new();
        if !params.is_empty() {
            query = match (content_type.as_str(), params) {
                ("application/json", Params::Fields(v)) => json::encode(
                    &php_std::Value::<php_std::Never>::from_json(v),
                    json::Flags::UNESCAPED_SLASHES,
                    json::DEFAULT_DEPTH,
                )
                .unwrap_or_default(),
                ("application/json", Params::Text(t)) => json::encode(
                    &php_std::Value::<php_std::Never>::from_json(&Value::String(t.clone())),
                    json::Flags::UNESCAPED_SLASHES,
                    json::DEFAULT_DEPTH,
                )
                .unwrap_or_default(),
                ("text/xml", Params::Text(t)) => t.clone(),
                ("text/xml", Params::Fields(_)) => {
                    return Err(Error::Type("Return value must be of type string".into()));
                }
                (_, p) => p.query()?,
            };
        }
        let body = match params {
            Params::Fields(fields) if method != "GET" && content_type == "multipart/form-data" => {
                merged.retain(|(n, _)| n != "Content-Type");
                let boundary = format!("utopia-{}", php_std::encoding::bin2hex(&random_bytes()));
                merged.push(("Content-Type".into(), format!("multipart/form-data; boundary={boundary}")));
                multipart(&boundary, &flatten(fields))
            }
            _ if method != "GET" => query.into_bytes(),
            _ => Vec::new(),
        };
        let url = normalize_url(&url).ok_or_else(|| Error::InvalidArgument("Invalid URI.".into()))?;
        let request = Request {
            method: method.to_ascii_uppercase(),
            url,
            headers: merged,
            body,
            connect_timeout: self.connect_timeout,
            timeout: self.timeout,
        };
        let response = self.transport.send(request).await.map_err(|e| Error::exception(e.0, 0))?;
        let response_type = response.header("content-type").unwrap_or("").to_ascii_lowercase();
        let text = String::from_utf8_lossy(&response.body).into_owned();
        let answer = match response_type.split_once(';') {
            Some(("application/json", _)) => Answer::Json(
                json::decode_value(text.as_bytes(), json::DEFAULT_DEPTH, json::Flags::NONE).unwrap_or(Value::Null),
            ),
            _ => Answer::Text(text),
        };
        if response.status >= 400 {
            return Err(match answer {
                Answer::Json(v @ (Value::Array(_) | Value::Object(_))) => {
                    let encoded = json::encode(
                        &php_std::Value::<php_std::Never>::from_json(&v),
                        json::Flags::NONE,
                        json::DEFAULT_DEPTH,
                    )
                    .unwrap_or_default();
                    Error::exception(encoded, 0)
                }
                Answer::Json(v) => Error::exception(format!("{}: {}", response.status, string(&v)), 0),
                Answer::Text(t) => Error::exception(format!("{}: {t}", response.status), 0),
            });
        }
        Ok(answer)
    }
}

fn random_bytes() -> [u8; 16] {
    let mut bytes = [0; 16];
    // A boundary only has to be unlikely in the body; a failed read leaves zeros.
    let _ = getrandom::fill(&mut bytes);
    bytes
}

/// `flatten()`: nested fields as `a[b][c]` names; scalars as strings, anything else `""`.
fn flatten(data: &Value) -> Vec<(String, String)> {
    fn walk(data: &Value, prefix: &str, out: &mut Vec<(String, String)>) {
        let entries: Vec<(String, &Value)> = match data {
            Value::Object(map) => map.iter().map(|(k, v)| (k.clone(), v)).collect(),
            Value::Array(list) => list.iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect(),
            _ => return,
        };
        for (key, value) in entries {
            let name = if prefix.is_empty() { key } else { format!("{prefix}[{key}]") };
            match value {
                Value::Object(_) | Value::Array(_) => walk(value, &name, out),
                _ if out.iter().any(|(n, _)| *n == name) => {}
                Value::Null => out.push((name, String::new())),
                scalar => out.push((name, string(scalar))),
            }
        }
    }
    let mut out = Vec::new();
    walk(data, "", &mut out);
    out
}

/// A `multipart/form-data` body of fields.
fn multipart(boundary: &str, fields: &[(String, String)]) -> Vec<u8> {
    let mut body = String::new();
    for (name, value) in fields {
        let escaped = name.replace('\\', "\\\\").replace('"', "\\\"");
        body.push_str(&format!(
            "--{boundary}\r\nContent-Disposition: form-data; name=\"{escaped}\"\r\n\r\n{value}\r\n"
        ));
    }
    body.push_str(&format!("--{boundary}--\r\n"));
    body.into_bytes()
}
