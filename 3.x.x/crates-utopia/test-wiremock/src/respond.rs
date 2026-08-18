//! Dynamic responders and recorded requests.

use std::io::{Read, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread::{self, JoinHandle};
use std::time::Duration;

use base64::Engine;
use http::{HeaderMap, HeaderName, HeaderValue};
use serde_json::Value;
use url::Url;

use crate::ResponseTemplate;

/// One request captured from WireMock's journal (or a proxied backend).
#[derive(Debug, Clone)]
pub struct RecordedRequest {
    pub method: http::Method,
    pub url: Url,
    pub headers: HeaderMap,
    pub body: Vec<u8>,
}

impl RecordedRequest {
    pub(crate) fn from_wiremock(value: &Value) -> Option<Self> {
        let method = value.get("method")?.as_str()?;
        let absolute = value
            .get("absoluteUrl")
            .and_then(Value::as_str)
            .or_else(|| value.get("url").and_then(Value::as_str))?;
        let url = Url::parse(absolute)
            .or_else(|_| Url::parse(&format!("http://localhost{absolute}")))
            .ok()?;
        let mut headers = HeaderMap::new();
        if let Some(Value::Object(map)) = value.get("headers") {
            for (name, val) in map {
                if let Some(text) = val.as_str() {
                    if let (Ok(header_name), Ok(header_value)) = (
                        HeaderName::from_bytes(name.as_bytes()),
                        HeaderValue::from_str(text),
                    ) {
                        headers.insert(header_name, header_value);
                    }
                }
            }
        }
        let body = value
            .get("body")
            .and_then(Value::as_str)
            .map(|text| text.as_bytes().to_vec())
            .unwrap_or_default();
        Some(Self {
            method: method.parse().ok()?,
            url,
            headers,
            body,
        })
    }
}

/// Dynamic response callback (parity with the Rust `wiremock::Respond` trait).
pub trait Respond: Send + Sync {
    fn respond(&self, request: &RecordedRequest) -> ResponseTemplate;
}

pub(crate) fn serve_respond(
    respond: Arc<dyn Respond>,
) -> (String, JoinHandle<()>, Arc<AtomicBool>) {
    let listener = TcpListener::bind("127.0.0.1:0").expect("respond bind");
    listener.set_nonblocking(true).expect("respond nonblocking");
    let port = listener.local_addr().expect("respond addr").port();
    let stop = Arc::new(AtomicBool::new(false));
    let flag = Arc::clone(&stop);
    let join = thread::spawn(move || {
        while !flag.load(Ordering::SeqCst) {
            match listener.accept() {
                Ok((stream, _)) => {
                    let respond = Arc::clone(&respond);
                    thread::spawn(move || {
                        let _ = handle_http(stream, respond.as_ref());
                    });
                }
                Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                    thread::sleep(Duration::from_millis(5));
                }
                Err(_) => break,
            }
        }
    });
    (format!("http://127.0.0.1:{port}"), join, stop)
}

fn handle_http(mut stream: TcpStream, respond: &dyn Respond) -> std::io::Result<()> {
    stream.set_read_timeout(Some(Duration::from_secs(5)))?;
    stream.set_write_timeout(Some(Duration::from_secs(5)))?;
    let mut buf = Vec::new();
    let mut chunk = [0u8; 4096];
    loop {
        let n = stream.read(&mut chunk)?;
        if n == 0 {
            break;
        }
        buf.extend_from_slice(&chunk[..n]);
        if let Some(header_end) = find_header_end(&buf) {
            if let Some(len) = content_length(&buf) {
                if buf.len() >= header_end + len {
                    break;
                }
            } else if is_chunked(&buf) {
                if chunked_complete(&buf[header_end..]) {
                    break;
                }
            } else {
                break;
            }
        }
    }
    let request = parse_http(&buf).unwrap_or_else(|| RecordedRequest {
        method: http::Method::GET,
        url: Url::parse("http://localhost/").expect("url"),
        headers: HeaderMap::new(),
        body: Vec::new(),
    });
    let template = respond.respond(&request);
    let json = template.to_json();
    let status = json.get("status").and_then(Value::as_u64).unwrap_or(200) as u16;
    let body = if let Some(Value::String(text)) = json.get("body") {
        text.as_bytes().to_vec()
    } else if let Some(Value::String(encoded)) = json.get("base64Body") {
        base64::engine::general_purpose::STANDARD
            .decode(encoded)
            .unwrap_or_default()
    } else if let Some(value) = json.get("jsonBody") {
        serde_json::to_vec(value).unwrap_or_default()
    } else {
        Vec::new()
    };
    let mut out = format!(
        "HTTP/1.1 {status} OK\r\nContent-Length: {}\r\nConnection: close\r\n",
        body.len()
    );
    if let Some(Value::Object(map)) = json.get("headers") {
        for (name, value) in map {
            if let Some(text) = value.as_str() {
                out.push_str(name);
                out.push_str(": ");
                out.push_str(text);
                out.push_str("\r\n");
            }
        }
    } else if json.get("jsonBody").is_some() {
        out.push_str("Content-Type: application/json\r\n");
    }
    out.push_str("\r\n");
    stream.write_all(out.as_bytes())?;
    stream.write_all(&body)?;
    stream.flush()?;
    Ok(())
}

fn find_header_end(buf: &[u8]) -> Option<usize> {
    buf.windows(4).position(|w| w == b"\r\n\r\n").map(|i| i + 4)
}

fn content_length(buf: &[u8]) -> Option<usize> {
    let header_end = find_header_end(buf)?;
    let text = std::str::from_utf8(&buf[..header_end]).ok()?;
    for line in text.lines() {
        let lower = line.to_ascii_lowercase();
        if let Some(rest) = lower.strip_prefix("content-length:") {
            return rest.trim().parse().ok();
        }
    }
    None
}

fn is_chunked(buf: &[u8]) -> bool {
    find_header_end(buf)
        .and_then(|header_end| std::str::from_utf8(&buf[..header_end]).ok())
        .is_some_and(|text| {
            text.lines().any(|line| {
                line.split_once(':').is_some_and(|(name, value)| {
                    name.eq_ignore_ascii_case("transfer-encoding")
                        && value
                            .split(',')
                            .any(|encoding| encoding.trim().eq_ignore_ascii_case("chunked"))
                })
            })
        })
}

fn chunked_complete(body: &[u8]) -> bool {
    body == b"0\r\n\r\n" || body.ends_with(b"\r\n0\r\n\r\n")
}

fn decode_chunked(mut body: &[u8]) -> Option<Vec<u8>> {
    let mut decoded = Vec::new();
    loop {
        let line_end = body.windows(2).position(|window| window == b"\r\n")?;
        let size_text = std::str::from_utf8(&body[..line_end]).ok()?;
        let size = usize::from_str_radix(size_text.split(';').next()?.trim(), 16).ok()?;
        body = &body[line_end + 2..];
        if size == 0 {
            return Some(decoded);
        }
        let data_end = size.checked_add(2)?;
        if body.len() < data_end || &body[size..data_end] != b"\r\n" {
            return None;
        }
        decoded.extend_from_slice(&body[..size]);
        body = &body[data_end..];
    }
}

fn parse_http(buf: &[u8]) -> Option<RecordedRequest> {
    let header_end = find_header_end(buf)?;
    let head = std::str::from_utf8(&buf[..header_end]).ok()?;
    let mut lines = head.split("\r\n");
    let request_line = lines.next()?;
    let mut parts = request_line.split_whitespace();
    let method = parts.next()?.parse().ok()?;
    let path = parts.next()?;
    let mut headers = HeaderMap::new();
    for line in lines {
        if line.is_empty() {
            continue;
        }
        let (name, value) = line.split_once(':')?;
        if let (Ok(header_name), Ok(header_value)) = (
            HeaderName::from_bytes(name.trim().as_bytes()),
            HeaderValue::from_str(value.trim()),
        ) {
            headers.insert(header_name, header_value);
        }
    }
    let body = if headers
        .get("transfer-encoding")
        .and_then(|value| value.to_str().ok())
        .is_some_and(|value| {
            value
                .split(',')
                .any(|encoding| encoding.trim().eq_ignore_ascii_case("chunked"))
        }) {
        decode_chunked(&buf[header_end..])?
    } else {
        buf[header_end..].to_vec()
    };
    let url = Url::parse(&format!("http://localhost{path}")).ok()?;
    Some(RecordedRequest {
        method,
        url,
        headers,
        body,
    })
}
