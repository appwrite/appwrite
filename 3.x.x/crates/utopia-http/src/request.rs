use crate::headers::HeaderMap;
use serde_json::Value;
use std::collections::HashMap;

#[derive(Debug, Clone)]
pub struct Request {
    method: String,
    uri: String,
    headers: HeaderMap,
    cookies: HashMap<String, String>,
    query: HashMap<String, Value>,
    payload: HashMap<String, Value>,
    raw_payload: Vec<u8>,
    server: HashMap<String, String>,
    trusted_ip_headers: Vec<String>,
    query_parsed: bool,
}

impl Default for Request {
    fn default() -> Self {
        Self::new("GET", "/")
    }
}

impl Request {
    pub fn new(method: impl Into<String>, uri: impl Into<String>) -> Self {
        Self {
            method: method.into().to_ascii_uppercase(),
            uri: uri.into(),
            headers: HeaderMap::new(),
            cookies: HashMap::new(),
            query: HashMap::new(),
            payload: HashMap::new(),
            raw_payload: Vec::new(),
            server: HashMap::new(),
            trusted_ip_headers: Vec::new(),
            query_parsed: false,
        }
    }

    pub fn method(&self) -> &str {
        &self.method
    }

    pub fn set_method(&mut self, method: impl Into<String>) -> &mut Self {
        self.method = method.into().to_ascii_uppercase();
        self
    }

    pub fn uri(&self) -> &str {
        &self.uri
    }

    pub fn set_uri(&mut self, uri: impl Into<String>) -> &mut Self {
        self.uri = uri.into();
        self
    }

    pub fn path(&self) -> &str {
        self.uri.split('?').next().unwrap_or("/")
    }

    pub fn headers(&self) -> &HeaderMap {
        &self.headers
    }

    pub fn headers_mut(&mut self) -> &mut HeaderMap {
        &mut self.headers
    }

    pub fn header_line(&self, key: &str) -> String {
        self.headers.get_line(key, "")
    }

    pub fn set_header(&mut self, key: &str, value: impl Into<String>) -> &mut Self {
        self.headers.set(key, value);
        self
    }

    pub fn cookie(&self, key: &str, default: &str) -> String {
        self.cookies
            .get(key)
            .cloned()
            .unwrap_or_else(|| default.to_string())
    }

    pub fn set_cookie_params(&mut self, cookies: HashMap<String, String>) -> &mut Self {
        self.cookies = cookies;
        self
    }

    pub fn set_query(&mut self, query: HashMap<String, Value>) -> &mut Self {
        self.query = query;
        self.query_parsed = true;
        self
    }

    pub fn set_payload(&mut self, payload: HashMap<String, Value>) -> &mut Self {
        self.payload = payload;
        self
    }

    pub fn set_raw_payload(&mut self, raw: Vec<u8>) -> &mut Self {
        self.raw_payload = raw;
        self
    }

    pub fn raw_payload(&self) -> &[u8] {
        &self.raw_payload
    }

    pub fn params(&self) -> HashMap<String, Value> {
        let mut out = self.query.clone();
        out.extend(self.payload.clone());
        out
    }

    /// Payload wins over query (same merge order as [`Self::params`]) without allocating.
    pub fn param_ref(&self, key: &str) -> Option<&Value> {
        self.payload.get(key).or_else(|| self.query.get(key))
    }

    pub fn param(&self, key: &str, default: Value) -> Value {
        self.param_ref(key).cloned().unwrap_or(default)
    }

    pub fn set_server(&mut self, key: impl Into<String>, value: impl Into<String>) -> &mut Self {
        self.server.insert(key.into(), value.into());
        self
    }

    pub fn server(&self, key: &str, default: &str) -> String {
        self.server
            .get(key)
            .cloned()
            .unwrap_or_else(|| default.to_string())
    }

    pub fn protocol(&self) -> String {
        self.header_line("x-forwarded-proto")
            .split(',')
            .next()
            .map(str::trim)
            .filter(|s| !s.is_empty())
            .unwrap_or("http")
            .to_string()
    }

    pub fn ip(&self) -> String {
        for h in &self.trusted_ip_headers {
            let v = self.header_line(h);
            if !v.is_empty() {
                return v.split(',').next().unwrap_or("").trim().to_string();
            }
        }
        self.server("REMOTE_ADDR", "127.0.0.1")
    }

    pub fn set_trusted_ip_headers(&mut self, headers: Vec<String>) -> &mut Self {
        self.trusted_ip_headers = headers
            .into_iter()
            .map(|h| h.to_ascii_lowercase())
            .collect();
        self
    }

    pub fn size(&self) -> usize {
        let mut n = self.raw_payload.len();
        for (k, values) in self.headers.iter() {
            for v in values {
                n += k.len() + v.len() + 2;
            }
        }
        n
    }

    pub fn parse_query_from_uri(&mut self) {
        if self.query_parsed {
            return;
        }
        self.query_parsed = true;
        if let Some(q) = self.uri.split_once('?').map(|(_, q)| q) {
            self.query = parse_urlencoded_map(q);
        }
    }

    /// Populate [`Self::payload`] from [`Self::raw_payload`] using `Content-Type`.
    ///
    /// Mirrors Utopia PHP's request body → params merge for JSON objects and
    /// `application/x-www-form-urlencoded` bodies. Nested JSON values are kept
    /// as [`Value`]s (arrays/objects), not stringified.
    pub fn parse_payload_from_raw(&mut self) {
        if self.raw_payload.is_empty() || !self.payload.is_empty() {
            return;
        }
        let content_type = self.header_line("content-type").to_ascii_lowercase();
        let ctype = content_type
            .split(';')
            .next()
            .unwrap_or("")
            .trim();
        if ctype == "application/json" || ctype.ends_with("+json") {
            if let Ok(Value::Object(map)) = serde_json::from_slice::<Value>(&self.raw_payload) {
                self.payload = map.into_iter().collect();
            }
            return;
        }
        if ctype == "application/x-www-form-urlencoded"
            || ctype.is_empty()
                && self
                    .raw_payload
                    .iter()
                    .all(|b| b.is_ascii() && !b.is_ascii_control() || *b == b'\r' || *b == b'\n')
        {
            if let Ok(s) = std::str::from_utf8(&self.raw_payload) {
                // Only treat as urlencoded when it looks like key=value pairs.
                if ctype == "application/x-www-form-urlencoded" || s.contains('=') {
                    self.payload = parse_urlencoded_map(s);
                }
            }
        }
    }
}

fn parse_urlencoded_map(input: &str) -> HashMap<String, Value> {
    let mut map = HashMap::new();
    for pair in input.split('&') {
        if pair.is_empty() {
            continue;
        }
        let mut it = pair.splitn(2, '=');
        let k = urlencoding_decode(it.next().unwrap_or(""));
        let v = urlencoding_decode(it.next().unwrap_or(""));
        map.insert(k, Value::String(v));
    }
    map
}

fn urlencoding_decode(s: &str) -> String {
    let mut out = String::new();
    let bytes = s.as_bytes();
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'+' => {
                out.push(' ');
                i += 1;
            }
            b'%' if i + 2 < bytes.len() => {
                if let Ok(v) = u8::from_str_radix(
                    std::str::from_utf8(&bytes[i + 1..i + 3]).unwrap_or("00"),
                    16,
                ) {
                    out.push(v as char);
                    i += 3;
                } else {
                    out.push('%');
                    i += 1;
                }
            }
            c => {
                out.push(c as char);
                i += 1;
            }
        }
    }
    out
}

#[cfg(test)]
mod payload_parse_tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn parse_json_object_into_payload() {
        let mut req = Request::new("POST", "/v1/users");
        req.set_header("content-type", "application/json");
        req.set_raw_payload(br#"{"userId":"u1","email":"a@b.c"}"#.to_vec());
        req.parse_payload_from_raw();
        assert_eq!(req.param_ref("userId"), Some(&json!("u1")));
        assert_eq!(req.param_ref("email"), Some(&json!("a@b.c")));
    }
}
