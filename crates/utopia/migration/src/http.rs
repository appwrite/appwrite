//! HTTP calls as `Target::call()` makes them on `Utopia\Client` (cURL), on
//! `utopia-client`.

use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant};

use bytes::Bytes;
use indexmap::IndexMap;
use php_std::encoding::{QueryEncoding, http_build_query};
use php_std::json::{Flags, encode};
use php_std::{Array, Value};
use utopia_client::{Anywhere, Client, Http, Request};

use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::target::{Body, Target};

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

/// `http_build_query($params, '', '&', PHP_QUERY_RFC3986)`.
fn query(params: &Array) -> String {
    String::from_utf8_lossy(&http_build_query(
        &Value::Array(params.clone()).to_json(),
        b"",
        None,
        QueryEncoding::Rfc3986,
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

/// Sends one request and returns its status, its headers (names
/// lowercased, each name's values joined by `, `) and its body.
///
/// The request has no overall time limit: it fails when connecting takes
/// over `connect` seconds, or when no byte of the response body arrives
/// for `stall` seconds (cURL's `CURLOPT_LOW_SPEED_LIMIT` of one byte over
/// `CURLOPT_LOW_SPEED_TIME`), so a transfer of any length lives while data
/// keeps arriving.
pub async fn send(
    method: &str,
    url: &str,
    headers: &[(String, String)],
    body: Option<Vec<u8>>,
    connect: i64,
    stall: i64,
) -> Result<(u16, IndexMap<String, String>, Bytes)> {
    let client = Client::new(Http::new())
        .with_destinations(Anywhere)
        .with_timeout(0.0)
        .and_then(|c| c.with_connect_timeout(connect as f64))
        .and_then(|c| c.with_follow_redirects(true, 50))
        .map_err(transport)?;
    let mut request = Request::new(method, url).map_err(transport)?;
    request = request.header("User-Agent", &user_agent()).map_err(transport)?;
    if !headers.iter().any(|(k, _)| k.eq_ignore_ascii_case("Accept")) {
        // cURL's default.
        request = request.header("Accept", "*/*").map_err(transport)?;
    }
    for (k, v) in headers {
        request = request.header(k, v).map_err(transport)?;
    }
    if let Some(b) = body {
        request = request.body(b);
    }

    let started = Instant::now();
    // Milliseconds after `started` when the last byte arrived.
    let progress = Arc::new(AtomicU64::new(0));
    let mut received = Vec::new();
    let mut sink = {
        let progress = progress.clone();
        let received = &mut received;
        move |chunk: &[u8]| {
            if !chunk.is_empty() {
                progress.store(started.elapsed().as_millis() as u64, Ordering::Relaxed);
            }
            received.extend_from_slice(chunk);
        }
    };
    let window = Duration::from_secs(stall.max(0) as u64);
    let stalled = async {
        loop {
            let idle = started.elapsed().saturating_sub(Duration::from_millis(progress.load(Ordering::Relaxed)));
            if idle >= window {
                return;
            }
            tokio::time::sleep(window - idle).await;
        }
    };
    let response = tokio::select! {
        response = client.stream(request, &mut sink) => response.map_err(transport)?,
        () = stalled, if stall > 0 => {
            return Err(Error::exception(
                format!("Operation too slow. Less than 1 bytes/sec transferred the last {stall} seconds"),
                Exception::CODE_INTERNAL,
            ));
        }
    };
    let mut out = IndexMap::new();
    for (name, values) in response.headers().iter() {
        let values: Vec<&str> = values.iter().map(|v| v.trim()).collect();
        out.insert(name.trim().to_ascii_lowercase(), values.join(", "));
    }
    Ok((response.status(), out, Bytes::from(received)))
}

fn transport(e: utopia_client::Error) -> Error {
    Error::exception(e.to_string(), Exception::CODE_INTERNAL)
}

/// PHP: `Target::call()`.
pub async fn call(
    target: &Target,
    method: &str,
    path: &str,
    headers: &IndexMap<String, String>,
    params: &Array,
) -> Result<(Body, IndexMap<String, String>)> {
    let mut headers = headers.clone();
    let content_type = headers.shift_remove("Content-Type").unwrap_or_default();
    // An empty value has always meant "do not send this header".
    let mut sent: Vec<(String, String)> = headers.into_iter().filter(|(_, v)| !v.is_empty()).collect();
    let has = |sent: &[(String, String)], name: &str| sent.iter().any(|(k, _)| k.eq_ignore_ascii_case(name));
    let mut url = if path.contains("http") { path.to_owned() } else { format!("{}{path}", target.endpoint) };
    let body = if method == "GET" || method == "HEAD" {
        // `Factory::query()`: the parameters appended to the URI's query.
        if !params.is_empty() {
            let built = query(params);
            let (base, existing) = match url.split_once('?') {
                Some((base, q)) => (base.to_owned(), q.to_owned()),
                None => (url.clone(), String::new()),
            };
            let full = if existing.is_empty() { built } else { format!("{existing}&{built}") };
            url = if full.is_empty() { base } else { format!("{base}?{full}") };
        }
        None
    } else if content_type == "application/json" {
        // `Factory::json()`: JSON_THROW_ON_ERROR, and an accept header.
        let encoded = encode(&Value::Array(params.clone()), Flags::NONE, 512).map_err(|e| Error::Other {
            class: "JsonException".into(),
            message: e.message().to_owned(),
            code: e.code().code(),
        })?;
        if !has(&sent, "Content-Type") {
            sent.push(("Content-Type".to_owned(), "application/json".to_owned()));
        }
        if !has(&sent, "Accept") {
            sent.push(("Accept".to_owned(), "application/json".to_owned()));
        }
        Some(encoded.into_bytes())
    } else {
        // `Factory::form()`, whatever the content type asked for.
        if !has(&sent, "Content-Type") {
            sent.push(("Content-Type".to_owned(), "application/x-www-form-urlencoded".to_owned()));
        }
        Some(query(params).into_bytes())
    };
    let (status, response_headers, raw) =
        send(method, &url, &sent, body, target.connect_timeout, target.stall_timeout).await?;
    let content = response_headers.get("content-type").cloned().unwrap_or_default();
    let decoded = content
        .to_ascii_lowercase()
        .starts_with("application/json")
        .then(|| php_std::json::decode(&raw, Some(true), 512, Flags::NONE).unwrap_or(Value::Null));
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
