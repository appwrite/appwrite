//! PHP `Utopia\Client` - decorator over an [`Adapter`].

use std::collections::HashMap;

use base64::engine::general_purpose::STANDARD as BASE64;
use base64::Engine as _;
use bytes::Bytes;
use http::header::HeaderName;
use http::{HeaderValue, Request, Response, Uri};
use utopia_pools::Recover;
use utopia_span::Span;

use crate::{Adapter, Error, StreamingClient, Tls};

const TRACEPARENT: &str = "traceparent";

/// Path-rootless relative reference (`users?active=1`) that [`http::Uri`] cannot store.
///
/// Attach with `request.extensions_mut().insert(RelativeUri(...))`. [`Client`]
/// joins it with the base URI the same way PHP PSR-7 joins a path-only target.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct RelativeUri(pub String);

#[derive(Clone, Debug)]
struct StoredHeader {
    name: String,
    values: Vec<String>,
}

/// Values for [`Client::with_headers`]: a single string or a list (PHP `string|array<int,string>`).
#[derive(Clone, Debug)]
pub struct HeaderValues(pub Vec<String>);

impl From<&str> for HeaderValues {
    fn from(value: &str) -> Self {
        Self(vec![value.to_owned()])
    }
}

impl From<String> for HeaderValues {
    fn from(value: String) -> Self {
        Self(vec![value])
    }
}

impl From<Vec<String>> for HeaderValues {
    fn from(value: Vec<String>) -> Self {
        Self(value)
    }
}

impl From<Vec<&str>> for HeaderValues {
    fn from(value: Vec<&str>) -> Self {
        Self(value.into_iter().map(str::to_owned).collect())
    }
}

/// PHP `Utopia\Client`.
#[derive(Clone, Debug)]
pub struct Client<A: Adapter> {
    adapter: A,
    headers: HashMap<String, StoredHeader>,
    base_uri: Option<Uri>,
    trace_propagation: bool,
}

impl<A: Adapter> Client<A> {
    /// PHP `new Client(Adapter $adapter)`.
    pub fn new(adapter: A) -> Self {
        Self {
            adapter,
            headers: HashMap::new(),
            base_uri: None,
            trace_propagation: false,
        }
    }

    pub fn with_timeout(&self, seconds: f64) -> Result<Self, Error> {
        let mut clone = self.clone();
        clone.adapter = self.adapter.with_timeout(seconds)?;
        Ok(clone)
    }

    pub fn with_connect_timeout(&self, seconds: f64) -> Result<Self, Error> {
        let mut clone = self.clone();
        clone.adapter = self.adapter.with_connect_timeout(seconds)?;
        Ok(clone)
    }

    pub fn with_ssl_verification(&self, enabled: bool) -> Self {
        let mut clone = self.clone();
        clone.adapter = self.adapter.with_ssl_verification(enabled);
        clone
    }

    pub fn with_custom_ca(&self, path: impl Into<String>) -> Self {
        let mut clone = self.clone();
        clone.adapter = self.adapter.with_custom_ca(path);
        clone
    }

    pub fn with_certificate(
        &self,
        cert_path: impl Into<String>,
        key_path: impl Into<String>,
        passphrase: Option<String>,
    ) -> Self {
        let mut clone = self.clone();
        clone.adapter = self
            .adapter
            .with_certificate(cert_path, key_path, passphrase);
        clone
    }

    pub fn with_min_tls_version(&self, version: Tls) -> Self {
        let mut clone = self.clone();
        clone.adapter = self.adapter.with_min_tls_version(version);
        clone
    }

    pub fn with_connection_reuse(&self, enabled: bool) -> Self {
        let mut clone = self.clone();
        clone.adapter = self.adapter.with_connection_reuse(enabled);
        clone
    }

    pub fn with_headers<I, K, V>(&self, headers: I) -> Self
    where
        I: IntoIterator<Item = (K, V)>,
        K: Into<String>,
        V: Into<HeaderValues>,
    {
        let mut clone = self.clone();
        for (name, values) in headers {
            let name = name.into();
            clone.headers.insert(
                name.to_ascii_lowercase(),
                StoredHeader {
                    name,
                    values: values.into().0,
                },
            );
        }
        clone
    }

    pub fn with_base_uri(&self, uri: impl AsRef<str>) -> Result<Self, Error> {
        let uri: Uri = uri
            .as_ref()
            .parse()
            .map_err(|_| Error::invalid_argument("Base URI must be absolute."))?;
        if uri.scheme().is_none() || uri.host().unwrap_or("").is_empty() {
            return Err(Error::invalid_argument("Base URI must be absolute."));
        }
        let mut clone = self.clone();
        clone.base_uri = Some(uri);
        Ok(clone)
    }

    pub fn with_basic_auth(&self, username: &str, password: &str) -> Self {
        let token = BASE64.encode(format!("{username}:{password}"));
        self.with_headers([("Authorization", format!("Basic {token}"))])
    }

    pub fn with_bearer_auth(&self, token: &str) -> Self {
        self.with_headers([("Authorization", format!("Bearer {token}"))])
    }

    pub fn with_trace_propagation(&self, enabled: bool) -> Self {
        let mut clone = self.clone();
        clone.trace_propagation = enabled;
        clone
    }

    fn prepare(&self, request: Request<Bytes>) -> Request<Bytes> {
        self.apply_trace(self.apply_headers(self.apply_base_uri(request)))
    }

    fn apply_base_uri(&self, request: Request<Bytes>) -> Request<Bytes> {
        let Some(base) = &self.base_uri else {
            return request;
        };
        if let Some(RelativeUri(raw)) = request.extensions().get::<RelativeUri>().cloned() {
            return join_with_base(base, &raw, None, request);
        }
        let uri = request.uri();
        if uri.scheme().is_some() {
            return request;
        }
        // `http::Uri` has no path-rootless form (`users`). Those parse as an
        // authority with an empty path; PHP PSR-7 stores them as path only.
        let path_is_origin = uri.path().starts_with('/') && uri.path() != "/";
        if uri.host().is_some() && path_is_origin {
            return request;
        }
        let relative_path = match uri.host() {
            Some(host) if uri.path().is_empty() || uri.path() == "/" => host.to_owned(),
            _ => uri.path().to_owned(),
        };
        let query = uri.query().map(str::to_owned);
        join_with_base(base, &relative_path, query.as_deref(), request)
    }

    fn apply_headers(&self, mut request: Request<Bytes>) -> Request<Bytes> {
        for header in self.headers.values() {
            if request.headers().contains_key(header.name.as_str()) {
                continue;
            }
            let Ok(name) = HeaderName::from_bytes(header.name.as_bytes()) else {
                continue;
            };
            for value in &header.values {
                if let Ok(value) = HeaderValue::from_str(value) {
                    request.headers_mut().append(name.clone(), value);
                }
            }
        }
        request
    }

    fn apply_trace(&self, mut request: Request<Bytes>) -> Request<Bytes> {
        if !self.trace_propagation {
            return request;
        }
        let Some(traceparent) = Span::traceparent() else {
            return request;
        };
        if request.headers().contains_key(TRACEPARENT) {
            return request;
        }
        if let Ok(value) = HeaderValue::from_str(&traceparent) {
            request.headers_mut().insert(TRACEPARENT, value);
        }
        request
    }
}

impl<A: Adapter> StreamingClient for Client<A> {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        self.adapter.send_request(self.prepare(request))
    }

    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        self.adapter.stream(self.prepare(request), sink)
    }
}

impl<A: Adapter> Adapter for Client<A> {
    fn with_timeout(&self, seconds: f64) -> Result<Self, Error> {
        Client::with_timeout(self, seconds)
    }

    fn with_connect_timeout(&self, seconds: f64) -> Result<Self, Error> {
        Client::with_connect_timeout(self, seconds)
    }

    fn with_ssl_verification(&self, enabled: bool) -> Self {
        Client::with_ssl_verification(self, enabled)
    }

    fn with_custom_ca(&self, path: impl Into<String>) -> Self {
        Client::with_custom_ca(self, path)
    }

    fn with_certificate(
        &self,
        cert_path: impl Into<String>,
        key_path: impl Into<String>,
        passphrase: Option<String>,
    ) -> Self {
        Client::with_certificate(self, cert_path, key_path, passphrase)
    }

    fn with_min_tls_version(&self, version: Tls) -> Self {
        Client::with_min_tls_version(self, version)
    }

    fn with_connection_reuse(&self, enabled: bool) -> Self {
        Client::with_connection_reuse(self, enabled)
    }
}

impl<A: Adapter + Recover> Recover for Client<A> {}

fn join_with_base(
    base: &Uri,
    relative: &str,
    query: Option<&str>,
    request: Request<Bytes>,
) -> Request<Bytes> {
    let (path_part, query_part) = match relative.split_once('?') {
        Some((path, q)) => (path, Some(q)),
        None => (relative, query),
    };
    let path = resolve_path(base.path(), path_part);
    let path_and_query = match query_part {
        Some(query) => format!("{path}?{query}"),
        None => path,
    };
    let mut parts = base.clone().into_parts();
    parts.path_and_query = path_and_query.parse().ok();
    let Ok(new_uri) = Uri::from_parts(parts) else {
        return request;
    };
    let (mut parts, body) = request.into_parts();
    parts.uri = new_uri.clone();
    if !parts.headers.contains_key("host") {
        if let Some(host) = new_uri.host() {
            let value = match new_uri.port_u16() {
                Some(port) => format!("{host}:{port}"),
                None => host.to_owned(),
            };
            if let Ok(value) = HeaderValue::from_str(&value) {
                parts.headers.insert("host", value);
            }
        }
    }
    Request::from_parts(parts, body)
}

fn resolve_path(base_path: &str, path: &str) -> String {
    if path.is_empty() {
        return if base_path.is_empty() {
            "/".to_owned()
        } else {
            base_path.to_owned()
        };
    }
    if path.starts_with('/') {
        return remove_dot_segments(path);
    }
    let mut base = base_path.to_owned();
    if base.is_empty() || !base.ends_with('/') {
        base.push('/');
    }
    remove_dot_segments(&format!("{base}{path}"))
}

fn remove_dot_segments(path: &str) -> String {
    let mut segments = Vec::new();
    for segment in path.split('/') {
        if segment.is_empty() || segment == "." {
            continue;
        }
        if segment == ".." {
            segments.pop();
            continue;
        }
        segments.push(segment);
    }
    format!("/{}", segments.join("/"))
}

#[cfg(test)]
mod tests {
    use super::resolve_path;

    #[test]
    fn resolve_path_matches_php() {
        assert_eq!(resolve_path("/v1", "users"), "/v1/users");
        assert_eq!(resolve_path("/v1", "/status"), "/status");
        assert_eq!(resolve_path("/v1/", "users"), "/v1/users");
        assert_eq!(resolve_path("", "users"), "/users");
        assert_eq!(resolve_path("/a/b", "../c"), "/a/c");
    }
}
