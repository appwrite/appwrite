//! Thin injectable HTTP layer over [`utopia_client`].
//!
//! PHP adapters take a PSR-18 `ClientInterface` (default `Utopia\Client` + cURL).
//! This trait is the same injection point: production code uses
//! [`utopia_client::Client`] with the cURL adapter; tests inject a mock or point
//! [`with_api_base`](crate::cache::adapter::Cloudflare::with_api_base) at wiremock.

use bytes::Bytes;
use http::{HeaderValue, Request, Response};
use serde_json::Value;
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};

/// Pluggable HTTP backend (PHP `Psr\Http\Client\ClientInterface`).
pub trait HttpClient: Send + Sync {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, String>;
}

impl<A> HttpClient for Client<A>
where
    A: utopia_client::Adapter,
{
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, String> {
        StreamingClient::send_request(self, request).map_err(|error| error.to_string())
    }
}

/// PHP `new Client(new CurlAdapter())`.
#[must_use]
pub fn default_client() -> Client<curl::Client> {
    Client::new(curl::Client::new())
}

/// Decoded provider response. PHP `array{statusCode,response,error}`.
#[derive(Debug, Clone)]
pub(crate) struct RequestResult {
    pub status: u16,
    pub response: Value,
    pub error: Option<String>,
}

pub(crate) fn send(
    client: &dyn HttpClient,
    method: &str,
    url: &str,
    headers: &[(&str, String)],
    body: Option<&Value>,
) -> RequestResult {
    let encoded = body.map(|value| serde_json::to_vec(value).unwrap_or_else(|_| b"{}".to_vec()));
    let mut builder = Request::builder().method(method).uri(url);
    for (name, value) in headers {
        if let Ok(value) = HeaderValue::from_str(value) {
            builder = builder.header(*name, value);
        }
    }
    let request = match builder.body(Bytes::from(encoded.unwrap_or_default())) {
        Ok(request) => request,
        Err(error) => {
            return RequestResult {
                status: 0,
                response: Value::Null,
                error: Some(error.to_string()),
            };
        }
    };
    match client.send_request(request) {
        Ok(response) => {
            let status = response.status().as_u16();
            let contents = String::from_utf8_lossy(response.body()).into_owned();
            RequestResult {
                status,
                response: decode_body(&contents),
                error: None,
            }
        }
        Err(error) => RequestResult {
            status: 0,
            response: Value::Null,
            error: Some(error),
        },
    }
}

pub(crate) fn decode_body(contents: &str) -> Value {
    serde_json::from_str(contents).unwrap_or_else(|_| Value::String(contents.to_owned()))
}

/// PHP `http_build_query` (default `PHP_QUERY_RFC1738` / `urlencode`).
#[must_use]
pub(crate) fn php_http_build_query(pairs: &[(&str, &str)]) -> String {
    pairs
        .iter()
        .map(|(key, value)| format!("{}={}", php_urlencode(key), php_urlencode(value)))
        .collect::<Vec<_>>()
        .join("&")
}

pub(crate) fn push_percent(out: &mut String, byte: u8) {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    out.push('%');
    out.push(char::from(HEX[usize::from(byte >> 4)]));
    out.push(char::from(HEX[usize::from(byte & 0x0F)]));
}

fn php_urlencode(input: &str) -> String {
    let mut out = String::new();
    for byte in input.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => {
                out.push(char::from(byte));
            }
            b' ' => out.push('+'),
            _ => push_percent(&mut out, byte),
        }
    }
    out
}

/// PHP `rawurlencode`.
#[must_use]
pub(crate) fn php_rawurlencode(input: &str) -> String {
    let mut out = String::new();
    for byte in input.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(char::from(byte));
            }
            _ => push_percent(&mut out, byte),
        }
    }
    out
}
