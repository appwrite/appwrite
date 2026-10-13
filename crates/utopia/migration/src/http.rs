//! HTTP calls as `Target::call()` makes them on cURL, on `utopia-client`.

use bytes::Bytes;
use indexmap::IndexMap;
use php_std::encoding::{QueryEncoding, http_build_query};
use php_std::json::{Flags, encode};
use php_std::{Array, Value};
use utopia_client::{Anywhere, Client, Http, Request};

use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::target::Body;

/// One part of a multipart body: a field, or a file with its MIME type and
/// file name (PHP: a `CURLFile`).
#[derive(Debug, Clone, PartialEq)]
pub enum Part {
    /// A field value.
    Field(Value),
    /// A file.
    File {
        /// The bytes.
        data: Bytes,
        /// The MIME type.
        mime: String,
        /// The file name.
        name: String,
    },
}

/// The user agent cURL sends from PHP is `<os>-<release>:php-<version>`;
/// Rust names its own runtime in the last part.
pub fn user_agent() -> String {
    let u = rustix::system::uname();
    format!("{}-{}:rust", u.sysname().to_string_lossy(), u.release().to_string_lossy())
}

fn query(params: &Array) -> String {
    String::from_utf8_lossy(&http_build_query(
        &Value::Array(params.clone()).to_json(),
        b"",
        None,
        QueryEncoding::Rfc1738,
    ))
    .into_owned()
}

/// A multipart body for `parts`, with a fixed boundary.
pub fn multipart(parts: &[(String, Part)]) -> (String, Vec<u8>) {
    let boundary = "------------------------migration".to_owned();
    let mut body = Vec::new();
    for (name, part) in parts {
        body.extend_from_slice(format!("--{boundary}\r\n").as_bytes());
        match part {
            Part::Field(v) => {
                let text = crate::resources::cast_string(v);
                body.extend_from_slice(format!("Content-Disposition: form-data; name=\"{name}\"\r\n\r\n").as_bytes());
                body.extend_from_slice(text.as_bytes());
            }
            Part::File { data, mime, name: file } => {
                body.extend_from_slice(
                    format!(
                        "Content-Disposition: form-data; name=\"{name}\"; filename=\"{file}\"\r\nContent-Type: {mime}\r\n\r\n"
                    )
                    .as_bytes(),
                );
                body.extend_from_slice(data);
            }
        }
        body.extend_from_slice(b"\r\n");
    }
    body.extend_from_slice(format!("--{boundary}--\r\n").as_bytes());
    (format!("multipart/form-data; boundary={boundary}"), body)
}

/// Sends one request and returns its status, lowercased headers and body.
pub async fn send(
    method: &str,
    url: &str,
    headers: &[(String, String)],
    body: Option<Vec<u8>>,
    follow: bool,
) -> Result<(u16, IndexMap<String, String>, Bytes)> {
    let client = Client::new(Http::new())
        .with_destinations(Anywhere)
        .with_connect_timeout(crate::target::CONNECT_TIMEOUT as f64)
        .map_err(transport)?;
    let client = if follow { client.with_follow_redirects(true, 50).map_err(transport)? } else { client };
    let mut request = Request::new(method, url).map_err(transport)?;
    request = request.header("User-Agent", &user_agent()).map_err(transport)?;
    request = request.header("Accept", "*/*").map_err(transport)?;
    for (k, v) in headers {
        if v.is_empty() {
            continue;
        }
        request = request.header(k, v).map_err(transport)?;
    }
    if let Some(b) = body {
        request = request.body(b);
    }
    let response = client.send(request).await.map_err(transport)?;
    let mut out = IndexMap::new();
    for (name, values) in response.headers().iter() {
        for v in values {
            out.insert(name.trim().to_ascii_lowercase(), v.trim().to_ascii_lowercase());
        }
    }
    Ok((response.status(), out, response.bytes().clone()))
}

fn transport(e: utopia_client::Error) -> Error {
    Error::exception(e.to_string(), Exception::CODE_INTERNAL)
}

/// PHP: `Target::call()`.
pub async fn call(
    endpoint: &str,
    method: &str,
    path: &str,
    headers: &IndexMap<String, String>,
    params: &Array,
) -> Result<(Body, IndexMap<String, String>)> {
    let base = if path.contains("http") { path.to_owned() } else { format!("{endpoint}{path}") };
    let url = if method == "GET" && !params.is_empty() { format!("{base}?{}", query(params)) } else { base };
    let content_type = headers.get("Content-Type").cloned().unwrap_or_default();
    let mut sent: Vec<(String, String)> = headers.iter().map(|(k, v)| (k.clone(), v.clone())).collect();
    let body = if method == "GET" {
        None
    } else {
        Some(match content_type.as_str() {
            "application/json" => {
                encode(&Value::Array(params.clone()), Flags::NONE, 512).unwrap_or_default().into_bytes()
            }
            "multipart/form-data" => {
                let parts: Vec<(String, Part)> =
                    crate::target::Target::flatten(params, "").into_iter().map(|(k, v)| (k, Part::Field(v))).collect();
                let (ct, body) = multipart(&parts);
                for h in &mut sent {
                    if h.0 == "Content-Type" {
                        h.1 = ct.clone();
                    }
                }
                body
            }
            _ => query(params).into_bytes(),
        })
    };
    let method = if method == "HEAD" { "HEAD" } else { method };
    let (status, response_headers, raw) = send(method, &url, &sent, body, true).await?;
    let content = response_headers.get("content-type").cloned().unwrap_or_default();
    let decoded = match content.find(';') {
        Some(i) if &content[..i] == "application/json" => {
            Some(php_std::json::decode(&raw, Some(true), 512, Flags::NONE).unwrap_or(Value::Null))
        }
        _ => None,
    };
    if status >= 400 {
        let message = match &decoded {
            Some(v @ Value::Array(_)) => encode(v, Flags::NONE, 512).unwrap_or_default(),
            Some(v) => format!("{status}: {}", crate::resources::cast_string(v)),
            None => format!("{status}: {}", String::from_utf8_lossy(&raw)),
        };
        return Err(Error::exception(message, i64::from(status)));
    }
    Ok((decoded.map_or(Body::Raw(raw), Body::Json), response_headers))
}
