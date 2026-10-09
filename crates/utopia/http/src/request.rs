use std::net::IpAddr;
use std::sync::OnceLock;

use bytes::Bytes;
use http::{HeaderMap, Method, Version};
use serde_json::{Map, Value};

use crate::params::{Params, parse_query};

/// An incoming HTTP request with lazily decoded parameters.
#[derive(Debug)]
pub struct Request {
    pub method: Method,
    pub path: String,
    pub query: Option<String>,
    pub version: Version,
    pub headers: HeaderMap,
    pub body: Bytes,
    pub remote_addr: IpAddr,
    query_params: OnceLock<Params>,
    body_params: OnceLock<Params>,
}

impl Request {
    pub fn new(
        method: Method,
        path: String,
        query: Option<String>,
        version: Version,
        headers: HeaderMap,
        body: Bytes,
        remote_addr: IpAddr,
    ) -> Self {
        Self {
            method,
            path,
            query,
            version,
            headers,
            body,
            remote_addr,
            query_params: OnceLock::new(),
            body_params: OnceLock::new(),
        }
    }

    /// First value of a header (names are case-insensitive).
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers.get(name).and_then(|v| v.to_str().ok())
    }

    /// All values of a header joined with `", "` (PHP `getHeaderLine`), or `""`.
    pub fn header_line(&self, name: &str) -> String {
        let mut values = self.headers.get_all(name).iter().filter_map(|v| v.to_str().ok());
        let Some(first) = values.next() else {
            return String::new();
        };
        let mut out = first.to_owned();
        for v in values {
            out.push_str(", ");
            out.push_str(v);
        }
        out
    }

    /// Header value or `""`.
    pub fn header_or_empty(&self, name: &str) -> &str {
        self.header(name).unwrap_or("")
    }

    /// Query string parameters (always from the URL, whatever the method).
    pub fn query_params(&self) -> &Params {
        self.query_params.get_or_init(|| self.query.as_deref().map(parse_query).unwrap_or_default())
    }

    /// Request parameters with Utopia semantics: the body for
    /// POST/PUT/PATCH/DELETE, the query string for every other method.
    pub fn params(&self) -> &Params {
        if self.has_body_params() { self.body_params.get_or_init(|| self.decode_body()) } else { self.query_params() }
    }

    /// A single request parameter.
    pub fn param(&self, key: &str) -> Option<&Value> {
        self.params().get(key)
    }

    /// A query string value (string form).
    pub fn query_value(&self, key: &str) -> Option<&str> {
        self.query_params().get(key).and_then(Value::as_str)
    }

    fn has_body_params(&self) -> bool {
        matches!(self.method, Method::POST | Method::PUT | Method::PATCH | Method::DELETE)
    }

    fn content_type(&self) -> &str {
        let ct = self.header_or_empty("content-type");
        ct.split(';').next().unwrap_or("").trim()
    }

    fn decode_body(&self) -> Params {
        if self.body.is_empty() {
            return Map::new();
        }
        match self.content_type() {
            "application/json" => match serde_json::from_slice::<Value>(&self.body) {
                Ok(Value::Object(map)) => map,
                _ => Map::new(),
            },
            "application/x-www-form-urlencoded" => match std::str::from_utf8(&self.body) {
                Ok(s) => parse_query(s),
                Err(_) => Map::new(),
            },
            // Swoole only decodes urlencoded and multipart bodies; JSON is decoded
            // by Utopia. Anything else yields no parameters.
            _ => Map::new(),
        }
    }

    /// Cookie value by exact name.
    pub fn cookie(&self, name: &str) -> Option<String> {
        for header in self.headers.get_all(http::header::COOKIE) {
            let Ok(raw) = header.to_str() else { continue };
            for pair in raw.split(';') {
                let pair = pair.trim();
                if let Some((k, v)) = pair.split_once('=')
                    && k.trim() == name
                {
                    let v = v.trim();
                    // PHP decodes cookie values with urldecode.
                    let decoded: String = form_urlencoded::parse(format!("v={v}").as_bytes())
                        .next()
                        .map(|(_, v)| v.into_owned())
                        .unwrap_or_else(|| v.to_owned());
                    return Some(decoded);
                }
            }
        }
        None
    }

    /// Size used by usage metrics: `"name: v1, v2"` lines joined by `\n`, plus body.
    pub fn size(&self) -> usize {
        let mut total = 0usize;
        let mut first = true;
        for name in self.headers.keys() {
            if !first {
                total += 1;
            }
            first = false;
            total += name.as_str().len() + 2;
            let mut first_value = true;
            for v in self.headers.get_all(name) {
                if !first_value {
                    total += 2;
                }
                first_value = false;
                total += v.as_bytes().len();
            }
        }
        total
    }
}
