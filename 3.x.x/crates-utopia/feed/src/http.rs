use bytes::Bytes;
use http::{Request, Response, StatusCode};
use serde_json::Value;
use std::sync::{Arc, Mutex};
use utopia_client::{Adapter, Error as ClientError, RelativeUri, StreamingClient, Tls};

use crate::FeedError;

/// PHP test response helper (`FakeTransport::json` / `raw`).
#[derive(Debug, Clone)]
pub struct FeedHttpResponse {
    pub status: u16,
    pub body: String,
    pub cache_control: String,
    pub content_type: String,
}

impl FeedHttpResponse {
    #[must_use]
    pub fn json(body: Value, status: u16) -> Self {
        Self {
            status,
            body: body.to_string(),
            cache_control: String::new(),
            content_type: "application/json".into(),
        }
    }

    #[must_use]
    pub fn raw(body: impl Into<String>, status: u16) -> Self {
        Self {
            status,
            body: body.into(),
            cache_control: String::new(),
            content_type: "text/plain".into(),
        }
    }
}

/// One recorded HTTP exchange (PHP `Recorder` row).
#[derive(Debug, Clone)]
pub struct RecordedRequest {
    pub method: String,
    pub uri: String,
    pub headers: Vec<(String, String)>,
    pub timeout: Option<f64>,
    pub status: u16,
    pub cache_control: String,
    pub content_type: String,
}

/// PHP `FakeTransport` / `FakeClient` - a cloneable [`Adapter`] that answers from a script.
#[derive(Clone)]
pub struct RecordingTransport {
    inner: Arc<Mutex<Inner>>,
    timeout: Option<f64>,
    base_uri: String,
}

struct Inner {
    responses: Vec<Result<FeedHttpResponse, FeedError>>,
    calls: Vec<RecordedRequest>,
}

impl RecordingTransport {
    #[must_use]
    pub fn of(responses: Vec<Result<FeedHttpResponse, FeedError>>) -> Self {
        Self {
            inner: Arc::new(Mutex::new(Inner {
                responses,
                calls: Vec::new(),
            })),
            timeout: None,
            base_uri: String::new(),
        }
    }

    /// Prefix recorded URIs the way PHP `Client::withBaseUri` joins a relative path.
    #[must_use]
    pub fn with_base_uri(mut self, base: impl Into<String>) -> Self {
        self.base_uri = base.into();
        self
    }

    #[must_use]
    pub fn json(body: Value, status: u16) -> FeedHttpResponse {
        FeedHttpResponse::json(body, status)
    }

    #[must_use]
    pub fn raw(body: impl Into<String>, status: u16) -> FeedHttpResponse {
        FeedHttpResponse::raw(body, status)
    }

    #[must_use]
    pub fn offline(message: &str) -> FeedError {
        FeedError::transport(format!("Failed to read the feed: {message}"))
    }

    #[must_use]
    pub fn last(&self) -> Option<RecordedRequest> {
        self.inner.lock().expect("lock").calls.last().cloned()
    }

    #[must_use]
    pub fn last_uri(&self) -> Option<String> {
        self.last().map(|c| c.uri)
    }

    #[must_use]
    pub fn calls(&self) -> Vec<RecordedRequest> {
        self.inner.lock().expect("lock").calls.clone()
    }

    #[must_use]
    pub fn uris(&self) -> Vec<String> {
        self.calls().into_iter().map(|c| c.uri).collect()
    }

    #[must_use]
    pub fn cache_control(&self) -> Vec<String> {
        self.calls().into_iter().map(|c| c.cache_control).collect()
    }

    #[must_use]
    pub fn timeout(&self) -> Option<f64> {
        self.timeout
    }
}

impl RecordedRequest {
    #[must_use]
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers
            .iter()
            .find(|(k, _)| k.eq_ignore_ascii_case(name))
            .map(|(_, v)| v.as_str())
    }
}

fn header_line(request: &Request<Bytes>, name: &str) -> Option<String> {
    let values: Vec<&str> = request
        .headers()
        .get_all(name)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .collect();
    if values.is_empty() {
        None
    } else {
        Some(values.join(", "))
    }
}

fn recorded_uri(base_uri: &str, request: &Request<Bytes>) -> String {
    let uri = request.uri();
    let relative = if uri.scheme().is_some() {
        uri.to_string()
    } else if let Some(RelativeUri(raw)) = request.extensions().get::<RelativeUri>() {
        raw.clone()
    } else {
        uri.to_string()
    };
    if base_uri.is_empty() || relative.contains("://") {
        return relative;
    }
    if base_uri.ends_with('/') {
        format!("{base_uri}{relative}")
    } else {
        format!("{base_uri}/{relative}")
    }
}

impl StreamingClient for RecordingTransport {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, ClientError> {
        let uri = recorded_uri(&self.base_uri, &request);
        let method = request.method().as_str().to_owned();
        let mut headers = Vec::new();
        for name in ["accept", "x-appwrite-jwt", "content-type"] {
            if let Some(value) = header_line(&request, name) {
                headers.push((name.to_owned(), value));
            }
        }
        for (name, value) in request.headers() {
            let n = name.as_str();
            if n == "accept" || n == "x-appwrite-jwt" || n == "content-type" {
                continue;
            }
            if let Ok(v) = value.to_str() {
                headers.push((n.to_owned(), v.to_owned()));
            }
        }

        let scripted = {
            let mut inner = self.inner.lock().expect("lock");
            if inner.responses.len() > 1 {
                inner.responses.remove(0)
            } else {
                inner
                    .responses
                    .first()
                    .cloned()
                    .unwrap_or_else(|| Ok(FeedHttpResponse::json(serde_json::json!([]), 200)))
            }
        };

        let response = match scripted {
            Err(e) => {
                self.inner
                    .lock()
                    .expect("lock")
                    .calls
                    .push(RecordedRequest {
                        method,
                        uri,
                        headers,
                        timeout: self.timeout,
                        status: 0,
                        cache_control: String::new(),
                        content_type: String::new(),
                    });
                let dummy = Request::builder()
                    .uri("http://feed.invalid/")
                    .body(Bytes::new())
                    .expect("dummy request");
                return Err(ClientError::network(dummy, e.to_string(), 0));
            }
            Ok(body) => body,
        };

        self.inner
            .lock()
            .expect("lock")
            .calls
            .push(RecordedRequest {
                method,
                uri,
                headers,
                timeout: self.timeout,
                status: response.status,
                cache_control: response.cache_control.clone(),
                content_type: response.content_type.clone(),
            });

        let status = StatusCode::from_u16(response.status).unwrap_or(StatusCode::OK);
        let mut builder = Response::builder().status(status);
        if !response.content_type.is_empty() {
            builder = builder.header("content-type", response.content_type);
        }
        if !response.cache_control.is_empty() {
            builder = builder.header("cache-control", response.cache_control);
        }
        Ok(builder.body(Bytes::from(response.body)).expect("response"))
    }

    fn stream(
        &self,
        _request: Request<Bytes>,
        _sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, ClientError> {
        Err(ClientError::invalid_argument(
            "A feed is never read as a stream",
        ))
    }
}

impl Adapter for RecordingTransport {
    fn with_timeout(&self, seconds: f64) -> Result<Self, ClientError> {
        if !seconds.is_finite() || seconds < 0.0 {
            return Err(ClientError::value());
        }
        let mut clone = self.clone();
        clone.timeout = Some(seconds);
        Ok(clone)
    }

    fn with_connect_timeout(&self, seconds: f64) -> Result<Self, ClientError> {
        if !seconds.is_finite() || seconds < 0.0 {
            return Err(ClientError::value());
        }
        Ok(self.clone())
    }

    fn with_ssl_verification(&self, _enabled: bool) -> Self {
        self.clone()
    }

    fn with_custom_ca(&self, _path: impl Into<String>) -> Self {
        self.clone()
    }

    fn with_certificate(
        &self,
        _cert_path: impl Into<String>,
        _key_path: impl Into<String>,
        _passphrase: Option<String>,
    ) -> Self {
        self.clone()
    }

    fn with_min_tls_version(&self, _version: Tls) -> Self {
        self.clone()
    }

    fn with_connection_reuse(&self, _enabled: bool) -> Self {
        self.clone()
    }
}

impl utopia_pools::Recover for RecordingTransport {}

impl std::fmt::Debug for RecordingTransport {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RecordingTransport")
            .field("timeout", &self.timeout)
            .finish_non_exhaustive()
    }
}
