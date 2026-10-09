use bytes::Bytes;
use http::{HeaderName, HeaderValue, StatusCode};

use crate::compression::{self, Compression, Encoding};

/// An outgoing response.
///
/// Headers keep Utopia semantics: [`Response::add_header`] appends another
/// line, [`Response::set_header`] replaces all lines with the same name.
#[derive(Debug, Clone)]
pub struct Response {
    pub status: StatusCode,
    headers: Vec<(HeaderName, HeaderValue)>,
    pub body: Bytes,
    content_type: Option<&'static str>,
    /// Suppress the body (HEAD requests).
    pub head: bool,
}

impl Default for Response {
    fn default() -> Self {
        Self::new(StatusCode::OK)
    }
}

impl Response {
    pub const JSON: &'static str = "application/json; charset=UTF-8";
    pub const TEXT: &'static str = "text/plain; charset=UTF-8";

    pub fn new(status: StatusCode) -> Self {
        Self { status, headers: Vec::with_capacity(16), body: Bytes::new(), content_type: None, head: false }
    }

    /// A JSON response from already-encoded bytes.
    pub fn json(status: StatusCode, body: impl Into<Bytes>) -> Self {
        let mut r = Self::new(status);
        r.content_type = Some(Self::JSON);
        r.body = body.into();
        r
    }

    /// An empty `204 No Content` response.
    pub fn no_content() -> Self {
        Self::new(StatusCode::NO_CONTENT)
    }

    pub fn text(status: StatusCode, body: impl Into<Bytes>) -> Self {
        let mut r = Self::new(status);
        r.content_type = Some(Self::TEXT);
        r.body = body.into();
        r
    }

    pub fn content_type(&self) -> Option<&'static str> {
        self.content_type
    }

    pub fn set_content_type(&mut self, ct: &'static str) {
        self.content_type = Some(ct);
    }

    pub fn headers(&self) -> &[(HeaderName, HeaderValue)] {
        &self.headers
    }

    /// Appends a header line.
    pub fn add_header(&mut self, name: HeaderName, value: HeaderValue) -> &mut Self {
        self.headers.push((name, value));
        self
    }

    /// Appends a header line from strings (invalid values are dropped).
    pub fn add(&mut self, name: &'static str, value: &str) -> &mut Self {
        if let Ok(v) = HeaderValue::from_str(value) {
            self.headers.push((HeaderName::from_static(name), v));
        }
        self
    }

    /// Replaces all lines of a header.
    pub fn set_header(&mut self, name: HeaderName, value: HeaderValue) -> &mut Self {
        self.headers.retain(|(n, _)| *n != name);
        self.headers.push((name, value));
        self
    }

    pub fn set(&mut self, name: &'static str, value: &str) -> &mut Self {
        if let Ok(v) = HeaderValue::from_str(value) {
            self.set_header(HeaderName::from_static(name), v);
        }
        self
    }

    pub fn remove_header(&mut self, name: &str) -> &mut Self {
        self.headers.retain(|(n, _)| n.as_str() != name);
        self
    }

    pub fn has_header(&self, name: &str) -> bool {
        self.headers.iter().any(|(n, _)| n.as_str() == name)
    }

    /// Compresses the body when the client accepts it and the body is large enough.
    pub fn compress(&mut self, accept_encoding: &str, settings: Compression) {
        if !settings.enabled || accept_encoding.is_empty() || self.body.len() <= settings.min_size {
            return;
        }
        if self.has_header("content-encoding") {
            return;
        }
        let Some(ct) = self.content_type else { return };
        if !compression::compressible(ct) {
            return;
        }
        let Some(encoding) = compression::negotiate(accept_encoding) else { return };
        if encoding == Encoding::Identity {
            return;
        }
        if let Some(compressed) = compression::compress(encoding, &self.body) {
            self.body = Bytes::from(compressed);
            self.add("content-encoding", encoding.name());
            self.add("x-utopia-compression", "true");
            self.add("vary", "Accept-Encoding");
        }
    }

    /// Size used by usage metrics: header lines (`name: value`) plus body bytes.
    pub fn size(&self) -> usize {
        if self.head {
            return 0;
        }
        let mut names: Vec<&HeaderName> = Vec::new();
        let mut size = 0usize;
        for (name, value) in &self.headers {
            size += name.as_str().len() + 2 + value.as_bytes().len();
            if names.contains(&name) {
                size += 2;
            } else {
                names.push(name);
            }
        }
        if let Some(ct) = self.content_type {
            size += "content-type: ".len() + ct.len();
            names.push(&http::header::CONTENT_TYPE);
        }
        if !names.is_empty() {
            size += (names.len() - 1) * 2;
        }
        size + self.body.len()
    }

    pub(crate) fn into_http(self) -> http::Response<http_body_util::Full<Bytes>> {
        let mut builder = http::Response::builder().status(self.status);
        if let Some(headers) = builder.headers_mut() {
            if let Some(ct) = self.content_type
                && self.status != StatusCode::NO_CONTENT
            {
                headers.insert(http::header::CONTENT_TYPE, HeaderValue::from_static(ct));
            }
            for (name, value) in self.headers {
                headers.append(name, value);
            }
        }
        let body = if self.head || self.status == StatusCode::NO_CONTENT { Bytes::new() } else { self.body };
        builder.body(http_body_util::Full::new(body)).unwrap_or_else(|_| {
            let mut r = http::Response::new(http_body_util::Full::new(Bytes::new()));
            *r.status_mut() = StatusCode::INTERNAL_SERVER_ERROR;
            r
        })
    }
}
