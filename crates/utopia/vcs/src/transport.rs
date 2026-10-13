//! Provider API calls: the request every adapter sends and the response it
//! reads back.

use php_std::{Str, Value};
use utopia_client::{Client, Http, Request};

use crate::error::{Error, Result};
use crate::php;

/// The browser `User-Agent` every call sends.
const USER_AGENT: &str =
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36";

/// An HTTP method.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum Method {
    Get,
    Post,
    Put,
    Patch,
    Delete,
}

impl Method {
    fn as_str(self) -> &'static str {
        match self {
            Method::Get => "GET",
            Method::Post => "POST",
            Method::Put => "PUT",
            Method::Patch => "PATCH",
            Method::Delete => "DELETE",
        }
    }
}

/// What a call sends besides its path: the query of a `GET`, the body of
/// anything else.
#[derive(Debug, Clone)]
pub(crate) enum Params {
    /// Nothing: no query, and `{}` as a JSON body.
    None,
    /// A PHP array: the query string of a `GET`, the JSON body otherwise.
    Array(Value),
    /// A form body of byte strings (`application/x-www-form-urlencoded`).
    Form(Vec<(Vec<u8>, Vec<u8>)>),
}

/// One provider call.
#[derive(Debug, Clone)]
pub(crate) struct Call {
    pub method: Method,
    pub path: String,
    /// The `Authorization` header, when the call carries one.
    pub authorization: Option<String>,
    pub params: Params,
    /// Whether a JSON response is decoded.
    pub decode: bool,
    /// Whether redirects are followed.
    pub follow: bool,
}

impl Call {
    /// A call with no parameters, decoding JSON and following redirects.
    pub fn new(method: Method, path: impl Into<String>) -> Self {
        Call { method, path: path.into(), authorization: None, params: Params::None, decode: true, follow: true }
    }

    /// `GET path`.
    pub fn get(path: impl Into<String>) -> Self {
        Self::new(Method::Get, path)
    }

    /// `POST path`.
    pub fn post(path: impl Into<String>) -> Self {
        Self::new(Method::Post, path)
    }

    /// With an `Authorization` header.
    pub fn auth(mut self, authorization: impl Into<String>) -> Self {
        self.authorization = Some(authorization.into());
        self
    }

    /// With parameters (a PHP array).
    pub fn params(mut self, params: Value) -> Self {
        self.params = Params::Array(params);
        self
    }

    /// Without decoding a JSON response.
    pub fn raw(mut self) -> Self {
        self.decode = false;
        self
    }

    /// Without following redirects.
    pub fn no_follow(mut self) -> Self {
        self.follow = false;
        self
    }
}

/// A provider's answer.
#[derive(Debug, Clone)]
pub(crate) struct Response {
    /// The status (0 never: a call that got no answer fails).
    pub status: i64,
    /// The response headers, names lowercased, each its last value.
    pub headers: Vec<(String, String)>,
    /// The body: decoded JSON for a JSON response, the raw bytes otherwise.
    pub body: Value,
}

impl Response {
    /// A response header (lowercase name).
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers.iter().find(|(n, _)| n == name).map(|(_, v)| v.as_str())
    }

    /// `$response['body'][$key] ?? null`.
    pub fn at(&self, key: &str) -> Value {
        php::at(&self.body, key)
    }
}

/// The HTTP side of an adapter: its endpoint and clients.
#[derive(Debug)]
pub(crate) struct Transport {
    pub endpoint: String,
    following: Client<Http>,
    direct: Client<Http>,
}

impl Transport {
    /// A transport calling `endpoint` (no trailing slash).
    pub fn new(endpoint: impl Into<String>) -> Self {
        let base = Client::new(Http::new())
            .with_user_agent(USER_AGENT)
            .with_path_as_is(true)
            .with_encoding_negotiation(false)
            .with_ssl_verification(false);
        let configure = |follow: bool| {
            base.with_follow_redirects(follow, i64::from(utopia_client::redirect::MAX_HOPS))
                .and_then(|c| c.with_connect_timeout(0.0))
                .and_then(|c| c.with_timeout(15.0))
                .expect("constant client settings are valid")
        };
        Transport { endpoint: endpoint.into(), following: configure(true), direct: configure(false) }
    }

    /// Sends a call and reads its response.
    ///
    /// PHP: `Adapter::call()`: the JSON content type (or the form one a call
    /// asks for), the query of a `GET`, a body for every other method (`{}`
    /// when there are no parameters), a 15 second timeout, no certificate
    /// verification, and a JSON response decoded unless asked not to (an
    /// undecodable one fails). A transport failure is an `\Exception` with
    /// the client's message and ` with status code 0`.
    pub async fn call(&self, call: Call) -> Result<Response> {
        let form = matches!(call.params, Params::Form(_));
        let mut url = format!("{}{}", self.endpoint, call.path);
        let mut body: Option<Vec<u8>> = None;
        match &call.params {
            Params::Array(Value::Array(params)) if call.method == Method::Get && !params.is_empty() => {
                url.push('?');
                url.push_str(&query(&Value::Array(params.clone())));
            }
            _ => {}
        }
        if call.method != Method::Get {
            body = Some(match &call.params {
                Params::Form(pairs) => form_encode(pairs),
                Params::Array(Value::Array(params)) if !params.is_empty() => {
                    php::json_encode(&Value::Array(params.clone())).map(String::into_bytes).unwrap_or_default()
                }
                Params::Array(other) if !matches!(other, Value::Array(_)) => {
                    php::json_encode(other).map(String::into_bytes).unwrap_or_default()
                }
                _ => b"{}".to_vec(),
            });
        }

        let mut request = Request::new(call.method.as_str(), &url).map_err(Error::Client)?;
        let content_type = if form { "application/x-www-form-urlencoded" } else { "application/json" };
        request = request.header("content-type", content_type).map_err(Error::Client)?;
        if let Some(authorization) = &call.authorization {
            request = request.header("Authorization", authorization).map_err(Error::Client)?;
        }
        if let Some(body) = body {
            request = request.body(body);
        }

        let client = if call.follow { &self.following } else { &self.direct };
        let response = match client.send(request).await {
            Ok(r) => r,
            Err(e) if e.is_client() => {
                return Err(Error::exception(format!("{} with status code 0", e.message()), 0));
            }
            Err(e) => return Err(Error::Client(e)),
        };

        let mut headers: Vec<(String, String)> = Vec::new();
        for (name, values) in response.headers().iter() {
            let name = name.to_ascii_lowercase();
            let value = values.last().cloned().unwrap_or_default();
            match headers.iter_mut().find(|(n, _)| *n == name) {
                Some(entry) => entry.1 = value,
                None => headers.push((name, value)),
            }
        }
        let status = i64::from(response.status());
        let raw = response.bytes();
        let content_type = headers.iter().find(|(n, _)| n == "content-type").map(|(_, v)| v.as_str()).unwrap_or("");
        let mime = content_type.split(';').next().unwrap_or("");
        // `strpos($type, ';') ?: strlen($type)`: a `;` at offset 0 reads the whole type.
        let mime = if content_type.starts_with(';') { content_type } else { mime };
        let body = if call.decode && mime == "application/json" {
            match php::json_decode(raw) {
                Some(v) => v,
                None => {
                    let mut message = b"Failed to parse response: ".to_vec();
                    message.extend_from_slice(raw);
                    return Err(Error::exception(message, 0));
                }
            }
        } else {
            Value::Str(Str::from(raw.clone()))
        };

        if status == 500 {
            // PHP echoes this to its output; a library has none, so it goes to stderr.
            let params = match &call.params {
                Params::Array(p) => php::json_encode(p).unwrap_or_default(),
                Params::None => "[]".to_owned(),
                Params::Form(_) => "{}".to_owned(),
            };
            eprintln!(
                "Server error({}: {}. Params: {}): {}",
                call.method.as_str(),
                call.path,
                params,
                php::json_encode(&body).unwrap_or_default()
            );
        }

        Ok(Response { status, headers, body })
    }
}

/// `http_build_query($params)` of the scalar and nested parameters adapters
/// send: PHP's encoding (`+` for spaces), `null` skipped, booleans `1`/`0`.
fn query(params: &Value) -> String {
    let json = params.to_json();
    String::from_utf8_lossy(&php_std::encoding::http_build_query(
        &json,
        b"",
        None,
        php_std::encoding::QueryEncoding::Rfc1738,
    ))
    .into_owned()
}

/// `http_build_query()` of a flat array of byte strings.
fn form_encode(pairs: &[(Vec<u8>, Vec<u8>)]) -> Vec<u8> {
    let mut out = Vec::new();
    for (key, value) in pairs {
        if !out.is_empty() {
            out.push(b'&');
        }
        out.extend_from_slice(php_std::encoding::urlencode(key).as_bytes());
        out.push(b'=');
        out.extend_from_slice(php_std::encoding::urlencode(value).as_bytes());
    }
    out
}
