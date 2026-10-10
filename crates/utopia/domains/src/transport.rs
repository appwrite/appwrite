//! [`Transport`]: how registrar adapters reach their APIs, and
//! [`HttpTransport`], the default one over HTTPS.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use hyper_rustls::HttpsConnector;
use hyper_util::client::legacy::Client;
use hyper_util::client::legacy::connect::HttpConnector;
use hyper_util::rt::TokioExecutor;

use crate::cache::BoxFuture;

/// An HTTP request an adapter sends.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Request {
    /// `GET`, `POST`, `PATCH`, ...
    pub method: String,
    /// The absolute URL.
    pub url: String,
    /// Header names as the adapter writes them, in order.
    pub headers: Vec<(String, String)>,
    /// The body.
    pub body: Vec<u8>,
    /// How long the default transport waits for a connection. Transports
    /// given to an adapter apply their own settings.
    pub connect_timeout: Duration,
    /// How long the default transport waits for the whole exchange.
    pub timeout: Duration,
}

impl Request {
    /// The value of a header (names compare case-insensitively).
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers.iter().find(|(n, _)| n.eq_ignore_ascii_case(name)).map(|(_, v)| v.as_str())
    }
}

/// Percent-encodes what a URI component may not hold (a `%` not starting
/// an escape included), as `rawurlencode()` does.
fn encode_component(value: &[u8], extra: &[u8]) -> String {
    let mut out = String::with_capacity(value.len());
    for (i, &c) in value.iter().enumerate() {
        let allowed = c.is_ascii_alphanumeric()
            || b"_-.~!$&'()*+,;=:@/".contains(&c)
            || extra.contains(&c)
            || (c == b'%' && value.get(i + 1..i + 3).is_some_and(|h| h.iter().all(u8::is_ascii_hexdigit)));
        if allowed {
            out.push(c as char);
        } else {
            out.push_str(&format!("%{c:02X}"));
        }
    }
    out
}

/// A request URL as HTTP messages hold it: scheme and host lower-cased,
/// the default port dropped, path, query and fragment percent-encoded where
/// they must be. `None` for a URL that does not parse.
pub fn normalize_url(url: &str) -> Option<String> {
    let parts = php_std::url::parse_url(url.as_bytes())?;
    let text = |v: Option<std::borrow::Cow<'_, [u8]>>| v.map(|v| String::from_utf8_lossy(&v).into_owned());
    let scheme = text(parts.scheme()).map(|s| s.to_ascii_lowercase()).unwrap_or_default();
    let host = text(parts.host()).map(|h| h.to_ascii_lowercase()).unwrap_or_default();
    let mut out = String::new();
    if !scheme.is_empty() {
        out.push_str(&scheme);
        out.push(':');
    }
    if !host.is_empty() {
        out.push_str("//");
        if let Some(user) = text(parts.user()) {
            out.push_str(&user);
            if let Some(pass) = text(parts.pass()) {
                out.push(':');
                out.push_str(&pass);
            }
            out.push('@');
        }
        out.push_str(&host);
        let default = matches!((scheme.as_str(), parts.port()), ("http", Some(80)) | ("https", Some(443)));
        if let Some(port) = parts.port().filter(|_| !default) {
            out.push_str(&format!(":{port}"));
        }
    }
    let mut path = parts.path().map(|p| encode_component(&p, b"")).unwrap_or_default();
    if !host.is_empty() {
        if !path.is_empty() && !path.starts_with('/') {
            path.insert(0, '/');
        }
    } else if path.starts_with("//") {
        path = format!("/{}", path.trim_start_matches('/'));
    }
    out.push_str(&path);
    if let Some(query) = parts.query().map(|q| encode_component(&q, b"?")).filter(|q| !q.is_empty()) {
        out.push('?');
        out.push_str(&query);
    }
    if let Some(fragment) = parts.fragment().map(|f| encode_component(&f, b"?")).filter(|f| !f.is_empty()) {
        out.push('#');
        out.push_str(&fragment);
    }
    Some(out)
}

/// An HTTP response.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Response {
    /// The status code.
    pub status: u16,
    /// Header names and values, in order.
    pub headers: Vec<(String, String)>,
    /// The body.
    pub body: Vec<u8>,
}

impl Response {
    /// The last value of a header (names compare case-insensitively).
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers.iter().rev().find(|(n, _)| n.eq_ignore_ascii_case(name)).map(|(_, v)| v.as_str())
    }
}

/// A request could not be sent or no response came back (PHP's
/// `Psr\Http\Client\ClientExceptionInterface`).
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[error("{0}")]
pub struct TransportError(pub String);

/// Sends requests for an adapter. Give an adapter your own transport to
/// route, record or replay its traffic.
pub trait Transport: Send + Sync {
    /// Sends a request and returns the response.
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, TransportError>>;
}

impl<T: Transport + ?Sized> Transport for Arc<T> {
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, TransportError>> {
        (**self).send(request)
    }
}

type HyperClient = Client<HttpsConnector<HttpConnector>, Full<Bytes>>;

/// The default transport: HTTP/1.1 over TLS with the WebPKI roots, the
/// request's timeouts, certificates verified, redirects not followed
/// (`follow_redirects` turns them on).
#[derive(Default)]
pub struct HttpTransport {
    clients: Mutex<HashMap<Duration, HyperClient>>,
    follow_redirects: bool,
}

impl std::fmt::Debug for HttpTransport {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("HttpTransport").field("follow_redirects", &self.follow_redirects).finish()
    }
}

/// Redirects followed at most, as curl's default.
const MAX_REDIRECTS: usize = 20;

impl HttpTransport {
    /// A transport that does not follow redirects.
    pub fn new() -> Self {
        Self::default()
    }

    /// A transport that follows redirects.
    pub fn follow_redirects() -> Self {
        Self { follow_redirects: true, ..Self::default() }
    }

    fn client(&self, connect_timeout: Duration) -> Result<HyperClient, TransportError> {
        let mut clients = self.clients.lock().unwrap_or_else(|e| e.into_inner());
        if let Some(client) = clients.get(&connect_timeout) {
            return Ok(client.clone());
        }
        let mut http = HttpConnector::new();
        http.set_connect_timeout(Some(connect_timeout));
        http.enforce_http(false);
        let https = hyper_rustls::HttpsConnectorBuilder::new()
            .with_provider_and_webpki_roots(rustls::crypto::ring::default_provider())
            .map_err(|e| TransportError(format!("TLS setup failed: {e}")))?
            .https_or_http()
            .enable_http1()
            .wrap_connector(http);
        let client = Client::builder(TokioExecutor::new()).build(https);
        clients.insert(connect_timeout, client.clone());
        Ok(client)
    }

    async fn exchange(&self, request: Request) -> Result<Response, TransportError> {
        let client = self.client(request.connect_timeout)?;
        let mut url = request.url.clone();
        let mut method = request.method.clone();
        let mut body = request.body.clone();
        for _ in 0..=MAX_REDIRECTS {
            let mut builder = hyper::Request::builder().method(method.as_str()).uri(url.as_str());
            for (name, value) in &request.headers {
                builder = builder.header(name.as_str(), value.as_str());
            }
            let req = builder.body(Full::new(Bytes::from(body.clone()))).map_err(|e| TransportError(e.to_string()))?;
            let res = client.request(req).await.map_err(|e| TransportError(e.to_string()))?;
            let status = res.status().as_u16();
            let headers: Vec<(String, String)> = res
                .headers()
                .iter()
                .map(|(n, v)| (n.as_str().to_owned(), String::from_utf8_lossy(v.as_bytes()).into_owned()))
                .collect();
            let bytes = res.into_body().collect().await.map_err(|e| TransportError(e.to_string()))?.to_bytes();
            let response = Response { status, headers, body: bytes.to_vec() };
            let location = response.header("location").map(str::to_owned);
            match location {
                Some(location) if self.follow_redirects && (300..400).contains(&status) => {
                    url = resolve(&url, &location);
                    if matches!(status, 301..=303) && method != "GET" && method != "HEAD" {
                        method = "GET".into();
                        body.clear();
                    }
                }
                _ => return Ok(response),
            }
        }
        Err(TransportError(format!("Maximum ({MAX_REDIRECTS}) redirects followed")))
    }
}

/// A redirect target relative to the URL it came from.
fn resolve(base: &str, location: &str) -> String {
    if location.contains("://") {
        return location.to_owned();
    }
    let origin_end = base.find("://").map_or(0, |p| p + 3);
    let host_end = base[origin_end..].find('/').map_or(base.len(), |p| origin_end + p);
    if location.starts_with('/') {
        format!("{}{location}", &base[..host_end])
    } else {
        let dir_end = base.rfind('/').filter(|&p| p >= host_end).map_or(host_end, |p| p);
        format!("{}/{location}", &base[..dir_end])
    }
}

impl Transport for HttpTransport {
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, TransportError>> {
        Box::pin(async move {
            let timeout = request.timeout;
            match tokio::time::timeout(timeout, self.exchange(request)).await {
                Ok(result) => result,
                Err(_) => {
                    Err(TransportError(format!("Operation timed out after {} milliseconds", timeout.as_millis())))
                }
            }
        })
    }
}

#[cfg(test)]
mod tests {
    use super::resolve;

    #[test]
    fn redirects() {
        assert_eq!(resolve("https://a.com/x/y", "/z"), "https://a.com/z");
        assert_eq!(resolve("https://a.com/x/y", "z"), "https://a.com/x/z");
        assert_eq!(resolve("https://a.com", "z"), "https://a.com/z");
        assert_eq!(resolve("https://a.com/x", "http://b.com/"), "http://b.com/");
    }
}
